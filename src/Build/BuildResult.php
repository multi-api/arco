<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

use Symfony\Component\Filesystem\Filesystem;

final readonly class BuildResult
{
    /** @param list<string> $skipped */
    public function __construct(public string $stagingDir, public string $appDir, public array $skipped)
    {
    }

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->stagingDir);
    }
}
