<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * Route 53 hosted zones of the deploy account and, with --dns-profile, of the DNS account too. A zone both see is
 * listed once, under the deploy label. Zone tags are not read: they need a per-zone call and nothing uses them.
 */
final class ZoneLister implements Lister
{
    public function __construct(
        private readonly ?AwsClients $dns,
        private readonly string $deployLabel,
        private readonly ?string $dnsLabel,
    ) {
    }

    public function type(): ResourceType
    {
        return ResourceType::Zone;
    }

    public function list(AwsClients $aws): array
    {
        $items = $this->zones($aws, $this->deployLabel);
        if ($this->dns !== null) {
            foreach ($this->zones($this->dns, $this->dnsLabel ?? 'dns') as $id => $item) {
                $items[$id] ??= $item;
            }
        }
        $items = array_values($items);
        usort($items, fn (ResourceItem $a, ResourceItem $b) => $a->id <=> $b->id);
        return $items;
    }

    /** @return array<string, ResourceItem> by zone id */
    private function zones(AwsClients $aws, string $account): array
    {
        $route53 = $aws->route53();
        $items = [];
        $marker = null;
        do {
            $page = Aws::call($route53, 'ListHostedZones', $marker === null ? [] : ['Marker' => $marker]);
            foreach (Aws::maps($page['HostedZones'] ?? []) as $zone) {
                $id = Aws::string($zone['Id'] ?? null);
                if ($id === null) {
                    continue;
                }
                $id = preg_replace('#^/hostedzone/#', '', $id) ?? $id;
                $name = rtrim(Aws::string($zone['Name'] ?? null) ?? '', '.');
                $private = Aws::map($zone['Config'] ?? [])['PrivateZone'] ?? false;
                $records = $zone['ResourceRecordSetCount'] ?? null;
                $items[$id] = new ResourceItem(
                    ResourceType::Zone,
                    $id,
                    $name,
                    [],
                    ['name' => $name, 'private' => $private === true, 'records' => is_numeric($records) ? (int) $records : null, 'account' => $account],
                    $private === true ? [['key' => 'resources.warning.zone_private', 'params' => []]] : [],
                );
            }
            $marker = ($page['IsTruncated'] ?? false) === true ? Aws::string($page['NextMarker'] ?? null) : null;
        } while ($marker !== null);
        return $items;
    }
}
