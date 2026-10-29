<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/**
 * The subnets and security groups of vpc(): the functions run attached to them through one Lambda network connector.
 * `endpoints` are the gateway endpoints (s3, dynamodb) in declaration order, an existing endpoint id or null to let
 * Arco manage one; repeated services are kept so the validator can report them.
 */
final class VpcConfig
{
    /**
     * @param list<string> $subnets
     * @param list<string> $securityGroups
     * @param list<array{service: string, id: ?string}> $endpoints
     */
    public function __construct(
        public readonly array $subnets,
        public readonly array $securityGroups,
        public readonly array $endpoints = [],
    ) {
    }

    /**
     * The endpoints keyed by service; a repeated service keeps the last id.
     *
     * @return array<string, ?string>
     */
    public function endpointIds(): array
    {
        $ids = [];
        foreach ($this->endpoints as $endpoint) {
            $ids[$endpoint['service']] = $endpoint['id'];
        }
        return $ids;
    }
}
