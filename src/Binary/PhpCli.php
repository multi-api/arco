<?php declare(strict_types=1);

namespace Multiapi\Arco\Binary;

/** The static PHP CLI published next to a FrankenPHP build (same PHP version and extensions). */
final readonly class PhpCli
{
    public function __construct(public string $url, public string $sha256, public int $size)
    {
    }
}
