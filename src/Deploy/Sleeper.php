<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

class Sleeper
{
    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /** Wall-clock seconds, for deadlines; tests advance it with sleep(). */
    public function now(): float
    {
        return microtime(true);
    }
}
