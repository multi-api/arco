<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Arco;
use Multiapi\Arco\Aws\DnsZoneFinder;
use Multiapi\Arco\Aws\LocalState;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\Build\AppInspector;
use Multiapi\Arco\Build\Detection;
use Multiapi\Arco\Build\FrameworkPreset;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Build\RequiresPackages;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ChoosesDns;
use Multiapi\Arco\Commands\Concerns\SuggestsSchedulerInterval;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Validator;
use Multiapi\Arco\Deploy\DeployCommands;
use Multiapi\Arco\Deploy\PackageRequirements;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @phpstan-type InitFlags array{console: ?bool, migrate: ?bool, scheduler: ?int, logRetention: ?int, queues: list<string>, failedJobsTable: bool, storage: ?string, cache: bool}
 */
#[AsCommand(name: 'init', description: 'Create arco.php for this project')]
final class InitCommand extends ArcoCommand
{
    use ChoosesDns;
    use SuggestsSchedulerInterval;

    private const SECRET_LIKE = '/(^APP_KEY$|_KEY$|_SECRET$|_PASSWORD$|_TOKEN$)/';

    /**
     * @param AppInspector|null $inspector reads what a Laravel app uses; default: one that asks artisan for the schedule
     * @param (Closure(string): \Multiapi\Arco\Deploy\Domains\CloudflareApi)|null $cloudflareFactory
     */
    public function __construct(private readonly ?PresetRegistry $presets = null, ?DnsZoneFinder $zoneFinder = null, private readonly ?AppInspector $inspector = null, ?Closure $cloudflareFactory = null)
    {
        $this->zoneFinder = $zoneFinder ?? DnsZoneFinder::default();
        $this->cloudflareFactory = $cloudflareFactory;
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
            ->addOption('dns', null, InputOption::VALUE_REQUIRED, '"manual" when the DNS is not in Route 53, "cloudflare" when the zones are on Cloudflare')
            ->addOption('console', null, InputOption::VALUE_NEGATABLE, 'Add the console function (arco command, tinker, deploy commands, scheduler)')
            ->addOption('migrate', null, InputOption::VALUE_NEGATABLE, 'Run "migrate --force" on every deploy (adds the console function)')
            ->addOption('scheduler', null, InputOption::VALUE_REQUIRED, 'Run the Laravel scheduler every N minutes (' . implode(', ', Validator::SCHEDULER_INTERVALS) . ')')
            ->addOption('log-retention', null, InputOption::VALUE_REQUIRED, 'Days CloudWatch keeps the logs')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add an SQS queue and its worker function (repeatable)')
            ->addOption('failed-jobs-table', null, InputOption::VALUE_NONE, 'Keep failed jobs in a DynamoDB table')
            ->addOption('storage', null, InputOption::VALUE_REQUIRED, 'Add an S3 bucket under this storage name')
            ->addOption('cache', null, InputOption::VALUE_NONE, 'Add the DynamoDB cache table');
        $this->addCloudflareTokenOption();
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

        $app = self::slug($name);
        try {
            $flags = $this->flags($input, $app, $environments);
            [$domains, $dns] = $this->askDomains($input, $io, $environments, $dir);
        } catch (UserError $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        $laravel = $preset->name() === 'laravel';
        $detection = $laravel ? ($this->inspector ?? new AppInspector())->detect($dir) : null;
        $connection = $laravel ? AppInspector::databaseConnection("$dir/.env") : null;
        [$choices, $migrate] = $this->choose($input, $io, $flags, $detection, $connection, $app, $environments);
        $deployCommands = $migrate ? [DeployCommands::MIGRATE] : [];

        $secretKeys = self::secretKeys("$dir/.env");
        $php = self::render($app, $region, $environments, $secretKeys, $domains, $dns, $deployCommands, $choices, RuntimeManifest::load()->defaultPhp());
        [$errors, $warnings, $raw] = $this->check($dir, $php, $environments);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $io->error($error);
            }
            return self::FAILURE;
        }
        file_put_contents($path, $php);
        self::ignoreLocalState("$dir/.gitignore");

        $io->success($this->t('init.created', ['preset' => $preset->name()]));
        foreach ($warnings as $warning) {
            $io->warning($warning);
        }
        if ($secretKeys !== []) {
            $io->writeln($this->t('init.secrets_next'));
            foreach ($environments as $environment) {
                foreach ($secretKeys as $key) {
                    $io->writeln(sprintf('  vendor/bin/arco secret:set %s --env=%s', self::parameterName($key), $environment));
                }
            }
        }
        $hints = $detection !== null && ! $input->isInteractive() ? $this->hints($detection, $flags, $choices, $app, $environments) : [];
        if ($hints !== []) {
            $io->writeln($this->t('init.detected_hint'));
            foreach ($hints as $hint) {
                $io->writeln('  vendor/bin/arco ' . $hint);
            }
        }
        $missing = self::missingPackages($dir, $raw, $environments[0] ?? 'production', $preset);
        if ($missing !== []) {
            $io->writeln($this->t('init.needs_packages'));
            $io->writeln('  composer require ' . implode(' ', $missing));
        }
        $io->writeln($this->t('init.deploy_next', ['environment' => $environments[0] ?? 'production']));
        return self::SUCCESS;
    }

    /**
     * The resource flags, validated before anything is asked or written.
     *
     * @param list<string> $environments
     * @return InitFlags
     */
    private function flags(InputInterface $input, string $app, array $environments): array
    {
        $console = $input->getOption('console');
        $migrate = $input->getOption('migrate');
        $numbers = ['scheduler' => null, 'logRetention' => null];
        foreach (['scheduler' => 'scheduler', 'logRetention' => 'log-retention'] as $setting => $option) {
            $value = $input->getOption($option);
            if (! is_string($value)) {
                continue;
            }
            $number = self::intOrText($value);
            $error = Validator::settingError($setting, $number);
            if ($error !== null) {
                throw new UserError($this->t($error['key'], $error['params']));
            }
            $numbers[$setting] = (int) $number;
        }
        /** @var list<string> $queues */
        $queues = array_values(array_unique(array_filter((array) $input->getOption('queue'), 'is_string')));
        foreach ($queues as $queue) {
            $this->checkName($this->queueErrors($app, $environments, $queue));
        }
        $storage = $input->getOption('storage');
        $storage = is_string($storage) ? $storage : null;
        if ($storage !== null) {
            $this->checkName($this->storageErrors($app, $environments, $storage));
        }
        if ($console === false && ($numbers['scheduler'] !== null || $migrate === true)) {
            throw new UserError($this->t('init.needs_console'));
        }
        return [
            'console' => is_bool($console) ? $console : null,
            'migrate' => is_bool($migrate) ? $migrate : null,
            'scheduler' => $numbers['scheduler'],
            'logRetention' => $numbers['logRetention'],
            'queues' => $queues,
            'failedJobsTable' => (bool) $input->getOption('failed-jobs-table'),
            'storage' => $storage,
            'cache' => (bool) $input->getOption('cache'),
        ];
    }

    /**
     * Why a queue Arco creates cannot take this name in one of the environments.
     *
     * @param list<string> $environments
     * @return list<array{key: string, params: array<string, string>}>
     */
    private function queueErrors(string $app, array $environments, string $name): array
    {
        return array_merge([], ...array_map(static fn (string $environment): array => Validator::newQueueErrors($app, $environment, $name), $environments));
    }

    /**
     * Why a storage Arco creates cannot take this name in one of the environments.
     *
     * @param list<string> $environments
     * @return list<array{key: string, params: array<string, string>}>
     */
    private function storageErrors(string $app, array $environments, string $name): array
    {
        return array_values(array_filter(array_map(static fn (string $environment): ?array => Validator::newStorageError($app, $environment, $name), $environments)));
    }

    /** @param list<array{key: string, params: array<string, string>}> $errors */
    private function checkName(array $errors): void
    {
        if ($errors !== []) {
            throw new UserError($this->t($errors[0]['key'], $errors[0]['params']));
        }
    }

    /**
     * The resources to write: each flag answers its question; without a terminal only the flags count.
     * The detected pieces are offered only for Laravel apps ($detection null otherwise).
     *
     * @param InitFlags $flags
     * @param list<string> $environments
     * @return array{0: InitChoices, 1: bool} the choices and whether to migrate on deploy
     */
    private function choose(InputInterface $input, SymfonyStyle $io, array $flags, ?Detection $detection, ?string $connection, string $app, array $environments): array
    {
        $ask = $input->isInteractive();
        // The scheduler and deploy commands run in the console function: asking for them turns it on.
        $console = $flags['console'] ?? ($flags['scheduler'] !== null || $flags['migrate'] === true ? true : null);
        $console ??= $ask && $io->confirm($this->t('init.ask_console'), true);

        $migrate = $flags['migrate'] ?? false;
        if ($flags['migrate'] === null && $ask && $connection !== null && $connection !== 'sqlite') {
            if ($console) {
                $migrate = $io->confirm($this->t('init.ask_migrate', ['connection' => $connection]), true);
            } else {
                $io->note($this->t('init.migrate_needs_console', ['connection' => $connection]));
            }
        }

        $scheduler = $flags['scheduler'];
        $schedule = $detection?->schedule;
        if ($scheduler === null && $ask && $console && $schedule !== null) {
            $suggested = $this->suggestInterval($io, $schedule);
            if ($schedule === []) {
                $io->note($this->t('init.schedule_unread'));
            }
            if ($io->confirm($this->t('init.ask_scheduler'), true)) {
                $scheduler = $this->askInterval($io, $suggested);
            }
        }

        $queues = $flags['queues'];
        $detected = [];
        $tooLong = [];
        foreach ($detection->queues ?? [] as $queue) {
            if ($this->queueErrors($app, $environments, $queue) === []) {
                $detected[] = $queue;
            } else {
                $tooLong[] = $queue;
            }
        }
        if ($ask && $queues === [] && $tooLong !== []) {
            $io->note($this->t('init.queues_too_long', ['queues' => implode(', ', $tooLong)]));
        }
        if ($queues === [] && $ask && $detected !== []
            && $io->confirm($this->t('init.ask_queues', ['count' => $detection->queuedClasses ?? 0]), true)) {
            $queues = count($detected) > 1 ? $this->askQueueNames($io, $detected) : $detected;
        }

        $failedJobsTable = $flags['failedJobsTable'];
        if (! $failedJobsTable && $ask && $queues !== []) {
            // With a SQL database Laravel's database-uuids driver keeps them in a table of its own.
            $failedJobsTable = $io->confirm($this->t('init.ask_failed_jobs'), ! ($detection->sqlDatabase ?? false));
        }

        $storage = $flags['storage'];
        if ($storage === null && $ask && ($detection->storage ?? false) && $io->confirm($this->t('init.ask_storage'), true)) {
            $answer = $io->ask($this->t('init.ask_storage_name'), 'files', function (mixed $value) use ($app, $environments): string {
                $name = is_string($value) ? $value : '';
                $this->checkName($this->storageErrors($app, $environments, $name));
                return $name;
            });
            $storage = is_string($answer) ? $answer : 'files';
        }

        $cache = $flags['cache'] || ($ask && ($detection->cache ?? false) && $io->confirm($this->t('init.ask_cache'), true));

        $logRetention = $flags['logRetention'];
        if ($logRetention === null && $ask) {
            $choices = [];
            foreach (Validator::LOG_RETENTION_DAYS as $days) {
                $choices[$days] = (string) $days;
            }
            $choices['never'] = $this->t('init.log_retention_never');
            $answer = $io->choice($this->t('init.ask_log_retention'), $choices, '30');
            $logRetention = is_numeric($answer) ? (int) $answer : null;
        }

        return [new InitChoices($console, $scheduler, $logRetention, $queues, $failedJobsTable, $storage, $cache), $migrate];
    }

    /**
     * @param non-empty-list<string> $names
     * @return list<string> the chosen names, in the order detected
     */
    private function askQueueNames(SymfonyStyle $io, array $names): array
    {
        // SymfonyStyle shows a multiselect default by its keys.
        $question = new ChoiceQuestion($this->t('init.ask_queue_names'), $names, implode(',', array_keys($names)));
        $question->setMultiselect(true);
        $answer = $io->askQuestion($question);
        $chosen = is_array($answer) ? $answer : [];
        return array_values(array_filter($names, static fn (string $name): bool => in_array($name, $chosen, true)));
    }

    /**
     * The commands that add each detected piece a run without a terminal left out (only flags count there).
     *
     * @param InitFlags $flags
     * @param list<string> $environments
     * @return list<string>
     */
    private function hints(Detection $detection, array $flags, InitChoices $choices, string $app, array $environments): array
    {
        $hints = [];
        if ($flags['console'] === null && ! $choices->console) {
            $hints[] = 'console:add';
        }
        if ($detection->schedule !== null && $choices->scheduler === null && $flags['console'] !== false) {
            $hints[] = 'scheduler:set ' . $this->requiredInterval($detection->schedule);
        }
        if ($choices->logRetention === null) {
            $hints[] = 'logs:retention 30';
        }
        if ($choices->queues === []) {
            foreach ($detection->queues as $queue) {
                if ($this->queueErrors($app, $environments, $queue) !== []) {
                    continue;
                }
                $hints[] = 'queue:add ' . $queue;
            }
        }
        if ($detection->storage && $choices->storage === null) {
            $hints[] = 'storage:add files';
        }
        if ($detection->cache && ! $choices->cache) {
            $hints[] = 'cache:add';
        }
        return $hints;
    }

    /**
     * The validation errors and warnings of the arco.php about to be written, once each across environments,
     * as `arco deploy` will show them. It is loaded from a temporary file under .arco/, removed right after.
     *
     * @param list<string> $environments
     * @return array{0: list<string>, 1: list<string>, 2: array<string, mixed>} error messages, warning messages and the loaded config
     */
    private function check(string $dir, string $php, array $environments): array
    {
        $stateDir = "$dir/.arco";
        $created = ! is_dir($stateDir) && @mkdir($stateDir, 0777, true);
        $file = $stateDir . '/init-' . bin2hex(random_bytes(6)) . '.php';
        try {
            file_put_contents($file, $php);
            $raw = (new ConfigLoader())->load($file);
        } finally {
            @unlink($file);
            if ($created) {
                @rmdir($stateDir);
            }
        }
        $messages = [[], []];
        foreach ($environments as $environment) {
            $validation = (new Validator())->validate(ConfigResolver::resolve($raw, $environment), $dir);
            foreach ([$validation->errors, $validation->warnings] as $kind => $problems) {
                foreach ($problems as $problem) {
                    $messages[$kind][] = $this->t($problem['key'], $problem['params']);
                }
            }
        }
        return [array_values(array_unique($messages[0])), array_values(array_unique($messages[1])), $raw];
    }

    /**
     * The requirements of the first environment's features that composer.lock does not list under `packages`
     * (the ones Arco packages; `packages-dev` is left out of a `--no-dev` build). No lock file: nothing to compare.
     * Constraints are not compared: the deploy check does that.
     *
     * @param array<string, mixed> $raw
     * @return list<string> requirements for `composer require`
     */
    private static function missingPackages(string $dir, array $raw, string $environment, FrameworkPreset $preset): array
    {
        $lock = is_file("$dir/composer.lock") ? json_decode((string) file_get_contents("$dir/composer.lock"), true) : null;
        if (! is_array($lock)) {
            return [];
        }
        $installed = [];
        foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $installed[$package['name']] = true;
            }
        }
        $missing = [];
        foreach ($preset instanceof RequiresPackages ? $preset->requiredPackages() : [] as $package) {
            if (! isset($installed[$package])) {
                $missing[$package] = true;
            }
        }
        foreach (PackageRequirements::forConfig(ConfigResolver::resolve($raw, $environment)) as $requirements) {
            foreach ($requirements as [$requirement]) {
                if (! isset($installed[explode(':', $requirement)[0]])) {
                    $missing[$requirement] = true;
                }
            }
        }
        return array_map('strval', array_keys($missing));
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
     * @param list<string> $deployCommands app-level deploy commands
     * @param InitChoices|null $choices resources written on the App chain, after the deploy commands
     * @param string|null $phpMinor the PHP minor the app pins with runtime php(); null: none written
     */
    public static function render(string $name, string $region, array $environments, array $secretKeys, array $domains = [], array $dns = [], array $deployCommands = [], ?InitChoices $choices = null, ?string $phpMinor = null): string
    {
        $php = "<?php\n\nuse Multiapi\\Arco\\Config\\App;\nuse Multiapi\\Arco\\Config\\Environment;\n";
        $php .= ($phpMinor === null ? '' : "use Multiapi\\Arco\\Config\\Runtime;\n") . "\n";
        $php .= 'return App::make(' . var_export($name, true) . ")\n    ->region(" . var_export($region, true) . ')';
        if ($phpMinor !== null) {
            // A new PHP minor is opt-in: the app stays on this one until arco.php says otherwise.
            $php .= "\n    ->runtime(fn (Runtime \$r) => \$r->php(" . var_export($phpMinor, true) . '))';
        }
        if ($deployCommands !== []) {
            $php .= "\n    ->deployCommands([" . implode(', ', array_map(fn (string $c) => var_export($c, true), $deployCommands)) . '])';
        }
        foreach ($choices?->lines() ?? [] as $line) {
            $php .= "\n    " . $line;
        }
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
        foreach (AppInspector::envEntries($envFile) as [$key, $value]) {
            // AWS_* keys are either reserved by Lambda or replaced by the execution role; ARCO* names are
            // reserved for Arco and variables() refuses them.
            if (! preg_match(self::SECRET_LIKE, $key) || str_starts_with($key, 'AWS_') || str_starts_with($key, 'ARCO') || Validator::isReserved($key)) {
                continue;
            }
            // Placeholders such as `REDIS_PASSWORD=null` or `APP_KEY=` hold no secret to store.
            if (in_array(strtolower($value), ['', 'null'], true)) {
                continue;
            }
            $keys[] = $key;
        }
        return array_values(array_unique($keys));
    }

    private static function ignoreLocalState(string $gitignore): void
    {
        $current = is_file($gitignore) ? (string) file_get_contents($gitignore) : '';
        if (! preg_match('#^/?\.arco/?$#m', $current)) {
            file_put_contents($gitignore, rtrim($current) . ($current === '' ? '' : "\n") . ".arco/\n");
        }
    }
}
