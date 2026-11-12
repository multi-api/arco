<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/** A language-neutral process runtime: Arco runs `command` inside the function and proxies HTTP, queues and schedules to it. */
final readonly class ProcessRuntime
{
    /** @param list<string> $command */
    public function __construct(
        public string $language,
        /** the package or file the build compiles; null: the language's default */
        public ?string $main,
        public array $command = ['bin/app'],
        public string $healthPath = '/',
    ) {
    }

    /** The runtime kind; the only one there is. */
    public function kind(): string
    {
        return 'process';
    }
}
