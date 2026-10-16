<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Console\CommandResult;

/**
 * A deploy command failed, crashed, or could not be invoked to the end (its outcome is unknown);
 * the deploy stopped before moving traffic.
 */
final class DeployCommandFailed extends \RuntimeException
{
    /**
     * @param CommandResult|null $result null when the invoke itself failed (throttling, network, HTTP timeout): see getPrevious()
     * @param list<string> $ran commands that ran or may have run, the failing one last
     */
    public function __construct(public readonly string $command, public readonly ?CommandResult $result, public readonly array $ran, ?\Throwable $previous = null)
    {
        parent::__construct("Deploy command \"$command\" failed.", 0, $previous);
    }
}
