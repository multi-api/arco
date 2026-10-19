<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

/** What a Laravel app uses, as found by AppInspector. */
final class Detection
{
    /**
     * @param list<array{task: string, minutes: list<int>}>|null $schedule `[]` when a schedule was found only
     *        statically (the tasks are unknown), null when the app has none
     * @param list<string> $queues empty when no job is queued; otherwise 'default' first, then the other literal names sorted
     * @param int $queuedClasses classes under app/ that implement ShouldQueue
     * @param bool $sqlDatabase DB_CONNECTION is set and is not sqlite
     */
    public function __construct(
        public readonly ?array $schedule,
        public readonly array $queues,
        public readonly int $queuedClasses,
        public readonly bool $storage,
        public readonly bool $cache,
        public readonly bool $sqlDatabase,
    ) {
    }
}
