<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/** The subnets and security groups of vpc(): the functions run attached to them through one Lambda network connector. */
final class VpcConfig
{
    /**
     * @param list<string> $subnets
     * @param list<string> $securityGroups
     */
    public function __construct(
        public readonly array $subnets,
        public readonly array $securityGroups,
    ) {
    }
}
