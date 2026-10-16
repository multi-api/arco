<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;


/**
 * Formats log lines as `HH:MM:SS LEVEL message [requestId=…]`, colored by level (console markup). Log
 * text is printed as text: console markup is escaped and control characters (ESC, BEL, ...) are dropped,
 * so a logged value can never drive the terminal. `arco logs --json` does not go through here.
 */
final class LogRenderer
{
    private const COLORS = [
        'debug' => 'gray', 'info' => 'green', 'notice' => 'cyan', 'warning' => 'yellow',
        'error' => 'red', 'critical' => 'red', 'alert' => 'red', 'emergency' => 'red',
    ];

    /** @param \Closure(string, array<string, string|int>): string $translate */
    public function __construct(
        private readonly \Closure $translate,
        private readonly bool $verbose = false,
        private readonly bool $platform = false,
    ) {
    }

    /** @return list<string> lines to print; empty when the event is hidden */
    public function render(LogEvent $event, LogLine $line): array
    {
        $time = date('H:i:s', intdiv($event->timestamp, 1000));
        if ($line->kind === LogLine::PLATFORM) {
            if ($this->platform) {
                return [$this->line($time, 'PLATFORM', $line->level, $line->text)];
            }
            return match ($line->summary) {
                'cold_start' => [$this->line($time, 'INIT', null, ($this->translate)('logs.cold_start', ['ms' => $line->durationMs ?? 0]))],
                'init_failed' => [$this->line($time, 'INIT', 'error', ($this->translate)('logs.init_failed', ['type' => (string) $line->errorType]))],
                default => [],
            };
        }
        if ($line->kind === LogLine::ARCO) {
            return $line->level === 'error' || $this->platform ? [$this->line($time, strtoupper((string) $line->level), $line->level, $line->text)] : [];
        }
        if ($line->kind === LogLine::TEXT) {
            return [LogText::escape($line->text)];
        }
        $suffix = $line->requestId !== null ? " [requestId={$line->requestId}]" : '';
        $lines = [$this->line($time, strtoupper((string) $line->level), $line->level, $line->text . $suffix)];
        return $this->verbose ? [...$lines, ...self::details($line->context)] : $lines;
    }

    private function line(string $time, string $label, ?string $level, string $message): string
    {
        $label = str_pad($label, 7);
        $color = $level === null ? null : (self::COLORS[$level] ?? null);
        return $time . ' ' . ($color === null ? $label : "<fg=$color>$label</>") . ' ' . LogText::escape($message);
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private static function details(array $context): array
    {
        $exception = $context['exception'] ?? null;
        unset($context['exception']);
        $lines = [];
        if ($context !== []) {
            $lines[] = '    ' . LogText::escape((string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        if (is_string($exception)) {
            $lines[] = '    ' . LogText::escape($exception);
        } elseif (is_array($exception)) {
            $class = is_string($exception['class'] ?? null) ? $exception['class'] : 'Exception';
            $message = is_string($exception['message'] ?? null) ? $exception['message'] : '';
            $file = is_string($exception['file'] ?? null) ? ' at ' . $exception['file'] : '';
            $lines[] = '    ' . LogText::escape("$class: $message$file");
            $trace = $exception['trace'] ?? [];
            foreach (is_array($trace) ? $trace : (is_string($trace) ? explode("\n", $trace) : []) as $frame) {
                if (is_string($frame) && $frame !== '') {
                    $lines[] = '      ' . LogText::escape($frame);
                }
            }
        }
        return $lines;
    }
}
