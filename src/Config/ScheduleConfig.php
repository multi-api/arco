<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/** One scheduled invocation of a process app: EventBridge Scheduler calls the route on the `every` expression. */
final readonly class ScheduleConfig
{
    public function __construct(
        public string $name,
        public string $every,
        /** null: /_arco/schedule/<name> */
        public ?string $route = null,
        public int $timeout = 60,
    ) {
    }

    public function route(): string
    {
        return $this->route ?? '/_arco/schedule/' . $this->name;
    }
}
