<?php declare(strict_types=1);

namespace Multiapi\Arco\Package;

final readonly class Package
{
    public function __construct(public string $path, public string $hash, public int $bytes, public ?PackageManifest $manifest = null)
    {
    }
}
