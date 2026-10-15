<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Aws\Route53\Route53Client;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Sleeper;

/** Route 53 in the account that holds the zones (the deploy account or the dns() profile's). */
final class Dns
{
    private const POLL = 5;

    /** @var list<HostedZone>|null */
    private ?array $zones = null;

    public function __construct(private readonly Route53Client $route53, private readonly Sleeper $sleeper)
    {
    }

    public function zoneFor(string $name): ?HostedZone
    {
        return HostedZone::select($this->zones ??= $this->listZones(), $name);
    }

    /**
     * Record sets named exactly $name.
     *
     * @return list<array<string, mixed>>
     */
    public function recordSets(HostedZone $zone, string $name): array
    {
        $page = Aws::call($this->route53, 'ListResourceRecordSets', ['HostedZoneId' => $zone->id, 'StartRecordName' => $name, 'MaxItems' => '100']);
        return array_values(array_filter(
            Aws::maps($page['ResourceRecordSets'] ?? []),
            fn (array $set) => Hostname::same((string) Aws::string($set['Name'] ?? null), $name),
        ));
    }

    /**
     * Applies the changes in one batch and waits until Route 53 has propagated them.
     *
     * @param list<array{0: 'UPSERT'|'DELETE', 1: array<string, mixed>}> $changes action and ResourceRecordSet
     */
    public function change(HostedZone $zone, array $changes, int $timeout = 600): void
    {
        if ($changes === []) {
            return;
        }
        $result = Aws::call($this->route53, 'ChangeResourceRecordSets', [
            'HostedZoneId' => $zone->id,
            'ChangeBatch' => [
                'Comment' => 'Arco custom domains',
                'Changes' => array_map(fn (array $c) => ['Action' => $c[0], 'ResourceRecordSet' => $c[1]], $changes),
            ],
        ]);
        $id = Aws::string(Aws::map($result['ChangeInfo'] ?? null)['Id'] ?? null);
        if ($id === null) {
            return;
        }
        for ($waited = 0; ; $waited += self::POLL) {
            $status = Aws::map(Aws::call($this->route53, 'GetChange', ['Id' => $id])['ChangeInfo'] ?? null)['Status'] ?? null;
            if ($status === 'INSYNC') {
                return;
            }
            if ($waited >= $timeout) {
                throw DeployException::make('deploy.timeout', ['what' => "DNS change $id"]);
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    /** @return list<HostedZone> */
    private function listZones(): array
    {
        $zones = [];
        $args = [];
        do {
            $page = Aws::call($this->route53, 'ListHostedZonesByName', $args);
            foreach (Aws::maps($page['HostedZones'] ?? []) as $zone) {
                $id = Aws::string($zone['Id'] ?? null);
                $name = Aws::string($zone['Name'] ?? null);
                if ($id !== null && $name !== null) {
                    $zones[] = new HostedZone($id, $name, (Aws::map($zone['Config'] ?? null)['PrivateZone'] ?? false) === true);
                }
            }
            $truncated = ($page['IsTruncated'] ?? false) === true;
            $args = ['DNSName' => Aws::string($page['NextDNSName'] ?? null), 'HostedZoneId' => Aws::string($page['NextHostedZoneId'] ?? null)];
        } while ($truncated && $args['DNSName'] !== null);
        return $zones;
    }
}
