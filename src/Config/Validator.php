<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

use Multiapi\Arco\Arco;
use Multiapi\Arco\Binary\BinaryException;
use Multiapi\Arco\Binary\ElfInspector;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Build\RunsCommands;
use Multiapi\Arco\ConsoleRuntime\ArgumentSplitter;
use Multiapi\Arco\Secrets\InvalidReference;
use Multiapi\Arco\Secrets\SecretReference;

final class Validator
{
    public const NAME = '/^[a-z][a-z0-9-]*$/';

    /**
     * Endings of the names Arco derives from <app>-<environment> for its other resources (console
     * function, resources stack, scheduler rule, CloudFront function); `-queue-` marks a worker.
     */
    public const RESERVED_SUFFIXES = ['-console', '-resources', '-scheduler', '-host'];

    private const VARIABLE = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const SECRET_LIKE = '/(_KEY|_SECRET|_PASSWORD|_TOKEN)$/';
    /** Disks Laravel's stock config/filesystems.php defines: a storage cannot take their names. */
    private const LARAVEL_DISKS = ['s3', 'public', 'local'];
    /** Stands for the account's 12 digits in bucket names checked before the credentials resolve it. */
    private const ACCOUNT_PLACEHOLDER = '123456789012';
    private const LOG_LEVELS = ['trace', 'debug', 'info', 'warn', 'error', 'fatal'];
    /** Environment variables Lambda sets itself and refuses in function configuration. */
    private const RESERVED = [
        '_HANDLER', '_X_AMZN_TRACE_ID', 'AWS_DEFAULT_REGION', 'AWS_REGION', 'AWS_EXECUTION_ENV',
        'AWS_ACCESS_KEY', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SECRET_KEY',
        'AWS_SESSION_TOKEN', 'LAMBDA_TASK_ROOT', 'LAMBDA_RUNTIME_DIR',
    ];
    private const RESERVED_PREFIXES = ['AWS_LAMBDA_', 'AWS_XRAY_'];
    /** Every variable starting with this belongs to Arco (the ones it sets, and the ones later versions may add). */
    private const ARCO_PREFIX = 'ARCO';
    private const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';
    private const CERTIFICATE = '/^arn:aws[a-z-]*:acm:us-east-1:\d{12}:certificate\/[A-Za-z0-9-]+$/';
    public const MAX_DOMAINS = 10;
    /** The worker keeps 3 s to answer for its batch and skips a job with less than 4 s left: below 10 s jobs barely run. */
    public const MIN_QUEUE_TIMEOUT = 10;
    /** Minutes between schedule:run invocations: the divisors of an hour that an EventBridge rate or cron expresses. */
    public const SCHEDULER_INTERVALS = SchedulerInterval::ALLOWED;
    /** Retention periods (days) CloudWatch Logs accepts. */
    public const LOG_RETENTION_DAYS = [1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922, 3288, 3653];
    /**
     * Artisan commands that write into the code directory (bootstrap/cache, public/storage), which is
     * read-only on Lambda (/var/task): they throw. view:cache works (views compile under /tmp).
     */
    private const WRITES_CODE_DIRECTORY = ['config:cache', 'route:cache', 'event:cache', 'optimize', 'storage:link'];
    /**
     * Artisan commands whose deletes in the read-only code directory fail silently: they exit 0 and
     * change nothing (optimize:clear's cache:clear and view:clear steps are the exception).
     */
    private const NO_EFFECT = ['config:clear', 'route:clear', 'event:clear', 'optimize:clear'];
    /** Artisan commands that ask for confirmation in production (ConfirmableTrait) unless given --force. */
    private const CONFIRMS_IN_PRODUCTION = [
        'migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback',
        'db:seed', 'db:wipe', 'key:generate', 'queue:clear',
    ];
    /** Artisan commands that drop every table. */
    private const DESTROYS_DATA = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'];

    /** @param PresetRegistry|null $presets detects the app's preset for the deploy command checks */
    public function __construct(private readonly ?RuntimeManifest $manifest = null, private readonly ?PresetRegistry $presets = null)
    {
    }

    /**
     * App and environment names that would collide with a name Arco gives another resource of some
     * app and environment: ending in a reserved suffix, or containing `-queue-` (also across the
     * hyphen that joins them).
     */
    private static function addReservedNameErrors(AppConfig $config, ValidationResult $result): void
    {
        $queue = false;
        $suffixed = false;
        foreach (['app' => $config->app, 'environment' => $config->environment] as $kind => $name) {
            foreach (self::RESERVED_SUFFIXES as $suffix) {
                if (str_ends_with($name, $suffix)) {
                    $result->error("validation.{$kind}_name_suffix", ['name' => $name, 'suffix' => $suffix]);
                    $suffixed = true;
                }
            }
            if (str_contains($name, '-queue-')) {
                $result->error("validation.{$kind}_name_queue", ['name' => $name]);
                $queue = true;
            }
        }
        // App `shop-eu` + environment `console` is the console function of app `shop`, environment `eu`;
        // app `shop` + environment `console` is no one's (`shop` alone is no <app>-<environment>).
        foreach ($suffixed ? [] : self::RESERVED_SUFFIXES as $suffix) {
            $rest = substr($config->functionName(), 0, -strlen($suffix));
            if (str_ends_with($config->functionName(), $suffix) && str_contains($rest, '-')) {
                $result->error('validation.function_name_suffix', ['app' => $config->app, 'environment' => $config->environment, 'function' => $config->functionName(), 'suffix' => $suffix]);
            }
        }
        if (! $queue && str_contains($config->functionName(), '-queue-')) {
            $result->error('validation.function_name_queue', ['app' => $config->app, 'environment' => $config->environment, 'function' => $config->functionName()]);
        }
    }

    public function validate(AppConfig $config, string $projectDir): ValidationResult
    {
        $result = new ValidationResult();

        if (! preg_match(self::NAME, $config->app)) {
            $result->error('validation.app_name', ['name' => $config->app]);
        }
        if (! preg_match(self::NAME, $config->environment)) {
            $result->error('validation.environment_name', ['name' => $config->environment]);
        }
        self::addReservedNameErrors($config, $result);
        if (strlen($config->functionName()) > 64) {
            $result->error('validation.function_name_length', ['name' => $config->functionName()]);
        } elseif (strlen($config->consoleFunctionName()) > 64) {
            $result->error('validation.console_function_name_length', ['name' => $config->consoleFunctionName()]);
        }
        if ($config->region === null) {
            $result->error('validation.region_missing');
        } elseif (! in_array($config->region, Arco::REGIONS, true)) {
            $result->error('validation.region', ['region' => $config->region, 'regions' => implode(', ', Arco::REGIONS)]);
        }
        $limits = ['concurrency' => $config->concurrency, 'timeout' => $config->timeout, 'maxInstances' => $config->maxInstances, 'rateLimit' => $config->rateLimit, 'consoleTimeout' => $config->consoleTimeout, 'consoleMemory' => $config->consoleMemory];
        foreach ($limits as $setting => $value) {
            self::addSettingError($result, $setting, $value);
        }
        if (! in_array($config->auth, ['public', 'iam'], true)) {
            $result->error('validation.auth', ['value' => $config->auth]);
        }
        if ($config->cacheDriver !== null) {
            $result->error('validation.cache_driver', ['value' => $config->cacheDriver]);
        }
        self::addSettingError($result, 'logLevel', $config->logLevel);
        self::addSettingError($result, 'scheduler', $config->scheduler);
        self::addSettingError($result, 'logRetention', $config->logRetention === false ? null : $config->logRetention);
        if ($config->scheduler !== null && $config->console === false) {
            $result->error('validation.scheduler_needs_console');
        }
        if ($config->account !== null && ! preg_match('/^\d{12}$/', $config->account)) {
            $result->error('validation.account', ['value' => $config->account]);
        }

        $runtime = $config->runtime;
        if ($runtime->workers < 1 || $runtime->maxThreads < $runtime->workers) {
            $result->error('validation.workers', ['workers' => $runtime->workers, 'maxThreads' => $runtime->maxThreads]);
        }
        if ($runtime->deprecatedWorkers) {
            $result->warning('deprecated.workers_min_max');
        }
        if ($runtime->threads < 1) {
            $result->error('validation.threads', ['value' => $runtime->threads]);
        }
        foreach ($runtime->phpIni as $key => $value) {
            if (! preg_match('/^[A-Za-z0-9_.-]+$/', (string) $key)) {
                $result->error('validation.php_ini_key', ['key' => (string) $key]);
            } elseif (is_string($value) && preg_match('/[\r\n\0]/', $value)) {
                $result->error('validation.php_ini_value', ['key' => (string) $key]);
            }
        }
        if (! in_array($runtime->mode, ['direct', 'proxy'], true)) {
            $result->error('validation.mode', ['value' => $runtime->mode]);
        }
        if ($runtime->binary !== null) {
            if ($runtime->build !== null || $runtime->frankenphp !== null || $runtime->php !== null) {
                $result->warning('validation.binary_overrides_build');
            }
            $path = str_starts_with($runtime->binary, '/') ? $runtime->binary : $projectDir . '/' . $runtime->binary;
            if (! is_file($path)) {
                $result->error('validation.binary_missing', ['path' => $runtime->binary]);
            } elseif (! ElfInspector::isLinuxArm64($path)) {
                $result->error('validation.binary_arch', ['path' => $runtime->binary]);
            }
            if ($runtime->phpBinary !== null) {
                $php = str_starts_with($runtime->phpBinary, '/') ? $runtime->phpBinary : $projectDir . '/' . $runtime->phpBinary;
                if (! is_file($php)) {
                    $result->error('validation.php_binary_missing', ['path' => $runtime->phpBinary]);
                } elseif (! ElfInspector::isLinuxArm64($php)) {
                    $result->error('validation.php_binary_arch', ['path' => $runtime->phpBinary]);
                }
            }
        } else {
            try {
                ($this->manifest ?? RuntimeManifest::load())->resolve($runtime->build, $runtime->frankenphp, $runtime->php);
            } catch (BinaryException $e) {
                $result->error($e->key, $e->params);
            }
        }

        foreach ($config->permissions as $index => $statement) {
            $field = self::invalidStatementField($statement);
            if ($field !== null) {
                $result->error('validation.permission', ['index' => $index, 'field' => $field]);
            }
        }
        if ($config->permissions !== [] && $config->role !== null) {
            $result->warning('validation.permissions_with_role');
        }

        self::validateDomains($config, $result);
        self::validateQueues($config, $result);
        self::validateStorage($config, $result);
        foreach ([$config->cacheTable, $config->failedJobsTable] as $table) {
            if ($table?->existing !== null && ! TableConfig::isTableName($table->existing)) {
                $result->error('validation.table_existing', ['table' => $table->kind, 'value' => $table->existing]);
            }
        }

        // The script every command goes through (artisan), as the console runtime runs it: php <entry> <args>.
        $entry = null;
        if ($config->deployCommands !== []) {
            $preset = ($this->presets ?? PresetRegistry::default())->detect($projectDir);
            $entry = $preset instanceof RunsCommands ? $preset->commandEntry() : null;
        }
        foreach ($config->deployCommands as $index => $command) {
            if (trim($command) === '') {
                $result->error('validation.deploy_command_empty', ['index' => $index]);
                continue;
            }
            try {
                $arguments = ArgumentSplitter::split($command);
            } catch (\InvalidArgumentException $e) {
                $result->error('validation.deploy_command', ['command' => $command, 'reason' => $e->getMessage()]);
                continue;
            }
            if ($entry !== null) {
                self::addDeployCommandWarnings($result, $command, $arguments, $entry);
            }
        }

        foreach ($config->variables as $name => $value) {
            if (str_starts_with($name, self::ARCO_PREFIX)) {
                $result->error('validation.reserved_arco_variable', ['variable' => $name]);
                continue;
            }
            if (self::isReserved($name)) {
                $result->error('validation.reserved_variable', ['variable' => $name]);
                continue;
            }
            if (! preg_match(self::VARIABLE, $name)) {
                $result->error('validation.variable_name', ['variable' => $name]);
                continue;
            }
            if ($value === '') {
                $result->warning('validation.empty_variable', ['variable' => $name]);
                continue;
            }
            if (SecretReference::isReference($value)) {
                try {
                    SecretReference::parse($value);
                } catch (InvalidReference $e) {
                    $result->error('validation.invalid_reference', ['variable' => $name, 'reason' => $e->getMessage()]);
                }
                continue;
            }
            if (preg_match(self::SECRET_LIKE, $name)) {
                $result->warning('validation.plaintext_secret', ['variable' => $name]);
            }
        }

        return $result;
    }

    /**
     * The error for one scalar setting value (concurrency, timeout, maxInstances, rateLimit,
     * logLevel), or null when it is valid. Used by validate() and by `arco set` before writing.
     *
     * @return array{key: string, params: array<string, string|int>}|null
     */
    public static function settingError(string $setting, int|string $value): ?array
    {
        [$key, $ok] = match ($setting) {
            'concurrency' => ['validation.concurrency', is_int($value) && $value >= 1 && $value <= 128],
            'timeout' => ['validation.timeout', is_int($value) && $value >= 3 && $value <= 900],
            // AWS limits: maxEnvironments 2–10,000; rateLimit a multiple of 100 up to 1,000, then a
            // multiple of 1,000 up to 10,000 (0 would block all traffic, so Arco does not accept it).
            'maxInstances' => ['validation.max_instances', is_int($value) && $value >= 2 && $value <= 10000],
            'rateLimit' => ['validation.rate_limit', is_int($value) && $value >= 100 && $value <= 10000
                && $value % ($value <= 1000 ? 100 : 1000) === 0],
            'consoleTimeout' => ['validation.console_timeout', is_int($value) && $value >= 3 && $value <= 900],
            'consoleMemory' => ['validation.console_memory', is_int($value) && $value >= 128 && $value <= 10240],
            'scheduler' => ['validation.scheduler_interval', is_int($value) && in_array($value, self::SCHEDULER_INTERVALS, true)],
            'logRetention' => ['validation.log_retention', is_int($value) && in_array($value, self::LOG_RETENTION_DAYS, true)],
            'logLevel' => ['validation.log_level', in_array($value, self::LOG_LEVELS, true)],
            default => throw new \InvalidArgumentException("Unknown setting $setting."),
        };
        return $ok ? null : ['key' => $key, 'params' => ['value' => $value]];
    }

    /**
     * Warnings for deploy commands that cannot work on Lambda: the first failing command stops the deploy.
     *
     * @param list<string> $arguments
     * @param string $entry the script the runtime puts before the arguments (artisan)
     */
    private static function addDeployCommandWarnings(ValidationResult $result, string $command, array $arguments, string $entry): void
    {
        // The runtime runs `php artisan <args>`: `artisan migrate` would run `php artisan artisan migrate`
        // and fail. Warned about, not rewritten; the command after the prefix is checked as well.
        $prefix = ($arguments[0] ?? null) === 'php' ? 1 : 0;
        if (($arguments[$prefix] ?? null) === $entry) {
            $result->warning('validation.deploy_command_artisan_prefix', ['command' => $command, 'entry' => $entry]);
            $arguments = array_slice($arguments, $prefix + 1);
        }
        if ($entry !== 'artisan') {
            return;
        }
        $name = $arguments[0] ?? '';
        $params = ['command' => $command, 'name' => $name];
        if (in_array($name, self::WRITES_CODE_DIRECTORY, true)) {
            $result->warning('validation.deploy_command_read_only', $params);
        }
        if (in_array($name, self::NO_EFFECT, true)) {
            $result->warning('validation.deploy_command_no_effect', $params);
        }
        if (in_array($name, self::DESTROYS_DATA, true)) {
            $result->warning('validation.deploy_command_destroys_data', $params);
        }
        $forced = array_filter($arguments, fn (string $a): bool => $a === '--force' || str_starts_with($a, '--force='));
        if (in_array($name, self::CONFIRMS_IN_PRODUCTION, true) && $forced === []) {
            $result->warning('validation.deploy_command_needs_force', $params);
        }
    }

    private static function addSettingError(ValidationResult $result, string $setting, int|string|null $value): void
    {
        $error = $value === null ? null : self::settingError($setting, $value);
        if ($error !== null) {
            $result->error($error['key'], $error['params']);
        }
    }

    private static function validateQueues(AppConfig $config, ValidationResult $result): void
    {
        $defaults = 0;
        $deprecated = [];
        foreach ($config->queues as $queue) {
            $name = $queue->name;
            if ($queue->deprecatedConcurrency) {
                $deprecated[] = $name;
            }
            if (! preg_match(self::NAME, $name)) {
                $result->error('validation.queue_name', ['name' => $name]);
                continue;
            }
            // The dead-letter queue's name, when there is one, is the longer of the two.
            $sqsName = $queue->existing === null ? $queue->dlqName($config) ?? $queue->physicalName($config) : null;
            foreach (self::queueLengthErrors($name, $sqsName, $config->workerFunctionName($name)) as $error) {
                $result->error($error['key'], $error['params']);
            }
            if ($queue->timeout < self::MIN_QUEUE_TIMEOUT || $queue->timeout > 900) {
                $result->error('validation.queue_timeout', ['queue' => $name, 'value' => $queue->timeout]);
            }
            if ($queue->memory < 128 || $queue->memory > 10240) {
                $result->error('validation.queue_memory', ['queue' => $name, 'value' => $queue->memory]);
            }
            if ($queue->conflictingConcurrency) {
                $result->error('validation.queue_both_concurrency', ['queue' => $name]);
            }
            if ($queue->maxInstances !== null && $queue->maxInstances < 2) {
                $result->error('validation.queue_max_instances', ['queue' => $name, 'value' => $queue->maxInstances]);
            }
            $maxBatch = $queue->fifo ? 10 : 10000;
            if ($queue->batchSize < 1 || $queue->batchSize > $maxBatch) {
                $result->error('validation.queue_batch_size', ['queue' => $name, 'value' => $queue->batchSize, 'max' => $maxBatch]);
            }
            if ($queue->tries < 1) {
                $result->error('validation.queue_tries', ['queue' => $name, 'value' => $queue->tries]);
            }
            if ($queue->default) {
                $defaults++;
            }
            if ($queue->existing !== null) {
                $parsed = QueueConfig::parseExisting($queue->existing);
                if ($parsed === null) {
                    $result->error('validation.queue_existing', ['queue' => $name, 'value' => $queue->existing]);
                } elseif (str_ends_with($parsed['name'], '.fifo') !== $queue->fifo) {
                    $result->error('validation.queue_existing_fifo', ['queue' => $name, 'value' => $queue->existing]);
                }
            }
        }
        if ($deprecated !== []) {
            $result->warning('deprecated.queue_concurrency', ['queues' => implode(', ', $deprecated)]);
        }
        if ($defaults > 1) {
            $result->error('validation.queue_default_twice');
        }
        // A created queue cannot take the name of another queue's dead-letter queue: one SQS name, two resources.
        $dlqs = [];
        foreach ($config->queues as $queue) {
            $dlq = $queue->dlqName($config);
            if ($dlq !== null) {
                $dlqs[$dlq] = $queue->name;
            }
        }
        foreach ($config->queues as $queue) {
            $owner = $queue->existing === null ? ($dlqs[$queue->physicalName($config)] ?? null) : null;
            if ($owner !== null) {
                $result->error('validation.queue_name_clash', ['queue' => $queue->name, 'other' => $owner, 'name' => $queue->physicalName($config)]);
            }
        }
    }

    /**
     * Why a queue Arco would create with the default options cannot be named so in this app and
     * environment (name rule, SQS and worker function name lengths); empty when it can.
     *
     * @return list<array{key: string, params: array<string, string>}>
     */
    public static function newQueueErrors(string $app, string $environment, string $name): array
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return [['key' => 'validation.queue_name', 'params' => ['name' => $name]]];
        }
        return self::queueLengthErrors($name, QueueConfig::managedName($app, $environment, $name, dlq: true), AppConfig::queueWorkerName($app, $environment, $name));
    }

    /**
     * @param ?string $sqsName the longest SQS name Arco creates for the queue; null for an existing queue
     * @return list<array{key: string, params: array<string, string>}>
     */
    private static function queueLengthErrors(string $queue, ?string $sqsName, string $worker): array
    {
        $errors = [];
        if ($sqsName !== null && strlen($sqsName) > 80) {
            $errors[] = ['key' => 'validation.queue_name_length', 'params' => ['queue' => $queue, 'name' => $sqsName]];
        }
        if (strlen($worker) > 64) {
            $errors[] = ['key' => 'validation.worker_name_length', 'params' => ['name' => $worker]];
        }
        return $errors;
    }

    /**
     * Why a storage cannot take this name: the name rule, or one of Laravel's own disks.
     *
     * @return array{key: string, params: array<string, string>}|null
     */
    public static function storageNameError(string $name): ?array
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return ['key' => 'validation.storage_name', 'params' => ['name' => $name]];
        }
        if (in_array($name, self::LARAVEL_DISKS, true)) {
            // Laravel's own disks: arco-laravel never replaces an app-defined disk, so `public` would stay
            // the local disk under /tmp on Lambda (lost on every new instance) and `s3` the default bucket.
            return ['key' => 'validation.storage_reserved', 'params' => ['name' => $name]];
        }
        return null;
    }

    /**
     * Why a storage Arco would create cannot be named so in this app and environment (name rule, bucket name length).
     *
     * @return array{key: string, params: array<string, string>}|null
     */
    public static function newStorageError(string $app, string $environment, string $name): ?array
    {
        return self::storageNameError($name)
            ?? self::bucketLengthError($name, StorageConfig::managedBucketName($app, $environment, $name, self::ACCOUNT_PLACEHOLDER));
    }

    /** @return array{key: string, params: array<string, string>}|null */
    private static function bucketLengthError(string $storage, string $bucket): ?array
    {
        return strlen($bucket) > 63 ? ['key' => 'validation.bucket_name_length', 'params' => ['storage' => $storage, 'name' => $bucket]] : null;
    }

    private static function validateStorage(AppConfig $config, ValidationResult $result): void
    {
        $defaults = 0;
        foreach ($config->storage as $storage) {
            $name = $storage->name;
            $error = self::storageNameError($name);
            if ($error !== null) {
                $result->error($error['key'], $error['params']);
                continue;
            }
            if ($storage->existing === null) {
                // Before the credentials resolve the account, count its 12 digits anyway.
                $error = self::bucketLengthError($name, $storage->bucketName($config) . ($config->account === null ? self::ACCOUNT_PLACEHOLDER : ''));
                if ($error !== null) {
                    $result->error($error['key'], $error['params']);
                }
            } elseif (! StorageConfig::isBucketName($storage->existing)) {
                $result->error('validation.storage_existing', ['storage' => $name, 'value' => $storage->existing]);
            } else {
                // Arco manages no policy, lifecycle or CORS on a bucket it did not create. A warning, not an
                // error: an environment's existing: merged over an app-level public: is legitimate.
                $ignored = array_keys(array_filter(['public' => $storage->public, 'expire' => $storage->expire, 'cors' => $storage->cors], fn (array $v) => $v !== []));
                if ($ignored !== []) {
                    $result->warning('validation.storage_existing_options', ['storage' => $name, 'options' => implode(', ', $ignored), 'bucket' => $storage->existing]);
                }
            }
            foreach ($storage->public as $prefix) {
                if ($prefix === '' || ! str_ends_with($prefix, '/')) {
                    $result->error('validation.storage_public', ['storage' => $name, 'prefix' => $prefix]);
                } elseif (strpbrk($prefix, '*?') !== false) {
                    // The prefix becomes <bucket>/<prefix>* in the bucket policy: a wildcard would make more public.
                    $result->error('validation.storage_public_wildcard', ['storage' => $name, 'prefix' => $prefix]);
                }
            }
            foreach ($storage->expire as $prefix => $days) {
                if ($days < 1 || ! str_ends_with($prefix, '/')) {
                    $result->error('validation.storage_expire', ['storage' => $name, 'prefix' => $prefix, 'days' => $days]);
                } elseif (strpbrk($prefix, '*?') !== false) {
                    $result->error('validation.storage_expire_wildcard', ['storage' => $name, 'prefix' => $prefix]);
                }
            }
            foreach ($storage->cors as $origin) {
                if ($origin !== '*' && ! preg_match('#^https?://[^\s/]+$#', $origin)) {
                    $result->error('validation.storage_cors', ['storage' => $name, 'origin' => $origin]);
                }
            }
            if ($storage->default) {
                $defaults++;
            }
        }
        if ($defaults > 1) {
            $result->error('validation.storage_default_twice');
        }
    }

    private static function validateDomains(AppConfig $config, ValidationResult $result): void
    {
        if ($config->domains === []) {
            if ($config->dnsProfile !== null || $config->dnsManual || $config->certificate !== null) {
                $result->warning('validation.domain_settings_unused');
            }
            return;
        }
        $seen = [];
        foreach ($config->domains as $domain) {
            if (str_contains($domain, '*')) {
                $result->error('validation.domain_wildcard', ['domain' => $domain]);
            } elseif (! self::isHostname($domain)) {
                $result->error('validation.domain', ['domain' => $domain]);
            } elseif (isset($seen[$domain])) {
                $result->error('validation.domain_duplicate', ['domain' => $domain]);
            }
            $seen[$domain] = true;
        }
        if (count($config->domains) > self::MAX_DOMAINS) {
            $result->error('validation.domain_count', ['count' => count($config->domains), 'max' => self::MAX_DOMAINS]);
        }
        if ($config->auth === 'iam') {
            $result->error('validation.domain_iam');
        }
        if ($config->certificate !== null && ! preg_match(self::CERTIFICATE, $config->certificate)) {
            $result->error('validation.certificate', ['value' => $config->certificate]);
        }
    }

    /** The rules domain() values must follow: lowercase, at least two labels, no wildcard. */
    public static function isHostname(string $domain): bool
    {
        return preg_match(self::HOSTNAME, $domain) === 1;
    }

    /** Returns the first missing or malformed field of an IAM statement, or null when it is usable. */
    private static function invalidStatementField(mixed $statement): ?string
    {
        if (! is_array($statement)) {
            return 'statement';
        }
        if (! in_array($statement['Effect'] ?? null, ['Allow', 'Deny'], true)) {
            return 'Effect';
        }
        if (array_key_exists('Sid', $statement) && (! is_string($statement['Sid']) || ! preg_match('/^[A-Za-z0-9]+$/', $statement['Sid']) || str_starts_with($statement['Sid'], 'Arco'))) {
            return 'Sid';
        }
        foreach (['Action', 'Resource'] as $field) {
            $value = $statement[$field] ?? $statement["Not$field"] ?? null;
            $list = is_string($value) ? [$value] : $value;
            if (! is_array($list) || $list === [] || array_filter($list, fn ($v) => ! is_string($v) || $v === '') !== []) {
                return $field;
            }
        }
        return null;
    }

    public static function isReserved(string $name): bool
    {
        if (in_array($name, self::RESERVED, true)) {
            return true;
        }
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
