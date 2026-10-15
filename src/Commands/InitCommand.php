<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Arco;
use Multiapi\Arco\Aws\DnsZoneFinder;
use Multiapi\Arco\Aws\LocalState;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ChoosesDns;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\Validator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'init', description: 'Create arco.php for this project')]
final class InitCommand extends ArcoCommand
{
    use ChoosesDns;

    private const SECRET_LIKE = '/(^APP_KEY$|_KEY$|_SECRET$|_PASSWORD$|_TOKEN$)/';

    public function __construct(private readonly ?PresetRegistry $presets = null, ?DnsZoneFinder $zoneFinder = null)
    {
        $this->zoneFinder = $zoneFinder ?? DnsZoneFinder::default();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'App name')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'AWS region')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Environments to create', ['production'])
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing arco.php')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Custom domain for the environments (repeatable)')
            ->addOption('dns-profile', null, InputOption::VALUE_REQUIRED, 'AWS profile whose Route 53 holds the DNS zones of the domains')
            ->addOption('dns', null, InputOption::VALUE_REQUIRED, '"manual" when the DNS is not in Route 53');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $this->projectDir();
        $path = "$dir/arco.php";

        if (is_file($path) && ! $input->getOption('force')) {
            $io->error($this->t('init.exists'));
            return self::FAILURE;
        }

        $preset = ($this->presets ?? PresetRegistry::default())->detect($dir);
        $name = $input->getOption('name');
        if (! is_string($name) || $name === '') {
            $answer = $io->ask($this->t('init.ask_name'), self::slug(basename($dir)));
            $name = is_string($answer) ? $answer : self::slug(basename($dir));
        }
        $region = $input->getOption('region');
        if (! is_string($region) || $region === '') {
            $answer = $io->choice($this->t('init.ask_region'), Arco::REGIONS, 'us-east-1');
            $region = is_string($answer) ? $answer : 'us-east-1';
        }
        /** @var list<string> $environments */
        $environments = array_values(array_unique(array_filter((array) $input->getOption('env'), 'is_string')));

        if (! in_array($region, Arco::REGIONS, true)) {
            $io->error($this->t('init.invalid_region', ['region' => $region, 'regions' => implode(', ', Arco::REGIONS)]));
            return self::FAILURE;
        }
        foreach ($environments as $environment) {
            if (! preg_match('/^[a-z][a-z0-9-]*$/', $environment)) {
                $io->error($this->t('init.invalid_environment', ['name' => $environment]));
                return self::FAILURE;
            }
        }

        try {
            [$domains, $dns] = $this->askDomains($input, $io, $environments, $dir);
        } catch (UserError $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        $secretKeys = self::secretKeys("$dir/.env");
        file_put_contents($path, self::render(self::slug($name), $region, $environments, $secretKeys, $domains, $dns));
        self::ignoreLocalState("$dir/.gitignore");

        $io->success($this->t('init.created', ['preset' => $preset->name()]));
        if ($secretKeys !== []) {
            $io->writeln($this->t('init.secrets_next'));
            foreach ($environments as $environment) {
                foreach ($secretKeys as $key) {
                    $io->writeln(sprintf('  vendor/bin/arco secret:set %s --env=%s', self::parameterName($key), $environment));
                }
            }
        }
        $io->writeln($this->t('init.deploy_next', ['environment' => $environments[0] ?? 'production']));
        return self::SUCCESS;
    }

    public static function slug(string $value): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
        if ($slug === '' || ! ctype_alpha($slug[0])) {
            $slug = 'app-' . $slug;
        }
        return rtrim($slug, '-');
    }

    public static function parameterName(string $variable): string
    {
        return str_replace('_', '-', strtolower($variable));
    }

    /**
     * Domains and dns() setting per environment, from --domain/--dns-profile/--dns or the questions.
     *
     * @param list<string> $environments
     * @return array{0: array<string, list<string>>, 1: array<string, string|false>}
     */
    private function askDomains(InputInterface $input, SymfonyStyle $io, array $environments, string $dir): array
    {
        /** @var list<string> $option */
        $option = array_values(array_filter((array) $input->getOption('domain'), 'is_string'));
        $flagDomains = $this->parseDomains($option);
        $flagDns = $this->dnsFromFlags($input);
        $state = new LocalState($dir);
        $domains = [];
        $dns = [];
        foreach ($environments as $environment) {
            $list = $flagDomains;
            if ($list === []) {
                if (! $input->isInteractive() || ! $io->confirm($this->t('init.ask_domain', ['environment' => $environment]), false)) {
                    continue;
                }
                $list = $this->askDomainList($io);
            }
            $deployProfile = (getenv('AWS_PROFILE') ?: null) ?? $state->profile($environment);
            $setting = $this->chooseDns($input, $io, $list, $deployProfile);
            $domains[$environment] = $list;
            if ($setting !== null) {
                $dns[$environment] = $setting;
            }
        }
        if ($domains === [] && $flagDns !== null) {
            $io->warning($this->t('init.dns_without_domain'));
        }
        return [$domains, $dns];
    }

    /** @return list<string> */
    private function askDomainList(SymfonyStyle $io): array
    {
        /** @var list<string> $answer */
        $answer = $io->ask($this->t('init.ask_domains'), null, function (mixed $value): array {
            $domains = $this->parseDomains(is_string($value) ? $value : '');
            if ($domains === []) {
                throw new UserError($this->t('init.domains_empty'));
            }
            return $domains;
        });
        return $answer;
    }

    /**
     * @param list<string> $environments
     * @param list<string> $secretKeys
     * @param array<string, list<string>> $domains per environment
     * @param array<string, string|false> $dns per environment; absent means Route 53 in the deploy account
     */
    public static function render(string $name, string $region, array $environments, array $secretKeys, array $domains = [], array $dns = []): string
    {
        $php = "<?php\n\nuse Multiapi\\Arco\\Config\\App;\nuse Multiapi\\Arco\\Config\\Environment;\n\n";
        $php .= 'return App::make(' . var_export($name, true) . ")\n    ->region(" . var_export($region, true) . ')';
        foreach ($environments as $environment) {
            $php .= "\n    ->environment(" . var_export($environment, true) . ", fn (Environment \$env) => \$env\n        ->variables([\n";
            $php .= "            'APP_ENV' => " . var_export($environment, true) . ",\n";
            foreach ($secretKeys as $key) {
                $php .= sprintf("            %s => %s,\n", var_export($key, true), var_export(sprintf('arco-ssm:/%s/%s/%s', $name, $environment, self::parameterName($key)), true));
            }
            $php .= "        ])";
            if (($domains[$environment] ?? []) !== []) {
                $php .= "\n        " . ArcoFileEditor::domainLine($domains[$environment]);
                if (array_key_exists($environment, $dns)) {
                    $php .= "\n        " . ArcoFileEditor::dnsLine($dns[$environment]);
                }
            }
            $php .= ')';
        }
        return $php . ";\n";
    }

    /** @return list<string> */
    private static function secretKeys(string $envFile): array
    {
        if (! is_file($envFile)) {
            return [];
        }
        $keys = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=(.*)$/', $line, $m)) {
                continue;
            }
            [, $key, $value] = $m;
            // AWS_* keys are either reserved by Lambda or replaced by the execution role.
            if (! preg_match(self::SECRET_LIKE, $key) || str_starts_with($key, 'AWS_') || Validator::isReserved($key)) {
                continue;
            }
            // Placeholders such as `REDIS_PASSWORD=null` or `APP_KEY=` hold no secret to store.
            if (in_array(strtolower(self::unquote($value)), ['', 'null'], true)) {
                continue;
            }
            $keys[] = $key;
        }
        return array_values(array_unique($keys));
    }

    private static function unquote(string $value): string
    {
        $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    private static function ignoreLocalState(string $gitignore): void
    {
        $current = is_file($gitignore) ? (string) file_get_contents($gitignore) : '';
        if (! preg_match('#^/?\.arco/?$#m', $current)) {
            file_put_contents($gitignore, rtrim($current) . ($current === '' ? '' : "\n") . ".arco/\n");
        }
    }
}
