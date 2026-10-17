<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

use Aws\CloudWatchLogs\CloudWatchLogsClient;

/**
 * Follows with CloudWatch Live Tail (StartLiveTail, about one second of latency). The client must use
 * FullReadStream::wrap() as http_handler (see docs/maintainers/logs-follow.md).
 *
 * A read never waits forever: the stream has a short read_timeout, $stop() is checked while waiting,
 * and a stream silent for $idleSeconds throws StreamInterrupted (Live Tail sends an update every second,
 * even an empty one).
 */
final class LiveTailFollower implements Follower
{
    /**
     * Bounds connect, the header phase and each silent body read; a timed-out body read is retried, so
     * a waiting read notices Ctrl+C within this time (usually at once: the signal interrupts the wait).
     */
    public const READ_TIMEOUT_SECONDS = 5;
    public const IDLE_SECONDS = 30.0;
    /** StartLiveTail accepts at most this many log groups in one session. */
    public const MAX_GROUPS = 10;

    /** @param list<string> $existing groups known to exist: a missing group fails the whole session */
    public function __construct(
        private readonly CloudWatchLogsClient $logs,
        private readonly string $region,
        private readonly string $account,
        private readonly array $existing,
        private readonly float $idleSeconds = self::IDLE_SECONDS,
    ) {
    }

    public function follow(array $groups, int $fromMs, ?string $pattern, callable $onEvent, callable $stop): void
    {
        $targets = array_values(array_intersect($groups, $this->existing));
        $params = [
            'logGroupIdentifiers' => array_map(fn (string $group) => LogGroups::arn($this->region, $this->account, $group), $targets),
            '@http' => [
                'read_timeout' => self::READ_TIMEOUT_SECONDS,
                FullReadStream::STOP_OPTION => $stop,
                FullReadStream::IDLE_OPTION => $this->idleSeconds,
            ],
        ];
        if ($pattern !== null) {
            $params['logEventFilterPattern'] = $pattern;
        }
        try {
            $stream = $this->logs->startLiveTail($params)['responseStream'] ?? [];
            foreach (is_iterable($stream) ? $stream : [] as $event) {
                if ($stop()) {
                    return;
                }
                $update = is_array($event) ? ($event['sessionUpdate'] ?? null) : null;
                $results = is_array($update) && is_array($update['sessionResults'] ?? null) ? $update['sessionResults'] : [];
                foreach ($results as $row) {
                    if (is_array($row)) {
                        $onEvent(LogEvent::fromAws(Arrays::keyed($row), ''));
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ctrl+C can interrupt a read mid-message; whatever that broke, the user asked to stop.
            if ($stop()) {
                return;
            }
            throw $e;
        }
    }
}
