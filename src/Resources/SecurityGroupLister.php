<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\Domains\Aws;

/** Security groups with their VPC, and whether they let everything out (a function needs outbound traffic). */
final class SecurityGroupLister implements Lister
{
    public function type(): ResourceType
    {
        return ResourceType::SecurityGroup;
    }

    public function list(AwsClients $aws): array
    {
        $ec2 = $aws->ec2();
        $items = [];
        $token = null;
        do {
            $page = Aws::call($ec2, 'DescribeSecurityGroups', $token === null ? [] : ['NextToken' => $token]);
            foreach (Aws::maps($page['SecurityGroups'] ?? []) as $group) {
                $id = Aws::string($group['GroupId'] ?? null);
                if ($id === null) {
                    continue;
                }
                $tags = Tags::map($group['Tags'] ?? []);
                $egressAll = self::egressAll($group);
                $items[] = new ResourceItem(
                    ResourceType::SecurityGroup,
                    $id,
                    Tags::name($tags) ?? Aws::string($group['GroupName'] ?? null),
                    $tags,
                    [
                        'vpc' => Aws::string($group['VpcId'] ?? null),
                        'description' => Aws::string($group['Description'] ?? null),
                        'egressAll' => $egressAll,
                    ],
                    $egressAll ? [] : [['key' => 'resources.warning.sg_no_egress', 'params' => []]],
                );
            }
            $token = Aws::string($page['NextToken'] ?? null);
        } while ($token !== null);
        Vpcs::detailsOf($ec2, $items);
        usort($items, fn (ResourceItem $a, ResourceItem $b) => $a->id <=> $b->id);
        return $items;
    }

    /**
     * An egress rule for every protocol (`-1`) to 0.0.0.0/0.
     *
     * @param array<string, mixed> $group
     */
    private static function egressAll(array $group): bool
    {
        foreach (Aws::maps($group['IpPermissionsEgress'] ?? []) as $rule) {
            if (($rule['IpProtocol'] ?? null) !== '-1') {
                continue;
            }
            foreach (Aws::maps($rule['IpRanges'] ?? []) as $range) {
                if (($range['CidrIp'] ?? null) === '0.0.0.0/0') {
                    return true;
                }
            }
        }
        return false;
    }
}
