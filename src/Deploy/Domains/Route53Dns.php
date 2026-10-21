<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Aws\Route53\Route53Client;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Sleeper;

/**
 * Route 53 in the account that holds the zones (the deploy account or the dns() profile's). Domains are
 * served by A/AAAA aliases to the distribution; a record is this environment's when it points at it.
 */
final class Route53Dns implements DnsProvider
{
    /** The Route 53 operations made here: an error on one of them comes from the DNS profile's clients. */
    public const OPERATIONS = ['ListResourceRecordSets', 'ChangeResourceRecordSets', 'GetChange', 'ListHostedZonesByName'];

    /** Hosted zone id Route 53 uses for every CloudFront alias target. */
    private const CLOUDFRONT_ZONE = 'Z2FDTNDATAQYW2';
    private const TTL = 300;
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
    public function records(HostedZone $zone, string $name): array
    {
        $page = Aws::call($this->route53, 'ListResourceRecordSets', ['HostedZoneId' => $zone->id, 'StartRecordName' => $name, 'MaxItems' => '100']);
        return array_values(array_filter(
            Aws::maps($page['ResourceRecordSets'] ?? []),
            fn (array $set) => Hostname::same((string) Aws::string($set['Name'] ?? null), $name),
        ));
    }

    public function servingRecords(string $domain, string $distributionDomain): array
    {
        return DnsRecord::aliases($domain, $distributionDomain);
    }

    public function plan(array $records, string $domain, ?string $distributionDomain, bool $force, AppConfig $owner): array
    {
        return RecordGuard::plan($records, $domain, $distributionDomain, $force);
    }

    public function removable(array $records, string $domain, string $distributionDomain, AppConfig $owner): array
    {
        return RecordGuard::ourAliases($records, $domain, $distributionDomain);
    }

    public function isSatisfied(DnsRecord $record, array $existing, AppConfig $owner): bool
    {
        return self::satisfies($record, $existing);
    }

    public function describe(array $record): string
    {
        return RecordGuard::describe($record);
    }

    /** One change batch, deletes first, then the INSYNC wait. */
    public function apply(HostedZone $zone, array $deletes, array $upserts, AppConfig $owner): void
    {
        $this->change($zone, [
            ...array_map(fn (array $set) => ['DELETE', $set], $deletes),
            ...array_map(fn (DnsRecord $record) => ['UPSERT', self::recordSet($record)], $upserts),
        ]);
    }

    /**
     * Applies the changes in one batch and waits until Route 53 has propagated them.
     *
     * @param list<array{0: 'UPSERT'|'DELETE', 1: array<string, mixed>}> $changes action and ResourceRecordSet
     */
    private function change(HostedZone $zone, array $changes, int $timeout = 600): void
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

    /** @return array<string, mixed> a Route 53 ResourceRecordSet */
    private static function recordSet(DnsRecord $record): array
    {
        if ($record->alias) {
            return [
                'Name' => $record->name,
                'Type' => $record->type,
                'AliasTarget' => ['HostedZoneId' => self::CLOUDFRONT_ZONE, 'DNSName' => $record->value, 'EvaluateTargetHealth' => false],
            ];
        }
        return ['Name' => $record->name, 'Type' => $record->type, 'TTL' => self::TTL, 'ResourceRecords' => [['Value' => $record->value]]];
    }

    /**
     * True when an existing Route 53 record set already is $record, so writing it would change nothing.
     *
     * @param array<mixed> $set
     */
    private static function satisfies(DnsRecord $record, array $set): bool
    {
        $name = $set['Name'] ?? null;
        if (($set['Type'] ?? null) !== $record->type || ! is_string($name) || ! Hostname::same($name, $record->name)) {
            return false;
        }
        if ($record->alias) {
            $target = $set['AliasTarget'] ?? null;
            return is_array($target)
                && ($target['HostedZoneId'] ?? null) === self::CLOUDFRONT_ZONE
                && is_string($target['DNSName'] ?? null)
                && Hostname::same($target['DNSName'], $record->value);
        }
        $values = array_map(fn ($r) => is_array($r) && is_string($r['Value'] ?? null) ? $r['Value'] : '', (array) ($set['ResourceRecords'] ?? []));
        return count($values) === 1 && Hostname::same($values[0], $record->value);
    }
}
