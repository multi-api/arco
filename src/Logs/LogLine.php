<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

/**
 * A log message understood: an app line (Monolog JSON from arco-laravel, or Lambda's JSON lines),
 * a Lambda platform event (JSON on Web Functions, text on the console function), an Arco runtime
 * line ({"arco": ...}) or plain text.
 */
final readonly class LogLine
{
    public const APP = 'app';
    public const PLATFORM = 'platform';
    public const ARCO = 'arco';
    public const TEXT = 'text';

    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
    private const MONOLOG = [100 => 'debug', 200 => 'info', 250 => 'notice', 300 => 'warning', 400 => 'error', 500 => 'critical', 550 => 'alert', 600 => 'emergency'];
    private const ARCO_ERRORS = ['fatal', 'secrets_missing', 'command_failed', 'invocation_error', 'frankenphp_exit'];

    /** @param array<string, mixed> $context */
    public function __construct(
        public string $kind,
        public ?string $level,
        public string $text,
        public ?string $requestId = null,
        public array $context = [],
        /** 'cold_start' or 'init_failed' for the platform events shown as one summary line */
        public ?string $summary = null,
        public ?int $durationMs = null,
        public ?string $errorType = null,
    ) {
    }

    public static function parse(string $message): self
    {
        $line = rtrim($message, "\r\n");
        $decoded = str_starts_with(ltrim($line), '{') ? json_decode($line, true) : null;
        if (is_array($decoded)) {
            $data = Arrays::keyed($decoded);
            if (is_string($data['type'] ?? null) && str_starts_with($data['type'], 'platform.')) {
                return self::platform($data['type'], is_array($data['record'] ?? null) ? Arrays::keyed($data['record']) : []);
            }
            if (is_string($data['arco'] ?? null)) {
                return new self(self::ARCO, in_array($data['arco'], self::ARCO_ERRORS, true) ? 'error' : 'debug', $line);
            }
            if (is_string($data['message'] ?? null) && (isset($data['level_name']) || isset($data['level']))) {
                return self::app($data['message'], $data);
            }
        }
        return self::text($line);
    }

    /** @param array<string, mixed> $record */
    private static function platform(string $type, array $record): self
    {
        if ($type !== 'platform.initReport') {
            return new self(self::PLATFORM, null, $type . ' ' . json_encode($record, JSON_UNESCAPED_SLASHES));
        }
        $status = is_string($record['status'] ?? null) ? $record['status'] : 'unknown';
        if ($status === 'success') {
            $metrics = is_array($record['metrics'] ?? null) ? Arrays::keyed($record['metrics']) : [];
            $ms = $metrics['totalDurationMs'] ?? $metrics['durationMs'] ?? null;
            return new self(self::PLATFORM, null, $type, summary: 'cold_start', durationMs: is_numeric($ms) ? (int) round((float) $ms) : null);
        }
        return new self(self::PLATFORM, 'error', $type, summary: 'init_failed', errorType: is_string($record['errorType'] ?? null) ? $record['errorType'] : $status);
    }

    /** @param array<string, mixed> $data */
    private static function app(string $message, array $data): self
    {
        $raw = $data['level_name'] ?? $data['level'] ?? null;
        $level = match (true) {
            is_string($raw) => strtolower($raw),
            is_int($raw) => self::MONOLOG[$raw] ?? null,
            default => null,
        };
        $level = match ($level) {
            'warn' => 'warning',
            'fatal' => 'critical',
            'trace' => 'debug',
            default => $level,
        };
        $context = is_array($data['context'] ?? null) ? Arrays::keyed($data['context']) : [];
        $requestId = $context['requestId'] ?? $data['requestId'] ?? null;
        unset($context['requestId']);
        return new self(
            self::APP,
            in_array($level, self::LEVELS, true) ? $level : 'info',
            $message,
            is_string($requestId) ? $requestId : null,
            $context,
        );
    }

    private static function text(string $line): self
    {
        if (preg_match('/^(START|END) RequestId:/', $line) === 1 || str_starts_with($line, 'INIT_START')) {
            return new self(self::PLATFORM, null, $line);
        }
        if (str_starts_with($line, 'REPORT RequestId:')) {
            return preg_match('/Init Duration: ([\d.]+) ms/', $line, $m) === 1
                ? new self(self::PLATFORM, null, $line, summary: 'cold_start', durationMs: (int) round((float) $m[1]))
                : new self(self::PLATFORM, null, $line);
        }
        if (str_starts_with($line, 'INIT_REPORT')) {
            if (preg_match('/Status: (error|timeout)/', $line, $m) === 1) {
                $type = preg_match('/Error Type: (\S+)/', $line, $t) === 1 ? $t[1] : $m[1];
                return new self(self::PLATFORM, 'error', $line, summary: 'init_failed', errorType: $type);
            }
            return new self(self::PLATFORM, null, $line);
        }
        return new self(self::TEXT, null, $line);
    }
}
