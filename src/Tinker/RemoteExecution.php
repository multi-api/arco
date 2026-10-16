<?php declare(strict_types=1);

namespace Multiapi\Arco\Tinker;

use Psy\ExecutionClosure;
use Psy\ExecutionLoop\AbstractListener;
use Psy\Shell;

/** PsySH loop listener: sends each statement to the console function instead of running it locally. */
final class RemoteExecution extends AbstractListener
{
    /** @param \Closure(TinkerReply): void $render */
    public function __construct(private readonly TinkerSession $session, private readonly \Closure $render)
    {
    }

    public static function isSupported(): bool
    {
        return true;
    }

    public function onExecute(Shell $shell, string $code): ?string
    {
        if (trim($code) === ExecutionClosure::NOOP_INPUT) {
            return null;
        }
        ($this->render)($this->session->execute($code));
        // Nothing runs locally; the remote value is already printed.
        return 'return new \\Psy\\CodeCleaner\\NoReturnValue();';
    }
}
