<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/**
 * The app's phpIni() settings (arco-runtime.json) for commands: written as an .ini file the child PHP
 * scans through PHP_INI_SCAN_DIR, so every key applies, PHP_INI_SYSTEM ones included (ini_set() could
 * not change those). The web runtime applies the same settings through Caddy's php_ini.
 */
final class PhpIni
{
    /**
     * @param array<mixed> $settings
     * @param array<string, string> $env the child's environment, for an existing PHP_INI_SCAN_DIR
     * @return array<string, string> the variables to add to the child's environment
     */
    public static function prepare(array $settings, string $dir, array $env): array
    {
        $lines = [];
        foreach ($settings as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            if (! preg_match('/^[A-Za-z0-9_.-]+$/', (string) $key)) {
                throw new \InvalidArgumentException("Invalid phpIni key: $key");
            }
            $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            if (preg_match('/[\r\n\0]/', $value)) {
                throw new \InvalidArgumentException("Invalid phpIni value for $key: line breaks and NUL are not allowed.");
            }
            $lines[] = sprintf('%s = "%s"', $key, addcslashes($value, '\\"$'));
        }
        if ($lines === []) {
            return [];
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir.");
        }
        if (file_put_contents("$dir/99-arco.ini", implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException("Cannot write $dir/99-arco.ini.");
        }
        // An empty entry keeps PHP's compiled-in scan dir; an existing value is kept and scanned first.
        return ['PHP_INI_SCAN_DIR' => ($env['PHP_INI_SCAN_DIR'] ?? '') . ":$dir"];
    }
}
