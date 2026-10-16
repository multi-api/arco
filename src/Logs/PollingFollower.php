<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Multiapi\Arco\Deploy\Sleeper;

/**
 * Follows by polling FilterLogEvents every 2 s. Events reach CloudWatch Logs 10–40 s late, so each
 * poll looks back a minute and skips events it already printed.
 */
final class PollingFollower implements Follower
{
    public const INTERVAL_SECONDS = 2;
    public const WINDOW_MS = 60_000;

    public function __construct(private readonly CloudWatchLogsClient $logs, private readonly Sleeper $sleeper)
    {
    }

    public function follow(array $groups, int $fromMs, ?string $pattern, callable $onEvent, callable $stop): void
    {
        $reader = new LogReader($this->logs);
        /** @var array<string, int> $seen event id => timestamp */
        $seen = [];
        while (! $stop()) {
            $start = max($fromMs, (int) floor($this->sleeper->now() * 1000) - self::WINDOW_MS);
            foreach ($reader->read($groups, $start, null, $pattern)['events'] as $event) {
                if (isset($seen[$event->id])) {
                    continue;
                }
                $seen[$event->id] = $event->timestamp;
                $onEvent($event);
            }
            $seen = array_filter($seen, fn (int $timestamp) => $timestamp >= $start);
            $this->pause($stop);
        }
    }

    /** @param callable(): bool $stop */
    private function pause(callable $stop): void
    {
        if (! $stop()) {
            $this->sleeper->sleep(self::INTERVAL_SECONDS);
        }
    }
}
