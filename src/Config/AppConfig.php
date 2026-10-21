<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

final readonly class AppConfig
{
    /**
     * @param array<string, string> $variables
     * @param list<string> $exclude
     * @param list<string> $include
     * @param array<string, string> $sources
     * @param list<array<string, mixed>> $permissions
     * @param list<string> $domains
     * @param list<string> $deployCommands
     * @param list<QueueConfig> $queues
     * @param list<StorageConfig> $storage
     * @param list<GenericTableConfig> $tables
     */
    public function __construct(
        public string $app,
        public string $environment,
        public ?string $region,
        /** null: AWS's default (64) with its dynamic per-instance limit */
        public ?int $concurrency,
        public int $timeout,
        public ?int $maxInstances,
        public ?int $rateLimit,
        public string $auth,
        public string $logLevel,
        public ?string $role,
        public ?string $kmsKey,
        public ?string $profile,
        public ?string $account,
        public RuntimeConfig $runtime,
        public array $variables,
        public array $exclude,
        public array $include,
        public array $sources,
        public array $permissions = [],
        public array $domains = [],
        public ?string $dnsProfile = null,
        public bool $dnsManual = false,
        public ?string $certificate = null,
        public array $deployCommands = [],
        public int $consoleTimeout = 900,
        public int $consoleMemory = 2048,
        /** null: protected when the environment is named production */
        public ?bool $protected = null,
        /** null: never set (the console function is off unless a scheduler needs it) */
        public ?bool $console = null,
        /** minutes between `schedule:run` invocations; null: no scheduler */
        public ?int $scheduler = null,
        /** days to keep logs; false: never expire; null: Arco does not manage retention */
        public int|false|null $logRetention = null,
        /** declaration order, app level first */
        public array $queues = [],
        /** declaration order, app level first */
        public array $storage = [],
        /** null: no cache table */
        public ?TableConfig $cacheTable = null,
        /** null: no failed-jobs table */
        public ?TableConfig $failedJobsTable = null,
        /** cache(driver:) when it is not 'dynamodb' (the validator refuses it); null: dynamodb */
        public ?string $cacheDriver = null,
        /** dns('cloudflare'): the custom domains' zones are on Cloudflare ($dnsProfile is then null) */
        public bool $dnsCloudflare = false,
        /** table() entries, declaration order, app level first */
        public array $tables = [],
    ) {
    }

    /** The same configuration with the account the credentials resolved to (arco.php did not lock one). */
    public function withAccount(string $account): self
    {
        return new self(
            app: $this->app,
            environment: $this->environment,
            region: $this->region,
            concurrency: $this->concurrency,
            timeout: $this->timeout,
            maxInstances: $this->maxInstances,
            rateLimit: $this->rateLimit,
            auth: $this->auth,
            logLevel: $this->logLevel,
            role: $this->role,
            kmsKey: $this->kmsKey,
            profile: $this->profile,
            account: $account,
            runtime: $this->runtime,
            variables: $this->variables,
            exclude: $this->exclude,
            include: $this->include,
            sources: $this->sources,
            permissions: $this->permissions,
            domains: $this->domains,
            dnsProfile: $this->dnsProfile,
            dnsManual: $this->dnsManual,
            certificate: $this->certificate,
            deployCommands: $this->deployCommands,
            consoleTimeout: $this->consoleTimeout,
            consoleMemory: $this->consoleMemory,
            protected: $this->protected,
            console: $this->console,
            scheduler: $this->scheduler,
            logRetention: $this->logRetention,
            queues: $this->queues,
            storage: $this->storage,
            cacheTable: $this->cacheTable,
            failedJobsTable: $this->failedJobsTable,
            cacheDriver: $this->cacheDriver,
            dnsCloudflare: $this->dnsCloudflare,
            tables: $this->tables,
        );
    }

    public function functionName(): string
    {
        return $this->app . '-' . $this->environment;
    }

    public function consoleFunctionName(): string
    {
        return $this->functionName() . '-console';
    }

    public function workerFunctionName(string $queue): string
    {
        return self::queueWorkerName($this->app, $this->environment, $queue);
    }

    /** The name of a queue's worker function: <app>-<environment>-queue-<queue>. */
    public static function queueWorkerName(string $app, string $environment, string $queue): string
    {
        return $app . '-' . $environment . '-queue-' . $queue;
    }

    public function defaultQueue(): ?QueueConfig
    {
        foreach ($this->queues as $queue) {
            if ($queue->default) {
                return $queue;
            }
        }
        return null;
    }

    public function defaultStorage(): ?StorageConfig
    {
        foreach ($this->storage as $storage) {
            if ($storage->default) {
                return $storage;
            }
        }
        return null;
    }

    /** Whether the console function is deployed: asked for, or needed by the scheduler. */
    public function consoleEnabled(): bool
    {
        return $this->console === true || $this->scheduler !== null;
    }

    /** Whether `arco tinker` asks for confirmation first. */
    public function isProtected(): bool
    {
        return $this->protected ?? $this->environment === 'production';
    }
}
