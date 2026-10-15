<?php declare(strict_types=1);

namespace Multiapi\Arco\Package;

use RuntimeException;

final class PackageTooLarge extends RuntimeException
{
    /** @param array<string, int> $largest */
    public function __construct(public readonly int $bytes, public readonly int $limit, public readonly array $largest)
    {
        parent::__construct("Package is $bytes bytes, limit is $limit");
    }
}
