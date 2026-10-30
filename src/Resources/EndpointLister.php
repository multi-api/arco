<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * The S3 and DynamoDB gateway endpoints, the ones vpc() can name. `endpoint_missing_routes` is not decided here: it
 * depends on the subnets in use (see the Annotator).
 */
final class EndpointLister implements Lister
{
    public function type(): ResourceType
    {
        return ResourceType::Endpoint;
    }

    public function list(AwsClients $aws): array
    {
        $ec2 = $aws->ec2();
        $items = [];
        $token = null;
        do {
            $page = Aws::call($ec2, 'DescribeVpcEndpoints', [
                'Filters' => [['Name' => 'vpc-endpoint-type', 'Values' => ['Gateway']]],
                ...($token === null ? [] : ['NextToken' => $token]),
            ]);
            foreach (Aws::maps($page['VpcEndpoints'] ?? []) as $endpoint) {
                $id = Aws::string($endpoint['VpcEndpointId'] ?? null);
                $service = self::service((string) Aws::string($endpoint['ServiceName'] ?? null));
                if ($id === null || $service === null || ($endpoint['VpcEndpointType'] ?? null) !== 'Gateway') {
                    continue;
                }
                if (in_array(strtolower((string) Aws::string($endpoint['State'] ?? null)), ['deleting', 'deleted', 'failed', 'rejected', 'expired'], true)) {
                    continue;
                }
                $tags = Tags::map($endpoint['Tags'] ?? []);
                $items[] = new ResourceItem(
                    ResourceType::Endpoint,
                    $id,
                    Tags::name($tags),
                    $tags,
                    [
                        'vpc' => Aws::string($endpoint['VpcId'] ?? null),
                        'service' => $service,
                        'routeTables' => Aws::strings($endpoint['RouteTableIds'] ?? []),
                    ],
                );
            }
            $token = Aws::string($page['NextToken'] ?? null);
        } while ($token !== null);
        Vpcs::detailsOf($ec2, $items);
        usort($items, fn (ResourceItem $a, ResourceItem $b) => $a->id <=> $b->id);
        return $items;
    }

    /** `s3` or `dynamodb` for com.amazonaws.<region>.s3 / .dynamodb, else null. */
    private static function service(string $serviceName): ?string
    {
        foreach (['s3', 'dynamodb'] as $service) {
            if (str_ends_with($serviceName, '.' . $service)) {
                return $service;
            }
        }
        return null;
    }
}
