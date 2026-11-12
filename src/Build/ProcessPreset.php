<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

use Multiapi\Arco\Config\ProcessRuntime;

/**
 * The synthetic preset of a process app: there is no framework to detect or prepare, so only the name (the language)
 * and the health path carry information.
 *
 * @internal Not covered by the 1.x compatibility promise.
 */
final class ProcessPreset implements FrameworkPreset
{
    public function __construct(private readonly ProcessRuntime $process)
    {
    }

    public function name(): string { return $this->process->language; }
    public function detects(string $projectDir): bool { return false; }
    public function prepare(string $appDir): void {}
    public function buildSteps(): array { return []; }
    public function workerScript(): ?string { return null; }
    public function publicDir(): string { return '.'; }
    public function defaultVariables(): array { return []; }
    public function excludeRules(): array { return []; }
    public function healthPath(): string { return $this->process->healthPath; }
}
