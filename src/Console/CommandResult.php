<?php declare(strict_types=1);

namespace Multiapi\Arco\Console;

/** What a command on the console function returned, or why the invocation itself failed. */
final readonly class CommandResult
{
    /** @param list<string> $logTail last lines of the invocation log (Lambda returns its last 4 KB) */
    public function __construct(
        public int $exitCode,
        public string $output,
        /** Lambda function error type (e.g. Sandbox.Timedout) when the runtime did not answer normally */
        public ?string $errorType = null,
        public ?string $errorMessage = null,
        public array $logTail = [],
    ) {
    }

    public function crashed(): bool
    {
        return $this->errorType !== null;
    }

    public function succeeded(): bool
    {
        return ! $this->crashed() && $this->exitCode === 0;
    }
}
