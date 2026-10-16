<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

final readonly class ProcessResult
{
    public function __construct(public int $exitCode, public string $output, public bool $timedOut)
    {
    }
}
