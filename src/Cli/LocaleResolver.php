<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

final class LocaleResolver
{
    public const SUPPORTED = ['en', 'es'];

    /** @param array<string, string> $env */
    public static function resolve(?string $flag, array $env, string $globalConfigPath): string
    {
        $candidates = [$flag, $env['ARCO_LANG'] ?? null, self::fromGlobalConfig($globalConfigPath)];
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $locale = strtolower(substr($candidate, 0, 2));
            return in_array($locale, self::SUPPORTED, true) ? $locale : 'en';
        }
        return 'en';
    }

    public static function globalConfigPath(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
        return $home . '/.arco/config.php';
    }

    private static function fromGlobalConfig(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $config = require $path;
        return is_array($config) && isset($config['lang']) && is_string($config['lang']) ? $config['lang'] : null;
    }
}
