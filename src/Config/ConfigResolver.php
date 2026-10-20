<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

final class ConfigResolver
{
    private const DEFAULTS = [
        'concurrency' => null,
        'timeout' => 30,
        'maxInstances' => null,
        'rateLimit' => null,
        'auth' => 'public',
        'logLevel' => 'info',
        'role' => null,
        'kmsKey' => null,
        'profile' => null,
        'account' => null,
        'dns' => null,
        'certificate' => null,
        'consoleTimeout' => 900,
        'consoleMemory' => 2048,
        'cacheDriver' => null,
        'protected' => null,
        'console' => null,
        'scheduler' => null,
        'logRetention' => null,
        'cache' => null,
        'failedJobsTable' => null,
    ];

    private const RUNTIME_DEFAULTS = [
        'build' => null,
        'frankenphp' => null,
        'php' => null,
        'binary' => null,
        'phpBinary' => null,
        'workers' => 4,
        'maxThreads' => 64,
        'threads' => 8,
        'mode' => 'direct',
    ];

    /**
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    public static function environments(array $raw): array
    {
        $environments = $raw['environments'] ?? [];
        return is_array($environments) ? array_map('strval', array_keys($environments)) : [];
    }

    /** @param array<string, mixed> $raw */
    public static function resolve(array $raw, string $environment): AppConfig
    {
        /** @var array<string, array{settings: array<string, mixed>, variables: array<string, string>, runtime: array<string, mixed>, permissions?: list<array<string, mixed>>, domains?: list<string>|null, deployCommands?: list<string>|null, queues?: array<string, array<string, mixed>|false>, storage?: array<string, array<string, mixed>|false>}> $environments */
        $environments = $raw['environments'] ?? [];
        if (! isset($environments[$environment])) {
            throw ConfigException::make('config.unknown_environment', [
                'environment' => $environment,
                'available' => implode(', ', array_keys($environments)),
            ]);
        }
        $env = $environments[$environment];

        /** @var array<string, mixed> $appSettings */
        $appSettings = $raw['settings'] ?? [];
        /** @var array<string, mixed> $declaredRuntime */
        $declaredRuntime = $raw['runtime'] ?? [];
        $appRuntime = self::inheritedRuntime($declaredRuntime, $env['runtime']);
        /** @var array<string, string> $appVariables */
        $appVariables = $raw['variables'] ?? [];
        /** @var list<array<string, mixed>> $appPermissions */
        $appPermissions = $raw['permissions'] ?? [];

        $sources = [];
        /** @var \Closure(string): mixed $setting */
        $setting = static function (string $key) use ($appSettings, $env, &$sources): mixed {
            if (array_key_exists($key, $env['settings'])) {
                $sources[$key] = 'environment';
                return $env['settings'][$key];
            }
            if (array_key_exists($key, $appSettings)) {
                $sources[$key] = 'app';
                return $appSettings[$key];
            }
            $sources[$key] = 'default';
            return self::DEFAULTS[$key];
        };
        /** @var \Closure(string): (string|int|null) $runtimeValue */
        $runtimeValue = static function (string $key) use ($appRuntime, $env, &$sources): mixed {
            if (array_key_exists($key, $env['runtime'])) {
                $sources["runtime.$key"] = 'environment';
                return $env['runtime'][$key];
            }
            if (array_key_exists($key, $appRuntime)) {
                $sources["runtime.$key"] = 'app';
                return $appRuntime[$key];
            }
            $sources["runtime.$key"] = 'default';
            return self::RUNTIME_DEFAULTS[$key];
        };

        /** @var array<string, string|int|bool> $appIni */
        $appIni = $appRuntime['phpIni'] ?? [];
        /** @var array<string, string|int|bool> $envIni */
        $envIni = $env['runtime']['phpIni'] ?? [];

        $runtime = new RuntimeConfig(
            build: self::nullableString($runtimeValue('build')),
            frankenphp: self::nullableString($runtimeValue('frankenphp')),
            binary: self::nullableString($runtimeValue('binary')),
            workers: (int) $runtimeValue('workers'),
            maxThreads: (int) $runtimeValue('maxThreads'),
            threads: (int) $runtimeValue('threads'),
            mode: (string) $runtimeValue('mode'),
            phpIni: [...$appIni, ...$envIni],
            php: self::nullableString($runtimeValue('php')),
            phpBinary: self::nullableString($runtimeValue('phpBinary')),
            deprecatedWorkers: ($env['runtime']['deprecatedWorkers'] ?? false) === true
                || (! array_key_exists('workers', $env['runtime']) && ($appRuntime['deprecatedWorkers'] ?? false) === true),
        );

        /** @var list<string> $exclude */
        $exclude = $raw['exclude'] ?? [];
        /** @var list<string> $include */
        $include = $raw['include'] ?? [];

        /** @var list<string>|null $appDomains */
        $appDomains = $raw['domains'] ?? null;
        $envDomains = $env['domains'] ?? null;
        $sources['domains'] = $envDomains !== null ? 'environment' : ($appDomains !== null ? 'app' : 'default');
        $dns = $setting('dns');
        $certificate = self::nullableString($setting('certificate'));

        /** @var list<string>|null $appCommands */
        $appCommands = $raw['deployCommands'] ?? null;
        $envCommands = $env['deployCommands'] ?? null;
        $sources['deployCommands'] = $envCommands !== null ? 'environment' : ($appCommands !== null ? 'app' : 'default');
        /** @var array<string, array<string, mixed>|false> $appQueues */
        $appQueues = $raw['queues'] ?? [];
        $queues = self::queues($appQueues, $env['queues'] ?? []);
        $sources['queues'] = ($env['queues'] ?? []) !== [] ? 'environment' : ($appQueues !== [] ? 'app' : 'default');
        /** @var array<string, array<string, mixed>|false> $appStorage */
        $appStorage = $raw['storage'] ?? [];
        $storage = self::storage($appStorage, $env['storage'] ?? []);
        $sources['storage'] = ($env['storage'] ?? []) !== [] ? 'environment' : ($appStorage !== [] ? 'app' : 'default');
        $cacheTable = self::table('cache', $setting('cache'));
        $failedJobsTable = self::table('failed-jobs', $setting('failedJobsTable'));
        $protected = $setting('protected');
        $consoleTimeout = self::nullableInt($setting('consoleTimeout')) ?? 0;
        $consoleMemory = self::nullableInt($setting('consoleMemory')) ?? 0;
        $console = $setting('console');
        $scheduler = $setting('scheduler');
        $logRetention = $setting('logRetention');

        $config = new AppConfig(
            app: self::nullableString($raw['name'] ?? null) ?? '',
            environment: $environment,
            region: self::nullableString($raw['region'] ?? null),
            concurrency: self::nullableInt($setting('concurrency')),
            timeout: self::nullableInt($setting('timeout')) ?? 0,
            maxInstances: self::nullableInt($setting('maxInstances')),
            rateLimit: self::nullableInt($setting('rateLimit')),
            auth: self::nullableString($setting('auth')) ?? '',
            logLevel: self::nullableString($setting('logLevel')) ?? '',
            role: self::nullableString($setting('role')),
            kmsKey: self::nullableString($setting('kmsKey')),
            profile: self::nullableString($setting('profile')),
            account: self::nullableString($setting('account')),
            runtime: $runtime,
            variables: [...$appVariables, ...$env['variables']],
            exclude: $exclude,
            include: $include,
            sources: $sources,
            permissions: [...$appPermissions, ...($env['permissions'] ?? [])],
            domains: $envDomains ?? $appDomains ?? [],
            dnsProfile: is_string($dns) && $dns !== '' ? $dns : null,
            dnsManual: $dns === false,
            certificate: $certificate,
            deployCommands: $envCommands ?? $appCommands ?? [],
            consoleTimeout: $consoleTimeout,
            consoleMemory: $consoleMemory,
            protected: is_bool($protected) ? $protected : null,
            console: is_bool($console) ? $console : null,
            scheduler: $scheduler === false ? null : self::nullableInt($scheduler),
            logRetention: $logRetention === false ? false : self::nullableInt($logRetention),
            queues: $queues,
            storage: $storage,
            cacheTable: $cacheTable,
            failedJobsTable: $failedJobsTable,
            cacheDriver: self::nullableString($setting('cacheDriver')),
        );

        return $config;
    }

    /**
     * App entries with the environment's merged over them by name: `false` drops one, new names append.
     *
     * @param array<string, array<string, mixed>|false> $app
     * @param array<string, array<string, mixed>|false> $env
     * @return array<string, array<string, mixed>>
     */
    private static function mergeNamed(array $app, array $env): array
    {
        $merged = array_filter($app, 'is_array');
        foreach ($env as $name => $options) {
            $name = (string) $name;
            if ($options === false) {
                unset($merged[$name]);
            } else {
                $merged[$name] = [...($merged[$name] ?? []), ...$options];
            }
        }
        return $merged;
    }

    /**
     * One level's queue options with the deprecated `concurrency` renamed to `maxInstances`, so a value at
     * the environment level overrides the app's whichever spelling each uses. `_deprecated` and `_conflict`
     * mark the entries that used the old name, or both names, at this level.
     *
     * @param array<string, array<string, mixed>|false> $level
     * @return array<string, array<string, mixed>|false>
     */
    private static function normalizedQueues(array $level): array
    {
        foreach ($level as $name => $options) {
            if (! is_array($options) || ! array_key_exists('concurrency', $options)) {
                continue;
            }
            $options['_deprecated'] = true;
            if (array_key_exists('maxInstances', $options)) {
                $options['_conflict'] = true;
            } else {
                $options['maxInstances'] = $options['concurrency'];
            }
            unset($options['concurrency']);
            $level[$name] = $options;
        }
        return $level;
    }

    /**
     * Whether no entry asks to be the default, in which case the first one is.
     *
     * @param array<string, array<string, mixed>> $merged
     */
    private static function firstIsDefault(array $merged): bool
    {
        return array_filter($merged, fn (array $o): bool => ($o['default'] ?? false) === true) === [];
    }

    /**
     * Whether every entry says `default: false` explicitly: then none is the default (storage only; an
     * entry that does not say, or says true, keeps the usual rule).
     *
     * @param array<string, array<string, mixed>> $merged
     */
    private static function allOptOutOfDefault(array $merged): bool
    {
        return $merged !== [] && array_filter($merged, fn (array $o): bool => ($o['default'] ?? null) !== false) === [];
    }

    /**
     * App queues with the environment's merged over them by name. The first queue is the default unless one asks for it.
     *
     * @param array<string, array<string, mixed>|false> $app
     * @param array<string, array<string, mixed>|false> $env
     * @return list<QueueConfig>
     */
    private static function queues(array $app, array $env): array
    {
        $merged = self::mergeNamed(self::normalizedQueues($app), self::normalizedQueues($env));
        $firstIsDefault = self::firstIsDefault($merged);
        $queues = [];
        foreach ($merged as $name => $o) {
            $existing = isset($o['existing']) && is_scalar($o['existing']) ? (string) $o['existing'] : null;
            $queues[] = new QueueConfig(
                name: (string) $name,
                timeout: self::nullableInt($o['timeout'] ?? null) ?? 60,
                memory: self::nullableInt($o['memory'] ?? null) ?? 1024,
                maxInstances: self::nullableInt($o['maxInstances'] ?? null),
                batchSize: self::nullableInt($o['batchSize'] ?? null) ?? 1,
                tries: self::nullableInt($o['tries'] ?? null) ?? 3,
                dlq: (bool) ($o['dlq'] ?? $existing === null),
                fifo: (bool) ($o['fifo'] ?? false),
                existing: $existing,
                default: $firstIsDefault ? $queues === [] : ($o['default'] ?? false) === true,
                deprecatedConcurrency: isset($o['_deprecated']),
                conflictingConcurrency: isset($o['_conflict']),
            );
        }
        return $queues;
    }

    /**
     * App buckets with the environment's merged over them by name. The first bucket is the default unless one asks for it
     * or every bucket sets `default: false`, which leaves the app without a default disk.
     *
     * @param array<string, array<string, mixed>|false> $app
     * @param array<string, array<string, mixed>|false> $env
     * @return list<StorageConfig>
     */
    private static function storage(array $app, array $env): array
    {
        $merged = self::mergeNamed($app, $env);
        $firstIsDefault = self::firstIsDefault($merged) && ! self::allOptOutOfDefault($merged);
        $storage = [];
        foreach ($merged as $name => $o) {
            $expire = [];
            foreach (is_array($o['expire'] ?? null) ? $o['expire'] : [] as $prefix => $days) {
                $expire[(string) $prefix] = self::nullableInt($days) ?? 0;
            }
            $storage[] = new StorageConfig(
                name: (string) $name,
                public: self::stringList($o['public'] ?? null),
                expire: $expire,
                cors: self::stringList($o['cors'] ?? null),
                existing: isset($o['existing']) && is_scalar($o['existing']) ? (string) $o['existing'] : null,
                default: $firstIsDefault ? $storage === [] : ($o['default'] ?? false) === true,
            );
        }
        return $storage;
    }

    /**
     * The table a `cache` or `failedJobsTable` setting asks for: true, or ['existing' => name]; false or unset is none.
     *
     * @param 'cache'|'failed-jobs' $kind
     */
    private static function table(string $kind, mixed $value): ?TableConfig
    {
        if ($value === true) {
            return new TableConfig($kind);
        }
        if (is_array($value) && isset($value['existing']) && is_scalar($value['existing'])) {
            return new TableConfig($kind, (string) $value['existing']);
        }
        return null;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value)) : [];
    }

    /**
     * The app-level runtime values an environment inherits. The build selectors resolve per level, as
     * groups: an environment build() drops the app's php()/frankenphp(); an environment php() or
     * frankenphp() drops the app's build(); an environment binary() drops all three (binary() ignores
     * them). Within one level a mix stays, so validation rejects it.
     *
     * @param array<string, mixed> $app
     * @param array<string, mixed> $env
     * @return array<string, mixed>
     */
    private static function inheritedRuntime(array $app, array $env): array
    {
        $set = static fn (string $key): bool => ($env[$key] ?? null) !== null;
        if ($set('build') || $set('binary')) {
            unset($app['php'], $app['frankenphp']);
        }
        if ($set('php') || $set('frankenphp') || $set('binary')) {
            unset($app['build']);
        }
        return $app;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_scalar($value) ? (int) $value : null;
    }
}
