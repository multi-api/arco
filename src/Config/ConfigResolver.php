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
    ];

    private const RUNTIME_DEFAULTS = [
        'build' => null,
        'frankenphp' => null,
        'binary' => null,
        'workers' => ['min' => 4, 'max' => 64],
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
        /** @var array<string, array{settings: array<string, mixed>, variables: array<string, string>, runtime: array<string, mixed>, permissions?: list<array<string, mixed>>, domains?: list<string>|null}> $environments */
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
        /** @var array<string, mixed> $appRuntime */
        $appRuntime = $raw['runtime'] ?? [];
        /** @var array<string, string> $appVariables */
        $appVariables = $raw['variables'] ?? [];
        /** @var list<array<string, mixed>> $appPermissions */
        $appPermissions = $raw['permissions'] ?? [];

        $sources = [];
        /** @var \Closure(string): (string|int|bool|null) $setting */
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

        /** @var array{min: int, max: int} $workers */
        $workers = $runtimeValue('workers');
        /** @var array<string, string|int|bool> $appIni */
        $appIni = $appRuntime['phpIni'] ?? [];
        /** @var array<string, string|int|bool> $envIni */
        $envIni = $env['runtime']['phpIni'] ?? [];

        $runtime = new RuntimeConfig(
            build: self::nullableString($runtimeValue('build')),
            frankenphp: self::nullableString($runtimeValue('frankenphp')),
            binary: self::nullableString($runtimeValue('binary')),
            workersMin: $workers['min'],
            workersMax: $workers['max'],
            threads: (int) $runtimeValue('threads'),
            mode: (string) $runtimeValue('mode'),
            phpIni: [...$appIni, ...$envIni],
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

        $config = new AppConfig(
            app: self::nullableString($raw['name'] ?? null) ?? '',
            environment: $environment,
            region: self::nullableString($raw['region'] ?? null),
            concurrency: self::nullableInt($setting('concurrency')),
            timeout: (int) $setting('timeout'),
            maxInstances: self::nullableInt($setting('maxInstances')),
            rateLimit: self::nullableInt($setting('rateLimit')),
            auth: (string) $setting('auth'),
            logLevel: (string) $setting('logLevel'),
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
        );

        return $config;
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
