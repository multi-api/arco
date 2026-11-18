<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;

/** @internal */
final class CloudflareTenantTargetDns implements TenantTarget
{
    public function __construct(private readonly CloudflareDns $dns, private readonly AppConfig $owner)
    {
    }

    public function check(string $target, ?string $ownedEndpoint): void
    {
        $this->preflight($target, $ownedEndpoint);
    }

    public function preflight(string $target, ?string $ownedEndpoint = null): HostedZone
    {
        $zone = $this->dns->zoneFor($target);
        if ($zone === null) {
            throw DeployException::make('domains.tenant_target_zone_missing', ['target' => $target]);
        }
        if (Hostname::same($zone->name, $target)) {
            throw DeployException::make('domains.tenant_target_apex', ['target' => $target]);
        }
        $this->dns->checkTenantTarget($zone, $target, $this->owner, $ownedEndpoint);
        return $zone;
    }

    public function publish(string $target, string $endpoint, ?string $ownedEndpoint = null): void
    {
        if ($ownedEndpoint !== null && ! Hostname::same($ownedEndpoint, $endpoint)) {
            throw DeployException::make('domains.tenant_target_change', ['target' => $target]);
        }
        $zone = $this->preflight($target, $ownedEndpoint);
        $this->dns->publishTenantTarget($zone, $target, $endpoint, $this->owner, $ownedEndpoint);
    }

    public function release(string $target, string $endpoint, AppConfig $owner): string
    {
        $zone = $this->dns->zoneFor($target);
        return $zone === null ? self::ABSENT : $this->dns->releaseTenantTarget($zone, $target, $owner, $endpoint);
    }
}
