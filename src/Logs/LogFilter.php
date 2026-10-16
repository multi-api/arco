<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

/**
 * --level and --request, applied to parsed lines. Lines without a level (plain text such as PHP fatal
 * errors, platform summaries) pass --level; only lines carrying the request id pass --request.
 */
final class LogFilter
{
    public const LEVELS = ['debug', 'info', 'warning', 'error'];
    private const RANKS = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];

    public function __construct(private readonly ?string $level = null, private readonly ?string $requestId = null)
    {
    }

    public function accepts(LogLine $line): bool
    {
        if ($this->requestId !== null && $line->requestId !== $this->requestId) {
            return false;
        }
        if ($this->level !== null && $line->level !== null && (self::RANKS[$line->level] ?? 0) < self::RANKS[$this->level]) {
            return false;
        }
        return true;
    }

    /** CloudWatch filter pattern: each value is a quoted term, and all terms must match. */
    public static function pattern(?string $text, ?string $requestId): ?string
    {
        $terms = [];
        foreach ([$text, $requestId] as $term) {
            if ($term !== null && $term !== '') {
                $terms[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $term) . '"';
            }
        }
        return $terms === [] ? null : implode(' ', $terms);
    }
}
