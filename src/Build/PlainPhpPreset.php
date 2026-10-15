<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

class PlainPhpPreset implements FrameworkPreset, CachesPaths
{
    public function name(): string { return 'php'; }
    public function detects(string $projectDir): bool { return true; }
    public function prepare(string $appDir): void {}
    public function buildSteps(): array { return []; }
    public function workerScript(): ?string { return null; }
    public function publicDir(): string { return 'public'; }
    public function defaultVariables(): array { return []; }
    public function excludeRules(): array { return []; }
    public function healthPath(): string { return '/'; }
    public function cachedPaths(): array { return []; }
}
