<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

interface Follower
{
    /**
     * Calls $onEvent for each new event until $stop() returns true or the source ends.
     *
     * @param list<string> $groups
     * @param callable(LogEvent): void $onEvent
     * @param callable(): bool $stop
     */
    public function follow(array $groups, int $fromMs, ?string $pattern, callable $onEvent, callable $stop): void;
}
