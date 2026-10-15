<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

final class Hostname
{
    /** Lowercase, without the trailing dot Route 53 and ACM add, with Route 53's "\052" turned back into "*". */
    public static function normalize(string $name): string
    {
        return rtrim(str_replace('\\052', '*', strtolower($name)), '.');
    }

    public static function same(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }
}
