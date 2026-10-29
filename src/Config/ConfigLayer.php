<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

use JsonException;

/**
 * The optional JSON config layer (schema 1) that is merged over arco.php. This class only reads the file and
 * checks its shape: unknown keys, wrong types and a parse error are refused with a JSON pointer to the
 * culprit. Values of the right type are validated later, on the merged config. Reasons never echo values.
 */
final readonly class ConfigLayer
{
    public const OPTION = 'config-layer';
    public const ENV = 'ARCO_CONFIG_LAYER';
    /** The --config-layer help text, the same in every command that takes the option. */
    public const DESCRIPTION = 'JSON config layer merged over arco.php (or used without it); also ARCO_CONFIG_LAYER';

    private const SETTINGS = [
        'concurrency' => 'int', 'timeout' => 'int', 'maxInstances' => 'int', 'rateLimit' => 'int',
        'auth' => 'string', 'logLevel' => 'string', 'role' => 'string', 'kmsKey' => 'string', 'profile' => 'string',
        'permissionsBoundary' => 'string|false', 'dns' => 'string|false', 'certificate' => 'string',
        'console' => 'bool', 'consoleTimeout' => 'int', 'consoleMemory' => 'int',
        'scheduler' => 'int|false', 'logRetention' => 'int|false', 'protected' => 'bool',
        'cacheDriver' => 'string', 'account' => 'string',
    ];
    private const QUEUE = [
        'timeout' => 'int', 'memory' => 'int', 'maxInstances' => 'int', 'batchSize' => 'int', 'tries' => 'int',
        'dlq' => 'bool', 'fifo' => 'bool', 'default' => 'bool', 'adopt' => 'bool', 'existing' => 'string',
    ];
    private const STORAGE = [
        'public' => 'strings', 'expire' => 'intmap', 'cors' => 'strings', 'existing' => 'string',
        'default' => 'bool', 'adopt' => 'bool',
    ];
    private const TABLE = ['ttl' => 'string', 'existing' => 'string', 'adopt' => 'bool'];

    /**
     * @param array{settings?: array<string, mixed>, variables?: array<string, string>, permissions?: list<array<string, mixed>>, domains?: list<string>, queues?: array<string, array<string, mixed>>, storage?: array<string, array<string, mixed>>, tables?: array<string, array<string, mixed>>} $entry the environment's array as written, `adopt` included
     */
    public function __construct(
        public string $path,
        public ?string $name,
        public ?string $region,
        public string $environment,
        public array $entry,
    ) {
    }

    /**
     * The layer named by the option, else by ARCO_CONFIG_LAYER; null when neither names one. Surrounding
     * whitespace is dropped, so a blank value counts as empty.
     *
     * @param array<string, string> $env the process environment, as getenv() returns it
     */
    public static function fromInput(?string $option, array $env): ?self
    {
        $option = trim($option ?? '');
        $path = $option !== '' ? $option : trim($env[self::ENV] ?? '');

        return $path === '' ? null : self::load($path);
    }

    public static function load(string $path): self
    {
        $json = is_file($path) ? @file_get_contents($path) : false;
        if ($json === false) {
            throw ConfigException::make('config.layer_not_found', ['path' => $path]);
        }
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw self::invalid('', $e->getMessage());
        }
        if (! is_array($document) || (array_is_list($document) && $document !== [])) {
            throw self::invalid('', 'expected an object');
        }

        if (($document['schema'] ?? null) !== 1) {
            $shown = array_key_exists('schema', $document) ? json_encode($document['schema']) : 'missing';
            throw ConfigException::make('config.layer_schema', ['schema' => is_string($shown) ? self::clip($shown) : 'invalid']);
        }
        foreach (array_keys($document) as $key) {
            if (! in_array($key, ['schema', 'name', 'region', 'environments'], true)) {
                throw self::invalid('/' . self::pointer((string) $key), 'unknown key');
            }
        }
        $name = self::optionalString($document, 'name', '/name');
        $region = self::optionalString($document, 'region', '/region');

        $environments = $document['environments'] ?? null;
        if (! is_array($environments) || count($environments) !== 1) {
            throw self::invalid('/environments', 'expected exactly one environment');
        }
        if (array_is_list($environments)) {
            throw self::invalid('/environments', 'expected an object keyed by environment name');
        }
        $environment = (string) array_key_first($environments);
        $entry = $environments[array_key_first($environments)];
        $base = '/environments/' . self::pointer($environment);
        if (! is_array($entry) || (array_is_list($entry) && $entry !== [])) {
            throw self::invalid($base, 'expected an object');
        }

        self::checkEntry($entry, $base);

        /** @var array{settings?: array<string, mixed>, variables?: array<string, string>, permissions?: list<array<string, mixed>>, domains?: list<string>, queues?: array<string, array<string, mixed>>, storage?: array<string, array<string, mixed>>, tables?: array<string, array<string, mixed>>} $entry */
        return new self($path, $name, $region, $environment, $entry);
    }

    /** @param array<mixed> $entry */
    private static function checkEntry(array $entry, string $base): void
    {
        foreach ($entry as $key => $value) {
            $at = $base . '/' . self::pointer((string) $key);
            match ($key) {
                'settings' => self::checkSettings($value, $at),
                'variables' => self::checkMap($value, $at, fn (mixed $v, string $p) => self::expect(is_string($v), $p, 'expected string')),
                'permissions' => self::checkPermissions($value, $at),
                'domains' => self::checkStrings($value, $at),
                'queues' => self::checkNamed($value, $at, self::QUEUE),
                'storage' => self::checkNamed($value, $at, self::STORAGE),
                'tables' => self::checkNamed($value, $at, self::TABLE),
                default => throw self::invalid($at, 'unknown key'),
            };
        }
    }

    private static function checkSettings(mixed $settings, string $at): void
    {
        self::checkMap($settings, $at, null, function (string $key, mixed $value, string $p): void {
            if (isset(self::SETTINGS[$key])) {
                self::checkType($value, self::SETTINGS[$key], $p);
            } elseif ($key === 'vpc') {
                self::checkVpc($value, $p);
            } elseif ($key === 'cache' || $key === 'failedJobsTable') {
                self::checkTableSetting($value, $p);
            } else {
                throw self::invalid($p, 'unknown key');
            }
        });
    }

    private static function checkVpc(mixed $vpc, string $at): void
    {
        if ($vpc === false) {
            return;
        }
        self::checkMap($vpc, $at, null, function (string $key, mixed $value, string $p): void {
            match ($key) {
                'subnets', 'securityGroups' => self::checkStrings($value, $p),
                'endpoints' => self::checkEndpoints($value, $p),
                default => throw self::invalid($p, 'unknown key'),
            };
        });
    }

    private static function checkEndpoints(mixed $endpoints, string $at): void
    {
        if (! is_array($endpoints)) {
            throw self::invalid($at, 'expected a list or an object');
        }
        foreach ($endpoints as $key => $value) {
            self::expect(is_string($value), $at . '/' . self::pointer((string) $key), 'expected string');
        }
    }

    private static function checkTableSetting(mixed $value, string $at): void
    {
        if (is_bool($value)) {
            return;
        }
        self::checkMap($value, $at, null, function (string $key, mixed $v, string $p): void {
            if ($key !== 'existing') {
                throw self::invalid($p, 'unknown key');
            }
            self::expect(is_string($v), $p, 'expected string');
        });
    }

    private static function checkPermissions(mixed $permissions, string $at): void
    {
        if (! is_array($permissions) || ! array_is_list($permissions)) {
            throw self::invalid($at, 'expected a list');
        }
        foreach ($permissions as $i => $statement) {
            self::expect(is_array($statement) && ! (array_is_list($statement) && $statement !== []), $at . '/' . $i, 'expected an object');
        }
    }

    /** @param array<string, string> $options option name => type */
    private static function checkNamed(mixed $named, string $at, array $options): void
    {
        self::checkMap($named, $at, null, function (string $name, mixed $entry, string $p) use ($options): void {
            self::checkMap($entry, $p, null, function (string $key, mixed $value, string $q) use ($options): void {
                if (! isset($options[$key])) {
                    throw self::invalid($q, 'unknown key');
                }
                self::checkType($value, $options[$key], $q);
            });
            /** @var array<string, mixed> $entry */
            // Refused even with adopt: false: an entry is either Arco-managed or a reference, and adopt means nothing on a reference.
            if (isset($entry['adopt'], $entry['existing'])) {
                throw self::invalid($p . '/adopt', 'cannot be combined with existing');
            }
        });
    }

    private static function checkType(mixed $value, string $type, string $at): void
    {
        match ($type) {
            'int' => self::expect(is_int($value), $at, 'expected integer'),
            'string' => self::expect(is_string($value), $at, 'expected string'),
            'bool' => self::expect(is_bool($value), $at, 'expected boolean'),
            'string|false' => self::expect(is_string($value) || $value === false, $at, 'expected string or false'),
            'int|false' => self::expect(is_int($value) || $value === false, $at, 'expected integer or false'),
            'strings' => self::checkStrings($value, $at),
            'intmap' => self::checkMap($value, $at, fn (mixed $v, string $p) => self::expect(is_int($v), $p, 'expected integer')),
            default => throw new \LogicException("Unknown layer type $type."),
        };
    }

    private static function checkStrings(mixed $value, string $at): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid($at, 'expected a list of strings');
        }
        foreach ($value as $i => $item) {
            self::expect(is_string($item), $at . '/' . $i, 'expected string');
        }
    }

    /**
     * An object (decoded as an array) whose values are checked by $each (value, pointer) or whose entries are
     * checked by $eachEntry (key, value, pointer).
     *
     * @param (callable(mixed, string): mixed)|null $each
     * @param (callable(string, mixed, string): mixed)|null $eachEntry
     */
    private static function checkMap(mixed $value, string $at, ?callable $each = null, ?callable $eachEntry = null): void
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw self::invalid($at, 'expected an object');
        }
        foreach ($value as $key => $item) {
            $p = $at . '/' . self::pointer((string) $key);
            $each !== null ? $each($item, $p) : ($eachEntry !== null ? $eachEntry((string) $key, $item, $p) : null);
        }
    }

    /** @param array<mixed> $document */
    private static function optionalString(array $document, string $key, string $at): ?string
    {
        if (! array_key_exists($key, $document)) {
            return null;
        }
        $value = $document[$key];
        if (! is_string($value)) {
            throw self::invalid($at, 'expected string');
        }

        return $value;
    }

    private static function expect(bool $ok, string $at, string $reason): void
    {
        if (! $ok) {
            throw self::invalid($at, $reason);
        }
    }

    private static function invalid(string $path, string $reason): ConfigException
    {
        return ConfigException::make('config.layer_invalid', ['path' => $path, 'reason' => $reason]);
    }

    /** One JSON pointer segment (RFC 6901). */
    private static function pointer(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    private static function clip(string $text): string
    {
        return strlen($text) > 40 ? substr($text, 0, 40) . '...' : $text;
    }
}
