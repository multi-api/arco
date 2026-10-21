<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Multiapi\Arco\Config\AppConfig;

/**
 * The DNS operations custom domains need from the service that holds the zones. CustomDomains owns
 * the flow (guard, write, remove); a provider owns its record format and its ownership rule.
 *
 * Existing records are provider-native maps (a Route 53 ResourceRecordSet, a Cloudflare DNS record
 * object): they are only handed back to the same provider, so a DELETE can name exactly what was read.
 * $owner is the environment the records belong to, for providers that mark them (Route 53 does not:
 * its ownership is the alias target).
 *
 * @internal
 */
interface DnsProvider
{
    /** The longest public zone whose name is $name or a parent of it. */
    public function zoneFor(string $name): ?HostedZone;

    /**
     * Records named exactly $name.
     *
     * @return list<array<string, mixed>>
     */
    public function records(HostedZone $zone, string $name): array;

    /**
     * The records that point $domain at the CloudFront distribution.
     *
     * @return list<DnsRecord>
     */
    public function servingRecords(string $domain, string $distributionDomain): array;

    /**
     * conflicts: address records (A/AAAA/CNAME) of $domain the environment does not own, by the
     * provider's ownership rule; deploy refuses while there are any, unless $force.
     * deletes: records that must go in the same change as the serving records; a foreign one only
     * when $force is set.
     *
     * @param list<array<string, mixed>> $records as returned by records()
     * @return array{conflicts: list<array<string, mixed>>, deletes: list<array<string, mixed>>}
     */
    public function plan(array $records, string $domain, ?string $distributionDomain, bool $force, AppConfig $owner): array;

    /**
     * The records of $domain that remove deletes: those serving this environment's distribution.
     *
     * @param list<array<string, mixed>> $records as returned by records()
     * @return list<array<string, mixed>>
     */
    public function removable(array $records, string $domain, string $distributionDomain, AppConfig $owner): array;

    /**
     * True when $existing already is $record (and owned), so writing it would change nothing.
     *
     * @param array<string, mixed> $existing as returned by records()
     */
    public function isSatisfied(DnsRecord $record, array $existing, AppConfig $owner): bool;

    /** @param array<string, mixed> $record as returned by records(); "<name> <type> → <target>" */
    public function describe(array $record): string;

    /**
     * Deletes $deletes and creates or replaces $upserts (marked as $owner's) in $zone, and waits until
     * the change is live. Nothing is called when both are empty.
     *
     * @param list<array<string, mixed>> $deletes as returned by records()
     * @param list<DnsRecord> $upserts
     */
    public function apply(HostedZone $zone, array $deletes, array $upserts, AppConfig $owner): void;
}
