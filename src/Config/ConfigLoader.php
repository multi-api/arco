<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

use Composer\Autoload\ClassLoader;
use Multiapi\Arco\Arco;
use ReflectionClass;
use Symfony\Component\Process\Process;

final class ConfigLoader
{
    /** @return array<string, mixed> */
    public function load(string $arcoPhpPath): array
    {
        if (! is_file($arcoPhpPath)) {
            throw ConfigException::make('config.not_found', ['path' => $arcoPhpPath]);
        }

        $output = tempnam(sys_get_temp_dir(), 'arco-config-');
        if ($output === false) {
            throw ConfigException::make('config.failed', ['output' => 'Cannot create a temporary file.']);
        }

        try {
            $process = new Process(
                [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', Arco::packageRoot() . '/resources/load-config.php', $arcoPhpPath, self::autoloadPath(), $output],
                dirname($arcoPhpPath),
            );
            $process->run();

            if ($process->getExitCode() === 3 && str_starts_with($process->getErrorOutput(), 'ARCO_INVALID_RETURN:')) {
                throw ConfigException::make('config.invalid_return', [
                    'type' => substr($process->getErrorOutput(), strlen('ARCO_INVALID_RETURN:')),
                ]);
            }
            if ($process->getExitCode() === 4 && str_starts_with($process->getErrorOutput(), 'ARCO_REMOVED_METHOD:')) {
                throw self::removedMethod(substr($process->getErrorOutput(), strlen('ARCO_REMOVED_METHOD:')), $arcoPhpPath);
            }
            if ($process->getExitCode() === 5 && str_starts_with($process->getErrorOutput(), 'ARCO_CHANGED_SIGNATURE:')) {
                throw self::changedSignature(substr($process->getErrorOutput(), strlen('ARCO_CHANGED_SIGNATURE:')));
            }
            if (! $process->isSuccessful()) {
                throw ConfigException::make('config.failed', [
                    'output' => trim($process->getOutput() . "\n" . $process->getErrorOutput()),
                ]);
            }

            /** @var array<string, mixed> $raw */
            $raw = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
            return $raw;
        } finally {
            @unlink($output);
        }
    }

    /** The error for a call to a method removed from the API, naming its replacement (with the value arco.php passed, when it can be read). */
    private static function removedMethod(string $method, string $arcoPhpPath): ConfigException
    {
        $value = preg_match('/->\s*' . preg_quote($method, '/') . '\s*\(\s*(\d+)/', (string) @file_get_contents($arcoPhpPath), $match) === 1 ? $match[1] : 'N';
        return $method === 'maxEnvironments'
            ? ConfigException::make('config.removed_max_environments', ['value' => $value])
            : ConfigException::make('config.removed_console_timeout', ['value' => $value]);
    }

    /** The error for a call whose signature changed in 0.9 (binary(path:), dns(profile:), cache(null), failedJobsTable(null)). */
    private static function changedSignature(string $code): ConfigException
    {
        return match ($code) {
            'binary_path' => ConfigException::make('config.changed_binary_path'),
            'dns_profile' => ConfigException::make('config.changed_dns_profile'),
            'null_cache', 'null_failedJobsTable' => ConfigException::make('config.changed_null_table', ['method' => substr($code, 5)]),
            default => ConfigException::make('config.failed', ['output' => $code]),
        };
    }

    private static function autoloadPath(): string
    {
        return self::autoloadPathFor(ClassLoader::getRegisteredLoaders());
    }

    /**
     * The autoload.php of the vendor dir whose loader knows Arco's classes. Reflecting on ClassLoader is not
     * enough: Laravel Herd's PHP prepends its own phar-bundled ClassLoader, which cannot find Arco.
     *
     * @param array<string, ClassLoader> $loaders vendor dir => loader
     */
    public static function autoloadPathFor(array $loaders): string
    {
        foreach ($loaders as $vendorDir => $loader) {
            if ($loader->findFile(App::class) !== false) {
                return $vendorDir . '/autoload.php';
            }
        }

        $file = (new ReflectionClass(ClassLoader::class))->getFileName();
        // vendor/composer/ClassLoader.php -> vendor/autoload.php
        return dirname((string) $file, 2) . '/autoload.php';
    }
}
