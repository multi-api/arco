<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/** Turns a schedule's `every` (`5m`, `6h`, `1d` or a 5-field cron, UTC) into an EventBridge schedule expression. */
final class ScheduleExpression
{
    /** The EventBridge expression for `every`, or null when it is neither a short interval nor a cron expression. */
    public static function toEventBridge(string $every): ?string
    {
        $every = trim($every);
        if (preg_match('/^([1-9]\d*)([mhd])$/', $every, $m) === 1) {
            return self::interval((int) $m[1], $m[2]);
        }
        $fields = preg_split('/\s+/', $every) ?: [];
        return count($fields) === 5 ? self::cron($fields) : null;
    }

    private static function interval(int $n, string $unit): ?string
    {
        if ($unit === 'd') {
            return $n === 1 ? 'cron(0 0 * * ? *)' : null;
        }
        $minutes = $unit === 'm';
        $limit = $minutes ? 59 : 23;
        if ($n > $limit) {
            return null;
        }
        if ($n === 1) {
            return $minutes ? 'cron(* * * * ? *)' : 'cron(0 * * * ? *)';
        }
        if (($minutes ? 60 : 24) % $n === 0) {
            return $minutes ? "cron(0/$n * * * ? *)" : "cron(0 0/$n * * ? *)";
        }
        return 'rate(' . $n . ($minutes ? ' minutes)' : ' hours)');
    }

    /** @param list<string> $fields minute, hour, day of month, month, day of week */
    private static function cron(array $fields): ?string
    {
        [$minute, $hour, $dom, $month, $dow] = $fields;
        foreach ([[$minute, 0, 59], [$hour, 0, 23], [$dom, 1, 31], [$month, 1, 12]] as [$field, $min, $max]) {
            if (self::field($field, $min, $max, false) === null) {
                return null;
            }
        }
        if ($dom !== '*' && $dow !== '*') {
            return null;
        }
        if ($dow !== '*') {
            $dow = self::field($dow, 0, 7, true);
            if ($dow === null) {
                return null;
            }
            $dom = '?';
        } else {
            $dow = '?';
        }
        return "cron($minute $hour $dom $month $dow *)";
    }

    /**
     * One cron field (a comma list of `*`, `a`, `a-b`, each with an optional `/step`) checked against its range; the
     * field itself, or for the day of week its EventBridge form (0 or 7 is Sunday, which is 1 there). Null when invalid.
     */
    private static function field(string $field, int $min, int $max, bool $weekday): ?string
    {
        $parts = [];
        foreach (explode(',', $field) as $item) {
            if (preg_match('/^(?:(\*)|(\d+)(?:-(\d+))?)(?:\/(\d+))?$/', $item, $m) !== 1) {
                return null;
            }
            $star = ($m[1] ?? '') === '*';
            $step = ($m[4] ?? '') === '' ? null : (int) $m[4];
            if ($step === 0) {
                return null;
            }
            if ($star) {
                $parts[] = '*' . ($step !== null ? "/$step" : '');
                continue;
            }
            $from = (int) ($m[2] ?? '');
            $to = ($m[3] ?? '') === '' ? null : (int) $m[3];
            if ($from < $min || $from > $max || ($to !== null && ($to < $from || $to > $max))) {
                return null;
            }
            if (! $weekday) {
                $parts[] = $from . ($to !== null ? "-$to" : '') . ($step !== null ? "/$step" : '');
                continue;
            }
            $translated = self::weekdayItem($from, $to, $step);
            if ($translated === null) {
                return null;
            }
            $parts[] = $translated;
        }
        return implode(',', $parts);
    }

    private static function weekdayItem(int $from, ?int $to, ?int $step): ?string
    {
        $suffix = $step !== null ? "/$step" : '';
        if ($to === null) {
            return ($from % 7 + 1) . $suffix;
        }
        if ($to < 7) {
            return ($from + 1) . '-' . ($to + 1) . $suffix;
        }
        // A range that ends at 7 (Sunday) runs to the end of EventBridge's week, then wraps to its Sunday (1).
        if ($step !== null || $from === 7) {
            return null;
        }
        return $from <= 1 ? '1-7' : ($from + 1) . '-7,1';
    }
}
