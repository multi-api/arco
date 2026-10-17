<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

use Throwable;

/**
 * Reads a Laravel app's schedule through `php artisan arco:schedule-intervals` (multiapi/arco-laravel):
 * each task with the minutes of the hour (UTC) at which it can fire. Best effort: any problem
 * (no artisan, an older arco-laravel without the command, a failing boot, unexpected output) is null.
 */
final class ScheduleInspector
{
    public const COMMAND = 'arco:schedule-intervals';
    /** Seconds before a hung app boot is given up on, so it cannot stall a deploy or scheduler:set. */
    public const TIMEOUT = 60;

    private readonly CommandRunner $runner;

    public function __construct(?CommandRunner $runner = null)
    {
        $this->runner = $runner ?? new ProcessCommandRunner(null, self::TIMEOUT);
    }

    /** @return list<array{task: string, minutes: list<int>}>|null */
    public function tasks(string $projectDir, string $php): ?array
    {
        if (! is_file("$projectDir/artisan")) {
            return null;
        }
        try {
            // ARCO=1: ArcoServiceProvider registers its commands only for Arco.
            $output = $this->runner->run([$php, 'artisan', self::COMMAND], $projectDir, ['ARCO' => '1']);
        } catch (Throwable) {
            // A failed or timed-out run (ProcessTimedOutException) alike.
            return null;
        }
        return self::parse($output);
    }

    /** @return list<array{task: string, minutes: list<int>}>|null */
    private static function parse(string $output): ?array
    {
        // The app may print notices before the JSON line: take the last line that is a JSON list.
        $lines = array_reverse(preg_split('/\R/', trim($output)) ?: []);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[')) {
                return self::validate(json_decode($line, true));
            }
        }
        return null;
    }

    /** @return list<array{task: string, minutes: list<int>}>|null */
    private static function validate(mixed $data): ?array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            return null;
        }
        $tasks = [];
        foreach ($data as $entry) {
            if (! is_array($entry) || ! is_string($entry['task'] ?? null) || ! is_array($entry['minutes'] ?? null)) {
                return null;
            }
            $minutes = [];
            foreach ($entry['minutes'] as $minute) {
                if (! is_int($minute) || $minute < 0 || $minute > 59) {
                    return null;
                }
                $minutes[] = $minute;
            }
            $tasks[] = ['task' => $entry['task'], 'minutes' => $minutes];
        }
        return $tasks;
    }
}
