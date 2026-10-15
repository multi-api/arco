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

    private static function autoloadPath(): string
    {
        $file = (new ReflectionClass(ClassLoader::class))->getFileName();
        // vendor/composer/ClassLoader.php -> vendor/autoload.php
        return dirname((string) $file, 2) . '/autoload.php';
    }
}
