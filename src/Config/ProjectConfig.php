<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/**
 * What the project root holds decides the runtime kind and where the configuration comes from:
 * arco.php (PHP), arco.json (process), a go.mod (process, configuration from the config layer).
 */
final class ProjectConfig
{
    /**
     * The raw configuration array ConfigResolver reads.
     *
     * @param bool $identity false for a caller that reads only the environments (`arco profile`): an arco.json may then
     *        leave `name` and `region` to a layer it was not given
     * @return array{raw: array<string, mixed>, hasArcoPhp: bool} hasArcoPhp: arco.php is the source (false for arco.json, go.mod and a layer alone)
     * @throws ConfigException config.both_files, config.not_found, config.json_*, config.layer_missing
     */
    public static function raw(string $projectDir, ?ConfigLayer $layer, bool $identity = true): array
    {
        $arcoPhp = $projectDir . '/arco.php';
        $arcoJson = $projectDir . '/arco.json';
        $hasGoMod = is_file($projectDir . '/go.mod');

        if (is_file($arcoPhp) && is_file($arcoJson)) {
            throw ConfigException::make('config.both_files');
        }
        if (is_file($arcoPhp)) {
            return ['raw' => (new ConfigLoader())->load($arcoPhp), 'hasArcoPhp' => true];
        }
        if (is_file($arcoJson)) {
            $raw = ArcoJson::load($arcoJson, $hasGoMod);
            foreach ($identity ? ['name', 'region'] : [] as $key) {
                if ($raw[$key] === null && ($layer === null || $layer->$key === null)) {
                    throw ConfigException::make('config.json_missing', ['key' => $key]);
                }
            }
            return ['raw' => $raw, 'hasArcoPhp' => false];
        }
        if ($layer === null) {
            throw ConfigException::make('config.not_found', ['path' => $arcoPhp]);
        }
        $raw = ConfigResolver::layerOnly($layer);
        if ($hasGoMod && ! self::isArcoLaravelApp($projectDir)) {
            $raw['process'] = ['language' => 'go'];
        }

        return ['raw' => $raw, 'hasArcoPhp' => false];
    }

    /**
     * Whether the project is a process app: arco.json, or a go.mod without arco.php that is not an Arco Laravel
     * app (Arco Cloud's rule). Reads composer.json only in that last case.
     */
    public static function isProcess(string $projectDir): bool
    {
        return is_file($projectDir . '/arco.json')
            || (! is_file($projectDir . '/arco.php') && is_file($projectDir . '/go.mod') && ! self::isArcoLaravelApp($projectDir));
    }

    /** composer.json requires both multiapi/arco and multiapi/arco-laravel (require or require-dev); unreadable counts as no. */
    private static function isArcoLaravelApp(string $projectDir): bool
    {
        $json = is_file($projectDir . '/composer.json') ? @file_get_contents($projectDir . '/composer.json') : false;
        $composer = $json === false ? null : json_decode($json, true);
        if (! is_array($composer)) {
            return false;
        }
        foreach (['multiapi/arco', 'multiapi/arco-laravel'] as $package) {
            $required = false;
            foreach (['require', 'require-dev'] as $section) {
                $required = $required || (is_array($composer[$section] ?? null) && array_key_exists($package, $composer[$section]));
            }
            if (! $required) {
                return false;
            }
        }

        return true;
    }

    /** Whether the project root has a configuration file (arco.php or arco.json). */
    public static function hasFile(string $projectDir): bool
    {
        return is_file($projectDir . '/arco.php') || is_file($projectDir . '/arco.json');
    }
}
