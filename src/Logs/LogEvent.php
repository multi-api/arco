<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

/** One CloudWatch Logs event, from FilterLogEvents or Live Tail. */
final readonly class LogEvent
{
    public function __construct(
        public int $timestamp,
        public string $message,
        public string $group,
        public string $stream = '',
        public string $id = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromAws(array $row, string $group): self
    {
        $identifier = is_string($row['logGroupIdentifier'] ?? null) ? $row['logGroupIdentifier'] : $group;
        $position = strpos($identifier, ':log-group:');
        $name = $position === false ? $identifier : substr($identifier, $position + strlen(':log-group:'));
        $timestamp = is_numeric($row['timestamp'] ?? null) ? (int) $row['timestamp'] : 0;
        $message = is_string($row['message'] ?? null) ? $row['message'] : '';
        $stream = is_string($row['logStreamName'] ?? null) ? $row['logStreamName'] : '';
        $id = is_string($row['eventId'] ?? null) && $row['eventId'] !== ''
            ? $row['eventId']
            : self::hash($name, $stream, $timestamp, $message);
        return new self($timestamp, $message, $name, $stream, $id);
    }

    /** Identifies an event by its content: Live Tail events have no eventId, FilterLogEvents ones do. */
    public function contentKey(): string
    {
        return self::hash($this->group, $this->stream, $this->timestamp, $this->message);
    }

    private static function hash(string $group, string $stream, int $timestamp, string $message): string
    {
        return hash('sha256', "$group\0$stream\0$timestamp\0$message");
    }
}
