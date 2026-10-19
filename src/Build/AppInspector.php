<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

use Closure;
use FilesystemIterator;
use Multiapi\Arco\Config\Validator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Detects what a Laravel app uses (schedule, queued jobs, S3 storage, DynamoDB cache, SQL database)
 * so `arco init` can suggest the matching resources. Best effort and read-only: whatever cannot be
 * read or parsed counts as "not used". The stock Laravel config files define s3 and dynamodb entries
 * in every app, so only explicit selections count as signals.
 */
final class AppInspector
{
    /** Upper bound of the files scanned under app/, so a huge or odd tree cannot stall `arco init`. */
    private const MAX_FILES = 5000;

    /** @var Closure(string): ?list<array{task: string, minutes: list<int>}> */
    private readonly Closure $scheduleTasks;

    /** @param Closure(string): ?list<array{task: string, minutes: list<int>}>|null $scheduleTasks the app's schedule by project directory; null reads it through artisan */
    public function __construct(?Closure $scheduleTasks = null)
    {
        $this->scheduleTasks = $scheduleTasks ?? fn (string $dir): ?array => (new ScheduleInspector())->tasks($dir, PHP_BINARY);
    }

    public function detect(string $projectDir): Detection
    {
        $appCode = self::phpSources("$projectDir/app");
        [$queues, $queuedClasses] = $this->queues($appCode, self::phpSources("$projectDir/routes"));
        $connection = self::databaseConnection("$projectDir/.env");

        return new Detection(
            $this->schedule($projectDir),
            $queues,
            $queuedClasses,
            $this->usesStorage($projectDir, $appCode),
            $this->usesDynamoDbCache($projectDir),
            $connection !== null && $connection !== 'sqlite',
        );
    }

    /** DB_CONNECTION from .env, lowercase, or null when it is not set. */
    public static function databaseConnection(string $envFile): ?string
    {
        $value = strtolower((string) self::envValue($envFile, 'DB_CONNECTION'));
        return $value === '' ? null : $value;
    }

    /** @return list<array{task: string, minutes: list<int>}>|null */
    private function schedule(string $dir): ?array
    {
        try {
            $tasks = ($this->scheduleTasks)($dir);
        } catch (Throwable) {
            $tasks = null;
        }
        if ($tasks !== null && $tasks !== []) {
            return $tasks;
        }
        $static = self::contains("$dir/routes/console.php", 'Schedule::')
            || self::contains("$dir/bootstrap/app.php", 'withSchedule(')
            || self::contains("$dir/app/Console/Kernel.php", '$schedule->');
        return $static ? [] : null;
    }

    /**
     * @param list<string> $appCode the contents of the PHP files under app/
     * @param list<string> $routeCode the contents of the PHP files under routes/
     * @return array{0: list<string>, 1: int} queue names and the number of queued classes
     */
    private function queues(array $appCode, array $routeCode): array
    {
        $classes = 0;
        foreach ($appCode as $code) {
            if (preg_match('/implements\s[^{]*\bShouldQueue\b/', $code) === 1) {
                $classes++;
            }
        }
        if ($classes === 0) {
            return [[], 0];
        }
        $names = [];
        foreach ([...$appCode, ...$routeCode] as $code) {
            preg_match_all('/->onQueue\(\s*(?:queue\s*:\s*)?[\'"]([^\'"\r\n]*)[\'"]|onQueue\s*:\s*[\'"]([^\'"\r\n]*)[\'"]|public\s+(?:\?string\s+)?\$queue\s*=\s*[\'"]([^\'"\r\n]*)[\'"]/', $code, $matches);
            foreach ([1, 2, 3] as $group) {
                foreach ($matches[$group] as $name) {
                    if ($name !== '' && $name !== 'default' && preg_match(Validator::NAME, $name) === 1) {
                        $names[$name] = true;
                    }
                }
            }
        }
        $others = array_map('strval', array_keys($names));
        sort($others);
        return [['default', ...$others], $classes];
    }

    /** @param list<string> $appCode the contents of the PHP files under app/ */
    private function usesStorage(string $dir, array $appCode): bool
    {
        if (strtolower((string) self::envValue("$dir/.env", 'FILESYSTEM_DISK')) === 's3') {
            return true;
        }
        $composer = json_decode((string) @file_get_contents("$dir/composer.json"), true);
        if (is_array($composer) && is_array($composer['require'] ?? null) && array_key_exists('league/flysystem-aws-s3-v3', $composer['require'])) {
            return true;
        }
        foreach ($appCode as $code) {
            if (preg_match('/disk\(\s*[\'"]s3[\'"]\s*\)/', $code) === 1) {
                return true;
            }
        }
        return false;
    }

    private function usesDynamoDbCache(string $dir): bool
    {
        foreach (['CACHE_STORE', 'CACHE_DRIVER'] as $key) {
            if (strtolower((string) self::envValue("$dir/.env", $key)) === 'dynamodb') {
                return true;
            }
        }
        $config = (string) @file_get_contents("$dir/config/cache.php");
        return preg_match('/\'default\'\s*=>\s*(env\(\s*\'CACHE_(STORE|DRIVER)\'\s*,\s*)?\'dynamodb\'/', $config) === 1;
    }

    private static function contains(string $file, string $needle): bool
    {
        return is_file($file) && str_contains((string) @file_get_contents($file), $needle);
    }

    /** The unquoted value of the first `KEY=` line of an .env file, or null when the file or key is missing. */
    private static function envValue(string $envFile, string $key): ?string
    {
        foreach (self::envEntries($envFile) as [$name, $value]) {
            if ($name === $key) {
                return $value;
            }
        }
        return null;
    }

    /**
     * The `KEY=value` lines of an .env file in order, values unquoted and stripped of trailing comments.
     * Empty when the file is missing. Shared with `arco init`, which reads the secret-looking keys.
     *
     * @return list<array{0: string, 1: string}> [key, value] pairs
     */
    public static function envEntries(string $envFile): array
    {
        if (! is_file($envFile)) {
            return [];
        }
        $entries = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=(.*)$/', $line, $m)) {
                $entries[] = [$m[1], self::unquote($m[2])];
            }
        }
        return $entries;
    }

    private static function unquote(string $value): string
    {
        $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    /** @return list<string> the contents of the regular .php files below $dir, without following symlinks, at most MAX_FILES files */
    private static function phpSources(string $dir): array
    {
        if (! is_dir($dir) || is_link($dir)) {
            return [];
        }
        $sources = [];
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                fn (SplFileInfo $entry): bool => ! $entry->isLink(),
            ));
            foreach ($iterator as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                    $sources[] = (string) @file_get_contents($entry->getPathname());
                    if (count($sources) >= self::MAX_FILES) {
                        break;
                    }
                }
            }
        } catch (Throwable) {
            // An unreadable directory ends the scan with what was found.
        }
        return $sources;
    }
}
