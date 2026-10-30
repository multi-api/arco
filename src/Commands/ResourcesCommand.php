<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Config\ConfigLoader;
use LogicException;
use Multiapi\Arco\Resources\Annotator;
use Multiapi\Arco\Resources\Inventory;
use Multiapi\Arco\Resources\Picker;
use Multiapi\Arco\Resources\PickerUi;
use Multiapi\Arco\Resources\PromptsPickerUi;
use Multiapi\Arco\Resources\PromptsUnavailable;
use Multiapi\Arco\Resources\ResourceItem;
use Multiapi\Arco\Resources\ResourceType;
use Multiapi\Arco\Resources\SelectionPlan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Lists the existing AWS resources an environment could use (subnets, security groups, gateway endpoints, buckets,
 * queues, tables, certificates, hosted zones), each with the command that adds it to arco.php. Read-only: it makes
 * List*, Describe* and Get* calls. `--json` is the document Arco Cloud reads (spec §8). In a terminal it is the
 * picker (spec §7): what the person ticks is written to arco.php by the editing commands, run in-process, the only
 * writes it makes.
 */
#[AsCommand(name: 'resources', description: 'List existing AWS resources and the commands that add them to arco.php')]
final class ResourcesCommand extends ArcoCommand
{
    use ResolvesTarget;

    /**
     * @param (Closure(?string, string): AwsClients)|null $awsFactory
     * @param (Closure(): int)|null $clock the current time, for the certificates' expiry (tests)
     * @param PickerUi|null $ui the picker's questions (tests); laravel/prompts by default
     */
    public function __construct(?Closure $awsFactory = null, private readonly ?Closure $clock = null, private readonly ?PickerUi $ui = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'AWS region to list (default: the environment\'s)')
            ->addOption('dns-profile', null, InputOption::VALUE_REQUIRED, 'AWS profile whose Route 53 zones are listed too (default: the environment\'s dns() profile)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these types: ' . implode(', ', array_map(fn (ResourceType $t) => $t->value, ResourceType::cases())))
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also show the resources hidden by default (Arco\'s own, other regions, the default VPC)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON')
            ->addOption(ConfigLayer::OPTION, null, InputOption::VALUE_REQUIRED, ConfigLayer::DESCRIPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : new NullOutput();
        $io = new SymfonyStyle($input, $output);
        $errorIo = new SymfonyStyle($input, $stderr);
        if ($json) {
            // No profile prompt or "profile saved" text in the JSON stream: it needs a profile that resolves.
            $input->setInteractive(false);
        }
        try {
            $types = $this->types($input);
            // With --json, config warnings are diagnostics: stderr, never the document.
            $config = $json
                ? $this->loadTarget($input, $errorIo, fn (string $key, array $params) => $errorIo->warning($this->t($key, $params)))
                : $this->loadTarget($input, $io);
            $environmentRegion = (string) $config->region;
            $region = $input->getOption('region');
            if (is_string($region) && $region !== '') {
                $config = $config->withRegion($region);
            }
            $aws = $this->awsClients($config, $input, $io);
            $located = $config->account === null ? $config->withAccount($aws->accountId()) : $config;
            $dns = in_array(ResourceType::Zone, $types, true) ? $this->dnsClients($located, $input) : null;
            if ($dns !== null && $this->dnsCredentialProfile === $this->credentialProfile) {
                $dns = null; // the same account: its zones are the deploy profile's
            }
            $picker = null;
            // Symfony leaves the input interactive on a piped STDIN, where laravel/prompts would silently take defaults:
            // without a terminal (and no injected UI) the run falls through to the plain listing.
            $terminal = $this->ui !== null || (defined('STDIN') && stream_isatty(STDIN));
            if (! $json && $input->isInteractive() && $terminal) {
                // Spec §7 step 1: the default profile may be a personal account, so it is confirmed before any listing.
                $picker = $this->picker($input, $output);
                try {
                    if (! $picker->confirmTarget($located, $this->credentialProfile ?? 'environment')) {
                        $io->writeln($this->t('resources.cancelled'));
                        return self::SUCCESS;
                    }
                } catch (PromptsUnavailable $e) {
                    $io->writeln($this->t('resources.prompts_unavailable', ['message' => $e->getMessage()]));
                    $picker = null;
                }
            }

            $inventory = new Inventory($this->credentialProfile ?? 'environment', $this->dnsCredentialProfile, $this->clock);
            $result = $inventory->collect($located, $aws, $dns, $types, function (ResourceType $type) use ($json, $stderr): void {
                if (! $json) {
                    $stderr->writeln($this->t('resources.listing_type', ['type' => mb_strtolower($this->t('resources.type.' . $type->value))]));
                }
            });
            foreach ($inventory->warnings as $warning) {
                $errorIo->warning($this->t($warning['key'], $warning['params']));
            }

            if ($json) {
                $this->printJson($output, $located, $result, $inventory->warnings);
                return self::SUCCESS;
            }
            $all = (bool) $input->getOption('all');
            if ($picker !== null && $located->region !== $environmentRegion) {
                // The editing commands write the environment, whose resources live in its own region.
                $this->printPlain($io, $located, $result, $all);
                $io->writeln($this->t('resources.region_differs', ['region' => (string) $located->region, 'env_region' => $environmentRegion]));
                return self::SUCCESS;
            }
            if ($picker !== null) {
                try {
                    // The preview shows vpc() at the environment's level, the one vpc:set and vpc:remove write.
                    $arcoPhp = $this->projectDir() . '/arco.php';
                    $environmentVpc = SelectionPlan::environmentVpc(is_file($arcoPhp) ? (new ConfigLoader())->load($arcoPhp) : [], $located->environment);
                    return $picker->run($located, $result, $all, $environmentVpc);
                } catch (PromptsUnavailable $e) {
                    // Questions come before any command runs: nothing was written.
                    $io->writeln($this->t('resources.prompts_unavailable', ['message' => $e->getMessage()]));
                }
            }
            return $this->printPlain($io, $located, $result, $all);
        } catch (Throwable $e) {
            $message = $this->describeError($e) ?? throw $e;
            if ($json) {
                // Scripts reading --json get the error as JSON too, as info, queue:list and storage:list give it.
                $output->writeln((string) json_encode(['schema' => 1, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $io->error($message);
            }
            return self::FAILURE;
        }
    }

    private function picker(InputInterface $input, OutputInterface $output): Picker
    {
        return new Picker(
            $this->ui ?? new PromptsPickerUi($input, $output, $this->t('resources.pick_none')),
            $output,
            fn (string $key, array $params): string => $this->t($key, $params),
            fn (ResourceItem $item): string => $this->pickerLabel($item),
            fn (array $command, OutputInterface $out): int => $this->runEditingCommand($input, $command, $out),
        );
    }

    /**
     * Runs an `apply` argv in-process, never prompting, as `arco <command> <args>` would: the editing command does the
     * writing (ArcoFileEditor). The --profile given to `arco resources` goes to the commands that check with AWS.
     *
     * @param array{command: string, args: list<string>} $command
     */
    private function runEditingCommand(InputInterface $input, array $command, OutputInterface $output): int
    {
        try {
            $editing = ($this->getApplication() ?? throw new LogicException('resources runs inside the Arco application.'))->find($command['command']);
            $definition = $editing->getNativeDefinition();
            $arguments = array_keys($definition->getArguments());
            /** @var array<string, mixed> $parameters */
            $parameters = ['command' => $command['command']];
            foreach ($command['args'] as $arg) {
                if (! str_starts_with($arg, '--')) {
                    $parameters[array_shift($arguments) ?? throw new LogicException("Too many arguments for {$command['command']}.")] = $arg;
                    continue;
                }
                $parts = explode('=', substr($arg, 2), 2);
                $name = $parts[0];
                $value = $parts[1] ?? true;
                if ($definition->hasOption($name) && $definition->getOption($name)->isArray()) {
                    $values = $parameters["--$name"] ?? [];
                    $parameters["--$name"] = [...(is_array($values) ? $values : []), $value];
                } else {
                    $parameters["--$name"] = $value;
                }
            }
            $profile = $input->getOption('profile');
            if (is_string($profile) && $profile !== '' && $definition->hasOption('profile')) {
                $parameters['--profile'] = $profile;
            }
            $editingInput = new ArrayInput($parameters);
            $editingInput->setInteractive(false);
            return $editing->run($editingInput, $output);
        } catch (Throwable $e) {
            $output->writeln('<error>' . ($this->describeError($e) ?? $e->getMessage()) . '</error>');
            return self::FAILURE;
        }
    }

    /** A resource on one line for the picker's checklists: name and id, marks, details, warnings. */
    private function pickerLabel(ResourceItem $item): string
    {
        $parts = [$item->name === null || $item->name === $item->id ? $item->id : "{$item->name}  {$item->id}"];
        if ($item->inUse !== null) {
            $parts[] = $this->t('resources.in_use', ['source' => $item->inUse['source']]);
        }
        if ($item->hidden) {
            $parts[] = $this->t('resources.hidden');
        }
        if ($item->unnamed()) {
            $parts[] = $this->t('resources.unnamed');
        }
        $parts[] = $this->details($item);
        foreach ($this->warningLines($item) as $warning) {
            $parts[] = '! ' . $warning;
        }
        return implode(' · ', $parts);
    }

    /**
     * An item's warnings as a person reads them. A table's table_schema warnings (one per use its keys do not fit, as
     * the JSON keeps them) become one line: the use its keys fit, or that they fit none and what each use needs.
     *
     * @return list<string>
     */
    private function warningLines(ResourceItem $item): array
    {
        $lines = [];
        $misfits = [];
        foreach ($item->warnings as $warning) {
            if ($warning['key'] === 'resources.warning.table_schema') {
                $misfits[$warning['params']['use'] ?? ''] = $warning['params']['expected'] ?? '';
                continue;
            }
            $lines[] = $this->t($warning['key'], $warning['params']);
        }
        if ($misfits !== []) {
            $fits = array_values(array_diff(array_keys(Annotator::TABLE_KEYS), array_keys($misfits)));
            $lines[] = $fits === []
                ? $this->t('resources.warning.table_fits_none', ['generic' => $misfits['generic'] ?? '', 'cache' => $misfits['cache'] ?? '', 'failedJobs' => $misfits['failedJobs'] ?? ''])
                : $this->t('resources.warning.table_fits', ['uses' => implode(', ', array_map(fn (string $use): string => $this->t('resources.use.' . $use), $fits))]);
        }
        return $lines;
    }

    /**
     * The --type values in the order the command lists them; every type when none is given.
     *
     * @return list<ResourceType>
     */
    private function types(InputInterface $input): array
    {
        $option = $input->getOption('type');
        $wanted = is_array($option) ? array_values(array_filter($option, 'is_string')) : [];
        foreach ($wanted as $value) {
            if (ResourceType::tryFrom($value) === null) {
                $params = ['type' => $value, 'types' => implode(', ', array_map(fn (ResourceType $t) => $t->value, ResourceType::cases()))];
                throw new UserError($this->t('resources.unknown_type', $params), 'resources.unknown_type', $params);
            }
        }
        return array_values(array_filter(ResourceType::cases(), fn (ResourceType $t) => $wanted === [] || in_array($t->value, $wanted, true)));
    }

    /**
     * Spec §8. A failed type is {ok: false, error: {key, params, message}}; `warnings` holds what went wrong without
     * failing a type (a DNS profile whose zones could not be read), each {key, params, message}. `vpc` is the
     * environment's resolved vpc() (false: vpc(false); null: none), whose endpoints with a null id (Arco-managed) no
     * item lists: a caller building the one vpc:set starts from it.
     *
     * @param array<string, array{ok: true, items: list<ResourceItem>}|array{ok: false, error: array{key: string, params: array<string, string>}}> $result
     * @param list<array{key: string, params: array<string, string>}> $warnings
     */
    private function printJson(OutputInterface $output, AppConfig $config, array $result, array $warnings): void
    {
        $types = [];
        foreach ($result as $type => $listed) {
            $types[$type] = $listed['ok']
                ? ['ok' => true, 'items' => array_map(fn (ResourceItem $item) => $item->toArray(), $listed['items'])]
                : ['ok' => false, 'error' => self::error($listed['error']['key'], $listed['error']['params'], $this->t($listed['error']['key'], $listed['error']['params']))];
        }
        $output->writeln((string) json_encode([
            'schema' => 1,
            'account' => $config->account,
            'region' => $config->region,
            'environment' => $config->environment,
            'vpc' => self::vpcDocument($config),
            'types' => (object) $types,
            'warnings' => array_map(fn (array $w) => self::error($w['key'], $w['params'], $this->t($w['key'], $w['params'])), $warnings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{subnets: list<string>, securityGroups: list<string>, endpoints: list<array{service: string, id: ?string}>, source: string}|false|null
     */
    private static function vpcDocument(AppConfig $config): array|false|null
    {
        $source = $config->sources['vpc'] ?? 'default';
        if ($config->vpc === null) {
            return $source === 'default' ? null : false; // set, so vpc(false)
        }
        return [
            'subnets' => $config->vpc->subnets,
            'securityGroups' => $config->vpc->securityGroups,
            'endpoints' => $config->vpc->endpoints,
            'source' => $source === 'cloud' ? 'cloud' : 'arco.php',
        ];
    }

    /**
     * One section per type: each resource's id and name, its details, warnings, what picking it would replace, and the
     * command that adds it. Hidden resources only with --all. Never prompts and writes nothing.
     *
     * @param array<string, array{ok: true, items: list<ResourceItem>}|array{ok: false, error: array{key: string, params: array<string, string>}}> $result
     */
    private function printPlain(SymfonyStyle $io, AppConfig $config, array $result, bool $all): int
    {
        $io->writeln($this->t('resources.target', [
            'account' => (string) $config->account,
            'profile' => $this->credentialProfile ?? 'environment',
            'region' => (string) $config->region,
            'environment' => $config->environment,
        ]));
        $network = false;
        foreach ($result as $type => $listed) {
            $title = $this->t('resources.type.' . $type);
            if (! $listed['ok']) {
                $io->section($title);
                $io->writeln('<error>' . $this->t($listed['error']['key'], $listed['error']['params']) . '</error>');
                continue;
            }
            $shown = array_values(array_filter($listed['items'], fn (ResourceItem $item) => $all || ! $item->hidden));
            $hidden = count($listed['items']) - count($shown);
            $io->section($title . ' (' . count($shown) . ')');
            if ($shown === []) {
                $io->writeln($this->t('resources.none'));
            }
            foreach ($shown as $item) {
                $this->printItem($io, $item);
                $network = $network || in_array($item->type, [ResourceType::Subnet, ResourceType::SecurityGroup, ResourceType::Endpoint], true);
            }
            if ($hidden > 0) {
                $io->writeln($this->t('resources.hidden_count', ['count' => $hidden]));
            }
        }
        $io->newLine();
        $io->writeln($this->t('resources.nothing_written'));
        if ($network) {
            $io->writeln($this->t('resources.vpc_full_list'));
        }
        return self::SUCCESS;
    }

    private function printItem(SymfonyStyle $io, ResourceItem $item): void
    {
        $marks = [];
        if ($item->inUse !== null) {
            $marks[] = $this->t('resources.in_use', ['source' => $item->inUse['source']]);
        }
        if ($item->hidden) {
            $marks[] = $this->t('resources.hidden');
        }
        if ($item->unnamed()) {
            $marks[] = $this->t('resources.unnamed');
        }
        $label = $item->name === null || $item->name === $item->id ? $item->id : "{$item->name}  {$item->id}";
        $io->writeln(' <info>' . $label . '</info>' . ($marks === [] ? '' : '  · ' . implode(' · ', $marks)));
        $io->writeln('   ' . $this->details($item));
        foreach ($this->warningLines($item) as $warning) {
            $io->writeln('   <comment>! ' . $warning . '</comment>');
        }
        if ($item->conflict !== null) {
            $io->writeln('   ' . $this->t('resources.conflict', $item->conflict));
        }
        $apply = $item->apply;
        if ($apply === null) {
            return;
        }
        if (isset($apply['command']) && is_string($apply['command'])) {
            /** @var array{command: string, args: list<string>} $apply */
            $io->writeln('   ' . self::shellLine($apply));
            return;
        }
        /** @var array<string, array{command: string, args: list<string>}> $apply */
        foreach ($apply as $use => $command) {
            $io->writeln('   ' . $this->t('resources.use.' . $use) . ': ' . self::shellLine($command));
        }
    }

    /** The type's identifying fields of spec §5.2 on one line. */
    private function details(ResourceItem $item): string
    {
        $d = $item->details;
        $text = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '-';
        $vpc = is_array($d['vpc'] ?? null) ? $text($d['vpc']['id'] ?? null) . (is_string($d['vpc']['name'] ?? null) ? " ({$d['vpc']['name']})" : '') : '-';
        $list = static fn (mixed $values): string => is_array($values) && $values !== [] ? implode(', ', array_filter($values, 'is_string')) : '-';
        $params = match ($item->type) {
            ResourceType::Subnet => ['vpc' => $vpc, 'az' => $text($d['az'] ?? null), 'cidr' => $text($d['cidr'] ?? null), 'free' => $text($d['freeIps'] ?? null), 'egress' => $text($d['egress'] ?? null)],
            ResourceType::SecurityGroup => ['vpc' => $vpc, 'description' => $text($d['description'] ?? null)],
            ResourceType::Endpoint => ['vpc' => $vpc, 'service' => $text($d['service'] ?? null), 'routeTables' => $list($d['routeTables'] ?? null)],
            ResourceType::Bucket => ['region' => $text($d['region'] ?? null), 'created' => $text($d['created'] ?? null)],
            ResourceType::Queue => ['arn' => $text($d['arn'] ?? null), 'timeout' => $text($d['visibilityTimeout'] ?? null), 'dlq' => $text($d['dlq'] ?? null)],
            ResourceType::Table => [
                'keys' => is_array($d['keys'] ?? null) && $d['keys'] !== []
                    ? implode(', ', array_map(static fn (mixed $k): string => is_array($k) ? $text($k['name'] ?? null) . ' (' . $text($k['type'] ?? null) . ', ' . $text($k['role'] ?? null) . ')' : '', $d['keys']))
                    : '-',
                'billing' => $text($d['billing'] ?? null),
                'ttl' => $text($d['ttl'] ?? null),
            ],
            ResourceType::Certificate => ['status' => $text($d['status'] ?? null), 'expires' => $text($d['expires'] ?? null), 'domains' => $list($d['domains'] ?? null), 'inUseBy' => $text($d['inUseBy'] ?? null)],
            ResourceType::Zone => [
                'visibility' => $this->t(($d['private'] ?? false) === true ? 'resources.zone_private' : 'resources.zone_public'),
                'records' => $text($d['records'] ?? null),
                'account' => $text($d['account'] ?? null),
            ],
        };
        return $this->t('resources.detail.' . $item->type->value, $params);
    }

    /**
     * An `apply` argv as one line a person can paste into a shell: `arco` and every element quoted when a POSIX shell
     * would split or expand it.
     *
     * @param array{command: string, args: list<string>} $apply
     */
    public static function shellLine(array $apply): string
    {
        $quote = static fn (string $arg): string => preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#D', $arg) === 1
            ? $arg
            : "'" . str_replace("'", "'\\''", $arg) . "'";
        return implode(' ', array_map($quote, ['arco', $apply['command'], ...$apply['args']]));
    }

    /**
     * @param array<string, string|int> $params
     * @return array{key: string, params: object, message: string}
     */
    private static function error(string $key, array $params, string $message): array
    {
        return ['key' => $key, 'params' => (object) $params, 'message' => $message];
    }
}
