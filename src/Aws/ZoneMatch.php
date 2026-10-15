<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

/** The public hosted zone that holds a domain, and the profile and account it was found with. */
final readonly class ZoneMatch
{
    public function __construct(public string $profile, public string $account, public string $zoneId, public string $zoneName)
    {
    }
}
