<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Aws\Endpoint;
use Multiapi\Arco\Binary\BinaryException;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\Aws\LocalState;
use Multiapi\Arco\Aws\ProfileResolver;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\GenericTableConfig;
use Multiapi\Arco\Deploy\RevisionFactory;
use Multiapi\Arco\Config\PermissionsBoundary;
use Multiapi\Arco\Config\VpcConfig;
use Multiapi\Arco\Config\StorageConfig;
use Multiapi\Arco\Config\TableConfig;
use Multiapi\Arco\Deploy\AppStatus;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\Deployer;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Deploy\EnvironmentGroup;
use Multiapi\Arco\Secrets\SecretReference;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'info', description: 'Show the resolved configuration and deployment status')]
final class InfoCommand extends ArcoCommand
{
    use ResolvesTarget;

    /** @var Closure(AwsClients): Deployer */
    private Closure $deployerFactory;

    public function __construct(?Closure $awsFactory = null, ?Closure $deployerFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->deployerFactory = $deployerFactory ?? fn (AwsClients $aws) => new AwsDeployer($aws);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON')
            ->addOption('resources', null, InputOption::VALUE_NONE, 'List every AWS resource tagged for the environment')
            ->addOption(PermissionsBoundary::OPTION, null, InputOption::VALUE_REQUIRED, 'Show the permissions boundary as deploy would use it with this flag');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            // Config warnings would corrupt machine-readable output, so --json suppresses them.
            $config = $this->loadTarget($input, $input->getOption('json') ? new SymfonyStyle($input, new NullOutput()) : $io);
        } catch (Throwable $e) {
            $message = $this->describeError($e) ?? throw $e;
            if ($input->getOption('json')) {
                // Scripts reading --json get the error as JSON too (like queue:list and storage:list).
                $output->writeln((string) json_encode(['schema' => 1, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $io->error($message);
            }
            return self::FAILURE;
        }

        if ($input->getOption('resources')) {
            return $this->showResources($config, $this->profileFlag($input), (bool) $input->getOption('json'), $output, $io);
        }

        [$status, $remoteError] = $this->remoteStatus($config, $this->profileFlag($input));
        $settings = $this->settings($config);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'function' => $config->functionName(),
                'url' => $status?->domainName !== null ? Endpoint::scheme() . '://' . $status->domainName : null,
                'revision' => $status?->activeRevisionId,
                'domains' => $config->domains,
                'distribution' => $status?->distributionDomain !== null ? ['domain' => $status->distributionDomain, 'status' => $status->distributionStatus] : null,
                'console' => $config->consoleEnabled()
                    ? ['function' => $config->consoleFunctionName(), 'version' => $status?->consoleVersion]
                        + ($status?->consoleVersionError !== null ? ['error' => $status->consoleVersionError] : [])
                        + ['enabled' => true]
                    : ['enabled' => false, 'function' => $config->consoleFunctionName()],
                'queues' => $this->jsonQueues($config, $status),
                'storage' => $this->jsonStorage($config, $status),
                'cache' => self::jsonTable($config, $config->cacheTable),
                'failedJobsTable' => self::jsonTable($config, $config->failedJobsTable),
                'deployCommands' => $config->deployCommands,
                'settings' => $this->jsonSettings($settings, $status),
                // Additive (0.10), last so the earlier keys keep their order: cloudflare, route53 (<profile>)
                // or manual; null without domains.
                'dns' => self::dnsProvider($config),
                // Additive (0.10), last: the table() tables, name => DynamoDB table (as in ARCO_TABLES); [] without any.
                'tables' => array_map(fn (GenericTableConfig $table) => [
                    'name' => $table->name,
                    'table' => $table->tableName($config),
                    'existing' => $table->existing !== null,
                    'ttl' => $table->ttl,
                ], $config->tables),
                // Additive (0.11), last: the execution role's boundary after the deploy-host override; null without one.
                'permissions_boundary' => $config->permissionsBoundary,
                // Additive (0.11.4), last: the endpoint's auth type, IamAuth with domain() whatever auth says.
                'endpoint_auth' => RevisionFactory::authType($config),
                // Additive (0.12), last: the vpc() subnets and security groups; null without vpc().
                // With a deploy, also the network connector: its id, latest version and state.
                // 0.12.1: its gateway endpoints per service ([] without any, null when unreadable or not read).
                'vpc' => $config->vpc === null ? null : ['subnets' => $config->vpc->subnets, 'security_groups' => $config->vpc->securityGroups] + ($status->vpc ?? [])
                    + ['endpoints' => $config->vpc->endpointIds() === [] ? [] : $status?->endpoints],
            ] + ($remoteError !== null ? ['error' => $remoteError] : []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $remoteError === null ? self::SUCCESS : self::FAILURE;
        }

        $io->title($config->functionName());
        $rows = [];
        foreach ($settings as $key => $row) {
            $value = $this->display($key, $row[0], $status);
            if ($key === 'auth' && RevisionFactory::protectsEndpoint($config)) {
                // domain() makes the endpoint IamAuth whatever auth() says: only CloudFront may invoke it.
                $value = $this->t('info.auth_cloudfront', ['auth' => $value]);
            }
            $rows[] = [$key === 'vpc' ? 'VPC' : $key, $value, $row[1]];
            if ($key === 'vpc' && $status?->endpoints !== null && $status->endpoints !== []) {
                $rows[] = [$this->t('info.vpc_endpoints'), $this->endpointsLabel($status->endpoints), $row[1]];
            }
            if ($key === 'dns' && ($provider = self::dnsProvider($config)) !== null) {
                $rows[] = ['dns.provider', $provider, $row[1]];
            }
        }
        $io->table([$this->t('info.setting'), $this->t('info.value'), $this->t('info.source')], $rows);

        $io->section($this->t('info.variables'));
        $rows = [];
        foreach ($config->variables as $name => $value) {
            $rows[] = [$name, SecretReference::isReference($value) || ! preg_match('/(_KEY|_SECRET|_PASSWORD|_TOKEN)$/', $name) ? $value : '••••••'];
        }
        $io->table([$this->t('info.variable'), $this->t('info.value')], $rows);

        if ($remoteError !== null) {
            $io->error($remoteError);
            return self::FAILURE;
        }
        if ($status === null) {
            $io->note($this->t('info.no_remote'));
        } else {
            $io->definitionList(
                ['URL' => $status->domainName !== null ? Endpoint::scheme() . '://' . $status->domainName : '-'],
                [$this->t('info.state') => $status->state],
                [$this->t('info.revision') => $status->activeRevisionId ?? '-'],
                [$this->t('info.console') => $config->consoleEnabled() ? $this->consoleStatus($status) : $this->t('info.console_off')],
                ...($status->distributionDomain !== null ? [[$this->t('info.distribution') => "{$status->distributionDomain} ({$status->distributionStatus})"]] : []),
                ...array_map(fn ($queue) => [$this->t('info.queue', ['queue' => $queue->name]) => $this->t('info.queue_status', [
                    'url' => $status->queues[$queue->name]['url'] ?? '-',
                    'worker' => $this->workerStatus($config->workerFunctionName($queue->name), $status->queues[$queue->name] ?? null),
                ])], $config->queues),
                ...array_map(fn (StorageConfig $storage) => [$this->t('info.storage', ['storage' => $storage->name]) => $this->storageStatus($config, $storage, $status)], $config->storage),
                ...($config->cacheTable !== null ? [[$this->t('info.cache_table') => $this->tableStatus($config, $config->cacheTable)]] : []),
                ...($config->failedJobsTable !== null ? [[$this->t('info.failed_jobs_table') => $this->tableStatus($config, $config->failedJobsTable)]] : []),
                ...array_map(fn (GenericTableConfig $table) => [$this->t('info.table', ['table' => $table->name]) => $this->genericTableStatus($config, $table)], $config->tables),
            );
        }
        return self::SUCCESS;
    }

    /**
     * `--resources`: what the `arco:app`/`arco:environment` tags say belongs to the environment, and
     * whether `arco remove` keeps each (also as `--json`, schema 1).
     */
    private function showResources(AppConfig $config, ?string $profile, bool $json, OutputInterface $output, SymfonyStyle $io): int
    {
        $group = EnvironmentGroup::name($config);
        [$resources, $error] = $this->taggedResources($config, $profile);
        if ($json) {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'app' => $config->app,
                'environment' => $config->environment,
                'group' => $group,
                'resources' => $resources,
            ] + ($error !== null ? ['error' => $error] : []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $error === null ? self::SUCCESS : self::FAILURE;
        }
        if ($error !== null) {
            $io->error($error);
            return self::FAILURE;
        }
        $io->title($this->t('info.resources_title', ['app' => $config->app, 'environment' => $config->environment]));
        if ($resources === []) {
            $io->note($this->t('info.resources_none'));
        } else {
            $io->table(
                [$this->t('info.resource_type'), $this->t('info.resource_name'), $this->t('info.resource_on_remove')],
                array_map(fn (array $resource) => [$resource['type'], $resource['name'], $this->t($resource['retained'] ? 'info.resource_kept' : 'info.resource_deleted')], $resources),
            );
        }
        $io->writeln($this->t('info.resources_group', ['group' => $group]));
        return self::SUCCESS;
    }

    /**
     * The tagged resources, or the error to show: no profile resolves without prompting, the
     * credentials are missing or expired, or AWS refused the lookup (tag:GetResources).
     *
     * @return array{0: list<array{type: string, name: string, arn: string, retained: bool, relations: list<array{type: string, target: string}>}>, 1: ?string}
     */
    private function taggedResources(AppConfig $config, ?string $profileFlag): array
    {
        /** @var array<string, string> $env */
        $env = getenv();
        $choice = ProfileResolver::resolve($profileFlag, $env, $config, new LocalState($this->projectDir()));
        if ($choice === null) {
            return [[], $this->t('info.resources_no_profile', ['environment' => $config->environment])];
        }
        try {
            return [($this->deployerFactory)($this->guardedClients($config, $choice->profile))->resources($config), null];
        } catch (UserError $e) {
            return [[], $e->getMessage()];
        } catch (Throwable $e) {
            $message = $e instanceof AwsException ? $e->getAwsErrorMessage() : null;
            return [[], $this->credentialProblem($e, $choice->profile)
                ?? $this->t('info.resources_failed', ['error' => is_string($message) && $message !== '' ? $message : $e->getMessage()])];
        }
    }

    /** @param array{url: ?string, version: ?string, error: ?string}|null $queue */
    private function workerStatus(string $function, ?array $queue): string
    {
        $error = $queue['error'] ?? null;
        return match (true) {
            ($queue['version'] ?? null) !== null => $this->t('queue.worker_version', ['function' => $function, 'version' => (string) $queue['version']]),
            $error === null => $this->t('queue.worker_missing'),
            str_contains($error, 'AccessDenied') => $this->t('queue.worker_denied'),
            default => $this->t('queue.worker_unknown', ['error' => $error]),
        };
    }

    /**
     * The queues in arco.php, with the URL and the worker's live version when AWS was read.
     *
     * @return list<array<string, mixed>>
     */
    private function jsonQueues(AppConfig $config, ?AppStatus $status): array
    {
        return array_map(fn ($queue) => [
            'name' => $queue->name,
            'url' => $status?->queues[$queue->name]['url'] ?? null,
            'existing' => $queue->existing !== null,
            'dlq' => $queue->dlqName($config),
            // Like the console: error only when the version could not be read (unknown, not missing).
            'worker' => ['function' => $config->workerFunctionName($queue->name), 'version' => $status?->queues[$queue->name]['version'] ?? null]
                + (($status?->queues[$queue->name]['error'] ?? null) !== null ? ['error' => $status->queues[$queue->name]['error']] : []),
        ], $config->queues);
    }

    /** The config with the account AWS reported, when arco.php has none (needed for created bucket names). */
    private static function located(AppConfig $config, ?AppStatus $status): ?AppConfig
    {
        return match (true) {
            $config->account !== null => $config,
            $status?->account !== null => $config->withAccount($status->account),
            default => null,
        };
    }

    /**
     * The storages in arco.php; a created bucket's name and URL need the account, so they are null
     * without it (not deployed, or not readable).
     *
     * @return list<array{name: string, bucket: ?string, url: ?string, existing: bool, default: bool, defaultOverridden: bool, public: list<string>}>
     */
    private function jsonStorage(AppConfig $config, ?AppStatus $status): array
    {
        $located = self::located($config, $status);
        return array_map(fn (StorageConfig $storage) => [
            'name' => $storage->name,
            'bucket' => $storage->existing !== null || $located !== null ? $storage->bucketName($located ?? $config) : null,
            'url' => $storage->existing !== null || $located !== null ? $storage->url($located ?? $config) : null,
            'existing' => $storage->existing !== null,
            'default' => $storage->default,
            'defaultOverridden' => $storage->default && DeployPipeline::storageOverrides($config, $located) !== [],
            'public' => $storage->public,
        ], $config->storage);
    }

    /** @return array{table: string, existing: bool}|null */
    private static function jsonTable(AppConfig $config, ?TableConfig $table): ?array
    {
        return $table === null ? null : ['table' => $table->tableName($config), 'existing' => $table->existing !== null];
    }

    private function storageStatus(AppConfig $config, StorageConfig $storage, AppStatus $status): string
    {
        $located = self::located($config, $status);
        if ($storage->existing !== null) {
            $text = $this->t('info.existing', ['name' => $storage->existing]);
        } else {
            $text = $located === null ? '-' : $storage->bucketName($located);
        }
        if ($storage->default) {
            $overrides = DeployPipeline::storageOverrides($config, $located);
            $text .= ' · ' . ($overrides === []
                ? $this->t('info.storage_default')
                : $this->t('info.storage_default_overridden', ['keys' => implode(', ', array_keys($overrides))]));
        }
        if ($storage->public !== []) {
            $text .= ' · ' . $this->t('info.storage_public', ['prefixes' => implode(', ', $storage->public)]);
        }
        return $text;
    }

    private function tableStatus(AppConfig $config, TableConfig $table): string
    {
        return $table->existing !== null ? $this->t('info.existing', ['name' => $table->existing]) : $table->tableName($config);
    }

    private function genericTableStatus(AppConfig $config, GenericTableConfig $table): string
    {
        $text = $table->existing !== null ? $this->t('info.existing', ['name' => $table->existing]) : $table->tableName($config);
        return $table->ttl === null ? $text : $text . ' · ' . $this->t('info.table_ttl', ['attribute' => $table->ttl]);
    }

    private function consoleStatus(AppStatus $status): string
    {
        return match (true) {
            $status->consoleVersion !== null => $this->t('info.console_version', ['function' => (string) $status->consoleFunction, 'version' => $status->consoleVersion]),
            $status->consoleVersionError === null => $this->t('info.console_missing'),
            str_contains($status->consoleVersionError, 'AccessDenied') => $this->t('info.console_denied'),
            default => $this->t('info.console_unknown', ['error' => $status->consoleVersionError]),
        };
    }

    /**
     * Remote status only when a profile resolves without prompting, plus the error to show when
     * the credentials are missing or expired (anything else still reads as "not deployed").
     *
     * @return array{0: ?AppStatus, 1: ?string}
     */
    private function remoteStatus(AppConfig $config, ?string $profileFlag): array
    {
        /** @var array<string, string> $env */
        $env = getenv();
        $choice = ProfileResolver::resolve($profileFlag, $env, $config, new LocalState($this->projectDir()));
        if ($choice === null) {
            return [null, null];
        }
        try {
            return [($this->deployerFactory)($this->guardedClients($config, $choice->profile))->describe($config), null];
        } catch (UserError $e) {
            return [null, $e->getMessage()];
        } catch (Throwable $e) {
            return [null, $this->credentialProblem($e, $choice->profile)];
        }
    }

    private function profileFlag(InputInterface $input): ?string
    {
        $flag = $input->getOption('profile');
        return is_string($flag) && $flag !== '' ? $flag : null;
    }

    /**
     * Clients for the profile, after the same account guard as deploy: an environment locked with
     * account() is never read through a profile of another account. Without account() no STS call is made.
     *
     * @throws UserError account_mismatch
     */
    private function guardedClients(AppConfig $config, ?string $profile): AwsClients
    {
        $clients = ($this->awsFactory)($profile, (string) $config->region);
        if ($config->account !== null) {
            $this->guardAccount($config, $clients->accountId(), $profile);
        }
        return $clients;
    }

    private function runtimeBuildLabel(AppConfig $config): ?string
    {
        if ($config->runtime->binary !== null) {
            return null;
        }
        try {
            $build = RuntimeManifest::load()->resolve($config->runtime->build, $config->runtime->frankenphp, $config->runtime->php);
        } catch (BinaryException) {
            return null;
        }
        return "{$build->id} (FrankenPHP {$build->frankenphp}, PHP {$build->php})";
    }

    private function runtimeBuildSource(AppConfig $config): string
    {
        $s = $config->sources;
        return match (true) {
            $s['runtime.build'] !== 'default' => $s['runtime.build'],
            $s['runtime.frankenphp'] !== 'default' => $s['runtime.frankenphp'],
            $s['runtime.php'] !== 'default' => $s['runtime.php'],
            default => 'default',
        };
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    private function settings(AppConfig $config): array
    {
        $s = $config->sources;
        return [
            'region' => [$config->region, 'app'],
            'account' => [$config->account, $s['account']],
            'profile' => [$config->profile, $s['profile']],
            'concurrency' => [$config->concurrency, $s['concurrency']],
            'timeout' => [$config->timeout, $s['timeout']],
            'maxInstances' => [$config->maxInstances, $s['maxInstances']],
            'rateLimit' => [$config->rateLimit, $s['rateLimit']],
            'auth' => [$config->auth, $s['auth']],
            'logLevel' => [$config->logLevel, $s['logLevel']],
            // Machine values, as arco.php writes them (--json); display() words them for the table.
            'console' => [$config->consoleEnabled() ? true : $config->console, $s['console']],
            'scheduler' => [$config->scheduler, $s['scheduler']],
            'logRetention' => [$config->logRetention, $s['logRetention']],
            'consoleTimeout' => [$config->consoleTimeout, $s['consoleTimeout']],
            'protected' => [$config->isProtected(), $s['protected']],
            'deployCommands' => [$config->deployCommands === [] ? null : implode('; ', $config->deployCommands), $s['deployCommands']],
            'permissions' => [count($config->permissions), $config->permissions === [] ? 'default' : 'app/environment'],
            'permissionsBoundary' => [$config->permissionsBoundary, $s['permissionsBoundary']],
            'vpc' => [$config->vpc, $s['vpc']],
            'domains' => [$config->domains === [] ? null : implode(', ', $config->domains), $s['domains']],
            'dns' => [$config->domains === [] ? null : $this->dnsLabel($config), $s['dns']],
            'certificate' => [$config->certificate, $s['certificate']],
            'runtime.build' => [$this->runtimeBuildLabel($config), $this->runtimeBuildSource($config)],
            'runtime.binary' => [$config->runtime->binary, $s['runtime.binary']],
            'runtime.workers' => [$config->runtime->workers, $s['runtime.workers']],
            'runtime.maxThreads' => [$config->runtime->maxThreads, $s['runtime.maxThreads']],
            'runtime.threads' => [$config->runtime->threads, $s['runtime.threads']],
            'runtime.mode' => [$config->runtime->mode, $s['runtime.mode']],
        ];
    }

    /** Where the domains' DNS records go, as a machine value: cloudflare, route53 (<profile>) or manual. */
    private static function dnsProvider(AppConfig $config): ?string
    {
        return match (true) {
            $config->domains === [] => null,
            $config->dnsCloudflare => 'cloudflare',
            $config->dnsManual => 'manual',
            default => 'route53 (' . ($config->dnsProfile ?? 'deploy account') . ')',
        };
    }

    private function dnsLabel(AppConfig $config): string
    {
        return match (true) {
            $config->dnsCloudflare => 'cloudflare',
            $config->dnsManual => $this->t('info.dns_manual'),
            $config->dnsProfile !== null => $config->dnsProfile,
            default => $this->t('info.dns_deploy_account'),
        };
    }

    /**
     * The machine values, with schedulerPaused after scheduler: true/false from the rule, null when
     * there is no scheduler, no rule or it could not be read.
     *
     * @param array<string, array{0: mixed, 1: string}> $settings
     * @return array<string, mixed>
     */
    private function jsonSettings(array $settings, ?AppStatus $status): array
    {
        $json = [];
        foreach ($settings as $key => $row) {
            // The top-level permissions_boundary carries it, so `settings` keeps the keys it had.
            if ($key === 'permissionsBoundary' || $key === 'vpc') {
                continue;
            }
            $json[$key] = $row[0];
            if ($key === 'scheduler') {
                $json['schedulerPaused'] = $row[0] === null ? null : $status?->schedulerPaused;
            }
        }
        return $json;
    }

    /** @param list<array{service: string, id: ?string, arco: bool, environments: ?int, route_tables: list<string>, missing_route_tables: list<string>}> $endpoints */
    private function endpointsLabel(array $endpoints): string
    {
        return implode(' · ', array_map(function (array $endpoint): string {
            $params = ['service' => $endpoint['service'], 'id' => (string) $endpoint['id'], 'count' => $endpoint['environments'] ?? 0];
            $label = match (true) {
                $endpoint['id'] === null => $this->t('info.vpc_endpoint_none', $params),
                ! $endpoint['arco'] => $this->t('info.vpc_endpoint_yours', $params),
                $endpoint['environments'] === 1 => $this->t('info.vpc_endpoint_arco_one', $params),
                default => $this->t('info.vpc_endpoint_arco', $params),
            };
            return $endpoint['missing_route_tables'] === [] ? $label : $label . ' ' . $this->t('info.vpc_endpoint_missing', ['route_tables' => implode(', ', $endpoint['missing_route_tables'])]);
        }, $endpoints));
    }

    private function display(string $key, mixed $value, ?AppStatus $status = null): string
    {
        return match (true) {
            $key === 'scheduler' && is_int($value) && $status?->schedulerPaused === true => $this->t('scheduler.every_paused', ['minutes' => $value]),
            $key === 'console' => $this->t(match ($value) {
                true => 'console.on',
                false => 'console.off',
                default => 'info.console_not_set',
            }),
            $key === 'scheduler' => is_int($value) ? $this->t('scheduler.every', ['minutes' => $value]) : $this->t('scheduler.off'),
            $key === 'logRetention' => match (true) {
                $value === null => $this->t('info.retention_default'),
                $value === false => $this->t('logs.retention_never'),
                $value === 1 => $this->t('logs.retention_one_day'),
                default => $this->t('logs.retention_days', ['days' => is_int($value) ? $value : 0]),
            },
            $key === 'vpc' && $value instanceof VpcConfig && $status?->vpc !== null => $this->t('info.vpc_connector', [
                'vpc' => $this->t('info.vpc', ['subnets' => implode(', ', $value->subnets), 'groups' => implode(', ', $value->securityGroups)]),
                ...$status->vpc,
            ]),
            $key === 'vpc' && $value instanceof VpcConfig => $this->t('info.vpc', ['subnets' => implode(', ', $value->subnets), 'groups' => implode(', ', $value->securityGroups)]),
            is_bool($value) => $this->t($value ? 'common.yes' : 'common.no'),
            is_scalar($value) => (string) $value,
            default => '-',
        };
    }
}
