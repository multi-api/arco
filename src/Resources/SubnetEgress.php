<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Multiapi\Arco\Deploy\Domains\Aws;

/** How a subnet reaches the internet, from its route table. */
final class SubnetEgress
{
    /**
     * `nat`: the default route goes to a NAT gateway, a NAT instance or a network interface. `igw`: to an internet
     * gateway (a public subnet: a Lambda function gets no public IP there). `none`: no default route.
     *
     * @param array<array-key, mixed> $routeTable a DescribeRouteTables `RouteTable`
     * @return 'nat'|'igw'|'none'
     */
    public static function of(array $routeTable): string
    {
        foreach (Aws::maps($routeTable['Routes'] ?? []) as $route) {
            if (($route['DestinationCidrBlock'] ?? null) !== '0.0.0.0/0') {
                continue;
            }
            if (Aws::string($route['NatGatewayId'] ?? null) !== null || Aws::string($route['InstanceId'] ?? null) !== null || Aws::string($route['NetworkInterfaceId'] ?? null) !== null) {
                return 'nat';
            }
            if (str_starts_with((string) Aws::string($route['GatewayId'] ?? null), 'igw-')) {
                return 'igw';
            }
        }
        return 'none';
    }
}
