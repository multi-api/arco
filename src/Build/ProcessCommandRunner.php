<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

use Symfony\Component\Process\Process;

final class ProcessCommandRunner implements CommandRunner
{
    /** @param (callable(string): void)|null $onOutput */
    public function __construct(private readonly mixed $onOutput = null)
    {
    }

    public function run(array $command, string $cwd, array $env = []): string
    {
        $process = new Process($command, $cwd, $env === [] ? null : $env, null, 900);
        $onOutput = $this->onOutput;
        $process->run(is_callable($onOutput) ? static fn (string $type, string $buffer) => $onOutput($buffer) : null);
        if (! $process->isSuccessful()) {
            throw BuildException::make('build.command_failed', [
                'command' => implode(' ', $command),
                'output' => trim($process->getOutput() . "\n" . $process->getErrorOutput()),
            ]);
        }
        return $process->getOutput();
    }
}
