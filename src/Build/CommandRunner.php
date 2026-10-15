<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

interface CommandRunner
{
    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    public function run(array $command, string $cwd, array $env = []): string;
}
