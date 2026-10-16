<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

/** --since / --until: 30s, 10m, 2h, 1d (before now) or a date PHP understands. Bare numbers are refused. */
final class TimeExpression
{
    private const UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    /** @return int epoch milliseconds */
    public static function parse(string $value, int $nowMs): int
    {
        $value = trim($value);
        if (preg_match('/^(\d+)([smhd])$/', $value, $m) === 1) {
            $unitMs = self::UNITS[$m[2]] * 1000;
            // Not before 1970 (which also keeps the arithmetic within int).
            if (strlen($m[1]) > 18 || (int) $m[1] > intdiv($nowMs, $unitMs)) {
                throw new \InvalidArgumentException("Time \"$value\" is too far back.");
            }
            return $nowMs - (int) $m[1] * $unitMs;
        }
        if ($value === '') {
            throw new \InvalidArgumentException('Empty time.');
        }
        // 1500, 2026, 1.5h: PHP would read some of these as a time of day or a year (15.10.2026 is a date).
        if (preg_match('/^\d+(\.\d+)?[a-z]?$/i', $value) === 1) {
            throw new \InvalidArgumentException("Unrecognized time \"$value\".");
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Unrecognized time \"$value\".");
        }
        return (int) $date->format('Uv');
    }
}
