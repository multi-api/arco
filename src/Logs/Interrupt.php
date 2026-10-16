<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

/**
 * Ctrl+C (and SIGTERM) end `arco logs --follow` cleanly when pcntl is available (a second one ends the
 * process at once); otherwise they end the process as usual.
 */
final class Interrupt
{
    private bool $requested = false;

    public function listen(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([\SIGINT, \SIGTERM] as $signal) {
            pcntl_signal($signal, function (int $signal): void {
                if ($this->requested) {
                    exit(128 + $signal); // a second Ctrl+C ends the process even if something hangs
                }
                $this->requested = true;
            });
        }
    }

    public function requested(): bool
    {
        return $this->requested;
    }
}
