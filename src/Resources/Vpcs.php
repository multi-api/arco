<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Aws\Ec2\Ec2Client;
use Multiapi\Arco\Deploy\Domains\Aws;

/** The VPCs of the account/region, read once: their Name tag (the group title) and whether each is the default VPC. */
final class Vpcs
{
    /** @param array<string, array{name: ?string, default: bool}> $vpcs */
    private function __construct(private readonly array $vpcs)
    {
    }

    public static function describe(Ec2Client $ec2): self
    {
        $vpcs = [];
        $token = null;
        do {
            $page = Aws::call($ec2, 'DescribeVpcs', $token === null ? [] : ['NextToken' => $token]);
            foreach (Aws::maps($page['Vpcs'] ?? []) as $vpc) {
                $id = Aws::string($vpc['VpcId'] ?? null);
                if ($id !== null) {
                    $vpcs[$id] = ['name' => Tags::name(Tags::map($vpc['Tags'] ?? [])), 'default' => ($vpc['IsDefault'] ?? false) === true];
                }
            }
            $token = Aws::string($page['NextToken'] ?? null);
        } while ($token !== null);
        return new self($vpcs);
    }

    /**
     * Turns the VPC id the items carry in their `vpc` field into {@see detail()}. Reads the VPCs only when there are items.
     *
     * @param list<ResourceItem> $items
     */
    public static function detailsOf(Ec2Client $ec2, array $items): void
    {
        if ($items === []) {
            return;
        }
        $vpcs = self::describe($ec2);
        foreach ($items as $item) {
            $item->details['vpc'] = $vpcs->detail(is_string($item->details['vpc'] ?? null) ? $item->details['vpc'] : '');
        }
    }

    /** @return array{id: string, name: ?string, default: bool} the `vpc` field of a subnet, security group or endpoint */
    public function detail(string $id): array
    {
        return ['id' => $id, 'name' => $this->vpcs[$id]['name'] ?? null, 'default' => $this->vpcs[$id]['default'] ?? false];
    }
}
