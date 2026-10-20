<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

trait ConfiguresDeployment
{
    /** @var array<string, string|int|bool|array<string, string>> */
    private array $settings = [];
    /** @var array<string, string> */
    private array $variables = [];
    private ?Runtime $runtime = null;
    /** @var list<array<string, mixed>> */
    private array $permissions = [];
    /** @var list<string>|null */
    private ?array $domains = null;
    /** @var list<string>|null */
    private ?array $deployCommands = null;
    /** @var array<string, array<string, mixed>|false> */
    private array $queues = [];
    /** @var array<string, array<string, mixed>|false> */
    private array $storage = [];

    public function concurrency(int $requests): static { $this->settings['concurrency'] = $requests; return $this; }
    public function timeout(int $seconds): static { $this->settings['timeout'] = $seconds; return $this; }
    /** Upper bound on running instances (Lambda execution environments) of the function. */
    public function maxInstances(int $count): static { $this->settings['maxInstances'] = $count; return $this; }
    public function rateLimit(int $requestsPerSecond): static { $this->settings['rateLimit'] = $requestsPerSecond; return $this; }
    /** @param 'public'|'iam' $auth */
    public function auth(string $auth): static { $this->settings['auth'] = $auth; return $this; }
    public function logLevel(string $level): static { $this->settings['logLevel'] = $level; return $this; }
    public function role(string $roleArn): static { $this->settings['role'] = $roleArn; return $this; }
    public function kmsKey(string $kmsKeyArn): static { $this->settings['kmsKey'] = $kmsKeyArn; return $this; }
    public function profile(string $awsProfile): static { $this->settings['profile'] = $awsProfile; return $this; }

    /**
     * Custom domains served through CloudFront. Calls add to the list; an environment's list
     * replaces the app's list as a whole.
     *
     * @param list<string>|string $domains
     */
    public function domain(array|string $domains): static
    {
        $this->domains = [...($this->domains ?? []), ...(is_string($domains) ? [$domains] : $domains)];
        return $this;
    }

    /**
     * Where the DNS records go: the name of an AWS profile whose Route 53 holds the zones, or false when DNS is
     * not in Route 53 (Arco prints the records to create). Default: Route 53 in the deploy account.
     */
    public function dns(string|false $dns): static { $this->settings['dns'] = $dns; return $this; }

    /** An ACM certificate in us-east-1 to use instead of looking one up or requesting one. */
    public function certificate(string $certificateArn): static { $this->settings['certificate'] = $certificateArn; return $this; }

    /**
     * Commands run on the console function during every deploy, after the new code is uploaded and
     * before traffic moves to it (e.g. 'migrate --force'). An environment's list replaces the app's.
     *
     * @param list<string> $commands
     */
    public function deployCommands(array $commands): static
    {
        $this->deployCommands = $commands;
        return $this;
    }

    /**
     * The console function (arco command, deploy commands, tinker, the scheduler). Off unless set;
     * scheduler() turns it on. Calling console() with any option also turns it on.
     *
     * @param int|null $timeout seconds a command may run, 3 to 900 (default 900)
     * @param int|null $memory MB of memory, 128 to 10240 (default 2048)
     */
    public function console(bool $enabled = true, ?int $timeout = null, ?int $memory = null): static
    {
        $this->settings['console'] = $enabled;
        if ($timeout !== null) {
            $this->settings['consoleTimeout'] = $timeout;
        }
        if ($memory !== null) {
            $this->settings['consoleMemory'] = $memory;
        }
        return $this;
    }

    /**
     * Run `schedule:run` every N minutes (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30 or 60) from an
     * EventBridge schedule; false turns it off. Needs the console function.
     */
    public function scheduler(int|false $every = 1): static { $this->settings['scheduler'] = $every; return $this; }

    /** Days the function's CloudWatch logs are kept (a value CloudWatch accepts); false keeps them forever. */
    public function logRetention(int|false $days): static { $this->settings['logRetention'] = $days; return $this; }

    /** Ask for confirmation before `arco tinker` (on by default for an environment named production). */
    public function protected(bool $protected = true): static { $this->settings['protected'] = $protected; return $this; }

    /**
     * An SQS queue with a worker function that consumes it. Options not given keep their defaults
     * (timeout 60, memory 1024, batchSize 1, tries 3, dlq on); repeated calls for a name accumulate.
     * An environment's entry merges over the app's; `queue('name', false)` drops the queue there.
     *
     * @param int|null $maxInstances most concurrent worker invocations (the event source mapping's MaximumConcurrency, at least 2)
     * @param string|null $existing name, URL or ARN of a queue Arco does not manage
     * @param int|null $concurrency deprecated: the old name of $maxInstances; removed in 1.0
     */
    public function queue(
        string $name,
        false|null $remove = null,
        ?int $timeout = null,
        ?int $memory = null,
        ?int $maxInstances = null,
        ?int $batchSize = null,
        ?int $tries = null,
        ?bool $dlq = null,
        ?bool $fifo = null,
        ?string $existing = null,
        ?bool $default = null,
        ?int $concurrency = null,
    ): static {
        if ($remove === false) {
            $this->queues[$name] = false;
            return $this;
        }
        $options = array_filter(
            ['timeout' => $timeout, 'memory' => $memory, 'maxInstances' => $maxInstances, 'concurrency' => $concurrency, 'batchSize' => $batchSize,
                'tries' => $tries, 'dlq' => $dlq, 'fifo' => $fifo, 'existing' => $existing, 'default' => $default],
            fn (mixed $value): bool => $value !== null,
        );
        $current = $this->queues[$name] ?? [];
        $this->queues[$name] = [...(is_array($current) ? $current : []), ...$options];
        return $this;
    }

    /**
     * An S3 bucket, named <app>-<env>-<name>-<account>. Options not given keep their defaults (private,
     * no expiry, no CORS); repeated calls for a name accumulate. An environment's entry merges over the
     * app's; `storage('name', false)` drops the bucket there.
     *
     * @param list<string>|null $public key prefixes anyone can read, each ending in "/"
     * @param array<string, int>|null $expire key prefix (ending in "/") => days before its objects are deleted
     * @param list<string>|null $cors origins allowed from a browser ("https://example.com" or "*")
     * @param string|null $existing name of a bucket Arco does not manage
     */
    public function storage(
        string $name,
        false|null $remove = null,
        ?array $public = null,
        ?array $expire = null,
        ?array $cors = null,
        ?string $existing = null,
        ?bool $default = null,
    ): static {
        if ($remove === false) {
            $this->storage[$name] = false;
            return $this;
        }
        $options = array_filter(
            ['public' => $public, 'expire' => $expire, 'cors' => $cors, 'existing' => $existing, 'default' => $default],
            fn (mixed $value): bool => $value !== null,
        );
        $current = $this->storage[$name] ?? [];
        $this->storage[$name] = [...(is_array($current) ? $current : []), ...$options];
        return $this;
    }

    /**
     * A DynamoDB table for Laravel's cache (<app>-<env>-cache); `existing` names a table Arco does not
     * manage; false turns it off.
     *
     * @param string $driver where the cache lives; only 'dynamodb' exists today (the parameter keeps room for others)
     */
    public function cache(bool $enabled = true, ?string $existing = null, string $driver = 'dynamodb'): static
    {
        $this->settings['cache'] = self::tableSetting($enabled, $existing);
        if ($driver !== 'dynamodb') {
            $this->settings['cacheDriver'] = $driver;
        }
        return $this;
    }

    /**
     * A DynamoDB table for Laravel's failed jobs (<app>-<env>-failed-jobs); `existing` names a table
     * Arco does not manage; false turns it off.
     */
    public function failedJobsTable(bool $enabled = true, ?string $existing = null): static
    {
        $this->settings['failedJobsTable'] = self::tableSetting($enabled, $existing);
        return $this;
    }

    /** @return bool|array{existing: string} */
    private static function tableSetting(bool $enabled, ?string $existing): bool|array
    {
        if ($existing !== null && $enabled) {
            return ['existing' => $existing];
        }
        return $enabled;
    }

    /** @param array<string, string> $variables */
    public function variables(array $variables): static
    {
        $this->variables = [...$this->variables, ...$variables];
        return $this;
    }

    /**
     * Extra IAM statements for the execution role Arco manages, e.g. access to an existing
     * bucket or table. App and environment statements are combined.
     *
     * @param list<array<string, mixed>> $statements
     */
    public function permissions(array $statements): static
    {
        $this->permissions = [...$this->permissions, ...$statements];
        return $this;
    }

    /** @param callable(Runtime): mixed $configure */
    public function runtime(callable $configure): static
    {
        $this->runtime ??= new Runtime();
        $configure($this->runtime);
        return $this;
    }

    /** @return array{settings: array<string, string|int|bool|array<string, string>>, variables: array<string, string>, runtime: array<string, mixed>, permissions: list<array<string, mixed>>, domains: list<string>|null, deployCommands: list<string>|null, queues: array<string, array<string, mixed>|false>, storage: array<string, array<string, mixed>|false>} */
    protected function deploymentArray(): array
    {
        return [
            'settings' => $this->settings,
            'variables' => $this->variables,
            'runtime' => $this->runtime?->toArray() ?? [],
            'permissions' => $this->permissions,
            'domains' => $this->domains,
            'deployCommands' => $this->deployCommands,
            'queues' => $this->queues,
            'storage' => $this->storage,
        ];
    }
}
