<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/**
 * The scheduler invokes `schedule:run` at the minutes of the hour that are multiples of its
 * interval (clock-aligned, in UTC). A task fires only when schedule:run happens at one of its minutes.
 */
final class SchedulerInterval
{
    /** Minutes between schedule:run invocations: the divisors of 60, so every hour looks the same. */
    public const ALLOWED = [1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60];

    /**
     * The largest allowed interval at which schedule:run happens at every one of these minutes
     * (0–59, UTC); 60 when there are none.
     *
     * @param list<int> $minutes
     */
    public static function required(array $minutes): int
    {
        foreach (array_reverse(self::ALLOWED) as $every) {
            if (self::covers($every, $minutes)) {
                return $every;
            }
        }
        return 1;
    }

    /**
     * The tasks that schedule:run every $every minutes would skip at least once an hour.
     *
     * @param list<array{task: string, minutes: list<int>}> $tasks
     * @return list<string>
     */
    public static function missed(int $every, array $tasks): array
    {
        $missed = [];
        foreach ($tasks as $task) {
            if (! self::covers($every, $task['minutes'])) {
                $missed[] = $task['task'];
            }
        }
        return $missed;
    }

    /** @param list<int> $minutes */
    private static function covers(int $every, array $minutes): bool
    {
        foreach ($minutes as $minute) {
            if ($every < 1 || $minute % $every !== 0) {
                return false;
            }
        }
        return true;
    }
}
