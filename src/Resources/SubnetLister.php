<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Aws\Ec2\Ec2Client;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\Domains\Aws;

/** Subnets with the VPC they belong to, the route table they use, their free addresses and how they reach the internet. */
final class SubnetLister implements Lister
{
    /** Fewer free addresses than this is worth a warning. */
    public const FEW_IPS = 256;

    public function type(): ResourceType
    {
        return ResourceType::Subnet;
    }

    public function list(AwsClients $aws): array
    {
        return $this->items($aws, self::describe($aws->ec2(), []));
    }

    /**
     * The items for DescribeSubnets results already in hand (vpc:set has them from its own check). Route tables and
     * VPC names are read once for all of them.
     *
     * @param list<array<string, mixed>> $subnets
     * @return list<ResourceItem>
     */
    public function items(AwsClients $aws, array $subnets): array
    {
        if ($subnets === []) {
            return [];
        }
        $ec2 = $aws->ec2();
        [$explicit, $main] = $this->routeTables($ec2);
        $vpcs = Vpcs::describe($ec2);

        $items = [];
        foreach ($subnets as $subnet) {
            $id = Aws::string($subnet['SubnetId'] ?? null);
            if ($id === null) {
                continue;
            }
            $vpcId = Aws::string($subnet['VpcId'] ?? null) ?? '';
            $table = $explicit[$id] ?? $main[$vpcId] ?? null;
            $egress = $table === null ? 'none' : SubnetEgress::of($table);
            $free = is_int($subnet['AvailableIpAddressCount'] ?? null) ? $subnet['AvailableIpAddressCount'] : 0;
            $tags = Tags::map($subnet['Tags'] ?? []);
            $items[] = new ResourceItem(
                ResourceType::Subnet,
                $id,
                Tags::name($tags),
                $tags,
                [
                    'vpc' => $vpcs->detail($vpcId),
                    'az' => Aws::string($subnet['AvailabilityZoneId'] ?? null),
                    'cidr' => Aws::string($subnet['CidrBlock'] ?? null),
                    'freeIps' => $free,
                    'egress' => $egress,
                    'routeTable' => $table === null ? null : Aws::string($table['RouteTableId'] ?? null),
                ],
                self::warnings($egress, $free),
            );
        }
        usort($items, fn (ResourceItem $a, ResourceItem $b) => $a->id <=> $b->id);
        return $items;
    }

    /**
     * The warnings of §5.2 for a subnet.
     *
     * @param 'nat'|'igw'|'none' $egress
     * @return list<array{key: string, params: array<string, string>}>
     */
    public static function warnings(string $egress, int $free): array
    {
        $warnings = [];
        if ($egress === 'igw') {
            $warnings[] = ['key' => 'resources.warning.subnet_public', 'params' => []];
        } elseif ($egress === 'none') {
            $warnings[] = ['key' => 'resources.warning.subnet_no_egress', 'params' => []];
        }
        if ($free < self::FEW_IPS) {
            $warnings[] = ['key' => 'resources.warning.subnet_few_ips', 'params' => ['free' => (string) $free]];
        }
        return $warnings;
    }

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private static function describe(Ec2Client $ec2, array $ids): array
    {
        $subnets = [];
        $token = null;
        do {
            $page = Aws::call($ec2, 'DescribeSubnets', [...($ids === [] ? [] : ['SubnetIds' => $ids]), ...($token === null ? [] : ['NextToken' => $token])]);
            $subnets = [...$subnets, ...Aws::maps($page['Subnets'] ?? [])];
            $token = Aws::string($page['NextToken'] ?? null);
        } while ($token !== null);
        return $subnets;
    }

    /**
     * Every route table of the account/region once: by explicitly associated subnet, and each VPC's main table.
     * A subnet without an association uses its VPC's main table.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function routeTables(Ec2Client $ec2): array
    {
        $explicit = [];
        $main = [];
        $token = null;
        do {
            $page = Aws::call($ec2, 'DescribeRouteTables', $token === null ? [] : ['NextToken' => $token]);
            foreach (Aws::maps($page['RouteTables'] ?? []) as $table) {
                foreach (Aws::maps($table['Associations'] ?? []) as $association) {
                    $subnet = Aws::string($association['SubnetId'] ?? null);
                    if ($subnet !== null) {
                        $explicit[$subnet] = $table;
                    }
                    $vpc = Aws::string($table['VpcId'] ?? null);
                    if (($association['Main'] ?? false) === true && $vpc !== null) {
                        $main[$vpc] = $table;
                    }
                }
            }
            $token = Aws::string($page['NextToken'] ?? null);
        } while ($token !== null);
        return [$explicit, $main];
    }
}
