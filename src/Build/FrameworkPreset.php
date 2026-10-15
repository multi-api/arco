<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

interface FrameworkPreset
{
    public function name(): string;

    public function detects(string $projectDir): bool;

    /** File changes in the staged app after composer install (e.g. copy a worker script). Throws BuildException. */
    public function prepare(string $appDir): void;

    /** @return list<list<string>> commands run in the staged app dir */
    public function buildSteps(): array;

    /** Worker script relative to the app dir, or null for classic mode. */
    public function workerScript(): ?string;

    /** Public dir relative to the app dir. */
    public function publicDir(): string;

    /** @return array<string, string> */
    public function defaultVariables(): array;

    /** @return list<string> glob patterns excluded from the package */
    public function excludeRules(): array;

    /** Health check path used by deploy verification. */
    public function healthPath(): string;
}
