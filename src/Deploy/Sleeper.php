<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Closure;

class Sleeper
{
    /** Not promoted: subclasses (test fakes) may skip this constructor. */
    private ?Closure $onSleep = null;

    /**
     * @param (Closure(): void)|null $onSleep called after each sleep: every wait loop of the deploy sleeps
     *                                       here, so it is the heartbeat that shows the elapsed time
     */
    public function __construct(?Closure $onSleep = null)
    {
        $this->onSleep = $onSleep;
    }

    public function sleep(int $seconds): void
    {
        sleep($seconds);
        if ($this->onSleep !== null) {
            ($this->onSleep)();
        }
    }

    /** Wall-clock seconds, for deadlines; tests advance it with sleep(). */
    public function now(): float
    {
        return microtime(true);
    }
}
