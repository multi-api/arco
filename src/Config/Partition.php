<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/** The AWS partition of a region, for building ARNs: aws, aws-cn or aws-us-gov. */
final class Partition
{
    public static function of(string $region): string
    {
        return match (true) {
            str_starts_with($region, 'cn-') => 'aws-cn',
            str_starts_with($region, 'us-gov-') => 'aws-us-gov',
            default => 'aws',
        };
    }
}
