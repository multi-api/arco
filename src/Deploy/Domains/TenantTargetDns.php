<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;

/** @internal */
final class TenantTargetDns implements TenantTarget
{
    public function __construct(private readonly Route53Dns $dns)
    {
    }

    public function check(string $target, ?string $ownedEndpoint): void
    {
        $this->inspect($target, $ownedEndpoint);
    }

    /** $ownedEndpoint may only come from the verified stack for this environment and target. */
    public function preflight(string $target, ?string $ownedEndpoint = null): HostedZone
    {
        return $this->inspect($target, $ownedEndpoint)[0];
    }

    /** $ownedEndpoint may only come from the verified stack for this environment and target. */
    public function publish(string $target, string $endpoint, ?string $ownedEndpoint = null): void
    {
        if ($ownedEndpoint !== null && ! Hostname::same($ownedEndpoint, $endpoint)) {
            throw DeployException::make('domains.tenant_target_change', ['target' => $target]);
        }
        [$zone, $exists] = $this->inspect($target, $ownedEndpoint);
        if (! $exists) {
            $this->dns->create($zone, DnsRecord::cname($target, $endpoint));
        }
    }

    public function release(string $target, string $endpoint, AppConfig $owner): string
    {
        $zone = $this->dns->zoneFor($target);
        if ($zone === null) {
            return self::ABSENT;
        }
        $records = $this->dns->records($zone, $target);
        if ($records === []) {
            return self::ABSENT;
        }
        if (count($records) !== 1 || ! self::matches($records[0], $endpoint)) {
            return self::KEPT;
        }
        $this->dns->apply($zone, [$records[0]], [], $owner);
        return self::DELETED;
    }

    /** @return array{HostedZone, bool} */
    private function inspect(string $target, ?string $ownedEndpoint): array
    {
        $zone = $this->dns->zoneFor($target);
        if ($zone === null) {
            throw DeployException::make('domains.tenant_target_zone_missing', ['target' => $target]);
        }
        if (Hostname::same($zone->name, $target)) {
            throw DeployException::make('domains.tenant_target_apex', ['target' => $target]);
        }

        $records = $this->dns->records($zone, $target);
        if ($records === []) {
            return [$zone, false];
        }
        if (count($records) === 1 && $ownedEndpoint !== null && self::matches($records[0], $ownedEndpoint)) {
            return [$zone, true];
        }
        throw DeployException::make('domains.tenant_target_occupied', ['target' => $target]);
    }

    /** @param array<string, mixed> $record */
    private static function matches(array $record, string $endpoint): bool
    {
        $values = $record['ResourceRecords'] ?? null;
        if (($record['Type'] ?? null) !== 'CNAME'
            || array_diff(array_keys($record), ['Name', 'Type', 'TTL', 'ResourceRecords']) !== []
            || ! is_array($values) || count($values) !== 1) {
            return false;
        }
        $value = Aws::string(Aws::map($values[0] ?? null)['Value'] ?? null);
        return $value !== null && Hostname::same($value, $endpoint);
    }
}