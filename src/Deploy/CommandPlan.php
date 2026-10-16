<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Console\Console;

/** The deploy commands to run between prepare and release, and where their results go. */
final readonly class CommandPlan
{
    /**
     * @param list<string> $commands
     * @param \Closure(string, CommandResult): void $onResult called after each command, before the next
     */
    public function __construct(public array $commands, public Console $console, public \Closure $onResult)
    {
    }
}
