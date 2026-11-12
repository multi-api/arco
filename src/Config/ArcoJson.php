<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

use JsonException;

/**
 * arco.json (schema 1): the JSON stand-in for arco.php in process apps. It is read with json_decode (no PHP
 * runs) and turned into the raw array App::toArray() gives, plus `process` and `schedules`, so ConfigResolver
 * and its inheritance, layer merge and conflict rules apply unchanged. Shapes are checked with ConfigLayer's
 * checks; a refusal carries the JSON pointer of the culprit and never echoes a value.
 */
final class ArcoJson
{
    private const KEYS = ['schema', 'name', 'region', 'runtime', 'include', 'exclude', 'settings', 'variables', 'permissions', 'domains', 'queues', 'storage', 'tables', 'schedules', 'environments'];
    private const RUNTIME = ['kind', 'language', 'main', 'command', 'healthPath'];

    /**
     * @param bool $hasGoMod whether the project root has a go.mod (the default runtime when `runtime` is missing)
     * @return array<string, mixed>
     * @throws ConfigException config.json_invalid, config.json_runtime_missing, config.json_php_runtime
     */
    public static function load(string $path, bool $hasGoMod): array
    {
        $json = is_file($path) ? @file_get_contents($path) : false;
        if ($json === false) {
            throw self::invalid('', 'cannot read the file');
        }
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw self::invalid('', $e->getMessage());
        }
        if (! is_array($document) || (array_is_list($document) && $document !== [])) {
            throw self::invalid('', 'expected an object');
        }
        foreach (array_keys($document) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw self::invalid('/' . ConfigLayer::pointer((string) $key), 'unknown key');
            }
        }
        if (array_key_exists('schema', $document) && $document['schema'] !== 1) {
            throw self::invalid('/schema', 'expected 1');
        }

        $process = self::process($document['runtime'] ?? null, array_key_exists('runtime', $document), $hasGoMod);
        $raw = [
            ...App::make('arco-json')->toArray(),
            'name' => self::optionalString($document, 'name'),
            'region' => self::optionalString($document, 'region'),
        ];
        foreach (['include', 'exclude'] as $key) {
            if (array_key_exists($key, $document)) {
                self::strings($document[$key], "/$key");
                $raw[$key] = $document[$key];
            }
        }

        $shared = array_intersect_key($document, array_flip(['settings', 'variables', 'permissions', 'domains', 'queues', 'storage', 'tables', 'schedules']));
        self::check($shared, '');
        $raw = [...$raw, 'schedules' => [], ...$shared];
        if (array_key_exists('tables', $shared) === false) {
            unset($raw['tables']);
        }
        $raw['environments'] = self::environments($document['environments'] ?? []);
        $raw['process'] = $process;

        return $raw;
    }

    /**
     * @param array<mixed> $entry
     * @param bool $allowFalse a named entry may be `false` (environment level)
     */
    private static function check(array $entry, string $base, bool $allowFalse = false): void
    {
        try {
            ConfigLayer::checkEntry($entry, $base, $allowFalse);
        } catch (ConfigException $e) {
            throw $e->key === 'config.layer_invalid' ? self::invalid((string) $e->params['path'], (string) $e->params['reason']) : $e;
        }
    }

    private static function strings(mixed $value, string $at): void
    {
        try {
            ConfigLayer::checkStrings($value, $at);
        } catch (ConfigException $e) {
            throw self::invalid((string) $e->params['path'], (string) $e->params['reason']);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private static function environments(mixed $environments): array
    {
        if (! is_array($environments) || (array_is_list($environments) && $environments !== [])) {
            throw self::invalid('/environments', 'expected an object keyed by environment name');
        }
        $result = [];
        foreach ($environments as $name => $entry) {
            $at = '/environments/' . ConfigLayer::pointer((string) $name);
            if (! is_array($entry) || (array_is_list($entry) && $entry !== [])) {
                throw self::invalid($at, 'expected an object');
            }
            self::check($entry, $at, true);
            /** @var array<string, mixed> $entry */
            $result[(string) $name] = [...(new Environment())->toArray(), ...$entry];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function process(mixed $runtime, bool $present, bool $hasGoMod): array
    {
        if (! $present) {
            return $hasGoMod ? ['language' => 'go'] : throw ConfigException::make('config.json_runtime_missing');
        }
        if (! is_array($runtime) || (array_is_list($runtime) && $runtime !== [])) {
            throw self::invalid('/runtime', 'expected an object');
        }
        foreach (array_keys($runtime) as $key) {
            if (! in_array($key, self::RUNTIME, true)) {
                throw self::invalid('/runtime/' . ConfigLayer::pointer((string) $key), 'unknown key');
            }
        }
        $kind = $runtime['kind'] ?? 'process';
        if ($kind === 'php') {
            throw ConfigException::make('config.json_php_runtime');
        }
        if ($kind !== 'process') {
            throw self::invalid('/runtime/kind', 'expected "process"');
        }

        $process = [];
        $language = $runtime['language'] ?? ($hasGoMod ? 'go' : null);
        if ($language !== 'go') {
            throw self::invalid('/runtime/language', $language === null ? 'required' : 'expected "go"');
        }
        $process['language'] = $language;
        foreach (['main', 'healthPath'] as $key) {
            if (array_key_exists($key, $runtime)) {
                if (! is_string($runtime[$key])) {
                    throw self::invalid("/runtime/$key", 'expected string');
                }
                $process[$key] = $runtime[$key];
            }
        }
        if (array_key_exists('command', $runtime)) {
            $command = $runtime['command'];
            if (is_string($command)) {
                // Split on whitespace only (no quoting): use the list form for arguments with spaces.
                $command = preg_split('/\s+/', trim($command), -1, PREG_SPLIT_NO_EMPTY);
            }
            if (! is_array($command) || ! array_is_list($command) || $command === []) {
                throw self::invalid('/runtime/command', 'expected a list of strings or a string');
            }
            self::strings($command, '/runtime/command');
            $process['command'] = $command;
        }

        return $process;
    }

    /** @param array<mixed> $document */
    private static function optionalString(array $document, string $key): ?string
    {
        if (! array_key_exists($key, $document) || $document[$key] === null) {
            return null;
        }
        if (! is_string($document[$key])) {
            throw self::invalid("/$key", 'expected string');
        }

        return $document[$key];
    }

    private static function invalid(string $pointer, string $reason): ConfigException
    {
        return ConfigException::make('config.json_invalid', ['pointer' => $pointer, 'reason' => $reason]);
    }
}
