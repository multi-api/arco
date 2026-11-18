<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;

/**
 * DNS on Cloudflare (dns('cloudflare')). Domains are served by `CNAME <domain> → <distribution>`, DNS
 * only (proxied: false: CloudFront terminates TLS; the apex works through CNAME flattening). A record
 * is an environment's when its comment is exactly `managed by arco: <app>/<env>`, Cloudflare's
 * equivalent of the arco:* tags. ACM validation CNAMEs carry `managed by arco`: ACM shares them
 * between certificates, so no environment owns them and remove never deletes them.
 *
 * @internal
 */
final class CloudflareDns implements DnsProvider
{
    public const MARKER = 'managed by arco';
    private const ADDRESS_TYPES = ['A', 'AAAA', 'CNAME'];
    /** "Automatic" in Cloudflare. */
    private const TTL = 1;

    /** @var array<string, HostedZone|null> lookups by candidate zone name */
    private array $zones = [];
    /** @var list<string> every change this provider made, across apply() calls (one deploy or remove) */
    private array $done = [];

    /** @param bool $force replace records Arco does not own (--force-dns) */
    public function __construct(private readonly CloudflareApi $api, private readonly bool $force = false)
    {
    }

    public static function marker(AppConfig $owner): string
    {
        return self::MARKER . ": {$owner->app}/{$owner->environment}";
    }

    /**
     * Before any change: the token works and every domain has a zone it can read. Missing zones are
     * refused rather than left for manual records, since dns('cloudflare') says Cloudflare holds them.
     *
     * @param list<string> $domains
     * @return list<string> the zone of each domain, without duplicates
     * @throws DeployException cloudflare.token_rejected, cloudflare.zone_missing, cloudflare.*
     */
    public function verify(array $domains): array
    {
        if ($domains === []) {
            $this->api->get('zones', ['per_page' => 5]);
            return [];
        }
        $zones = [];
        $missing = [];
        foreach ($domains as $domain) {
            $zone = $this->zoneFor($domain);
            if ($zone === null) {
                $missing[] = $domain;
                continue;
            }
            $zones[$zone->name] = true;
            // DNS read access, so a token without DNS permissions fails now rather than mid-deploy.
            $this->records($zone, $domain);
        }
        if ($missing !== []) {
            throw DeployException::make('cloudflare.zone_missing', [
                'domains' => implode(', ', $missing),
                'env' => CloudflareApi::TOKEN_ENV,
                'permissions' => CloudflareApi::PERMISSIONS,
            ]);
        }
        return array_keys($zones);
    }

    /** The longest active zone the token can see whose name is $name or a parent of it. */
    public function zoneFor(string $name): ?HostedZone
    {
        $labels = explode('.', Hostname::normalize($name));
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            if (! array_key_exists($candidate, $this->zones)) {
                $this->zones[$candidate] = $this->lookup($candidate);
            }
            if ($this->zones[$candidate] !== null) {
                return $this->zones[$candidate];
            }
        }
        return null;
    }

    public function records(HostedZone $zone, string $name): array
    {
        $result = $this->api->getAll("zones/{$zone->id}/dns_records", ['name' => Hostname::normalize($name), 'per_page' => 100]);
        $records = [];
        foreach (self::maps($result) as $record) {
            if (self::string($record['id'] ?? null) === '' || self::string($record['name'] ?? null) === ''
                || self::string($record['type'] ?? null) === '') {
                throw DeployException::make('cloudflare.list_incomplete', ['call' => "GET /zones/{$zone->id}/dns_records"]);
            }
            if (Hostname::same(self::string($record['name']), $name)) {
                $records[] = $record;
            }
        }
        return $records;
    }

    public function servingRecords(string $domain, string $distributionDomain): array
    {
        return [DnsRecord::cname($domain, $distributionDomain)];
    }

    /** @return array<string, mixed>|null */
    public function checkTenantTarget(HostedZone $zone, string $target, AppConfig $owner, ?string $ownedEndpoint): ?array
    {
        $records = $this->records($zone, $target);
        if ($records === []) {
            return null;
        }
        if (count($records) === 1 && $ownedEndpoint !== null) {
            $record = $records[0];
            if (($record['type'] ?? null) === 'CNAME' && self::owned($record, $owner)
                && Hostname::same(self::string($record['content'] ?? null), $ownedEndpoint)) {
                if (($record['proxied'] ?? null) !== false) {
                    throw DeployException::make('domains.tenant_target_proxied', ['target' => $target]);
                }
                return $record;
            }
        }
        throw DeployException::make('domains.tenant_target_occupied', ['target' => $target]);
    }

    /**
     * Deletes the tenant target's record when it is exactly this environment's: one CNAME with its marker comment
     * to $endpoint. Anything else at the name is left alone.
     *
     * @return 'deleted'|'absent'|'kept' a TenantTarget outcome
     */
    public function releaseTenantTarget(HostedZone $zone, string $target, AppConfig $owner, string $endpoint): string
    {
        $records = $this->records($zone, $target);
        if ($records === []) {
            return TenantTarget::ABSENT;
        }
        $record = $records[0];
        if (count($records) !== 1 || ($record['type'] ?? null) !== 'CNAME' || ! self::owned($record, $owner)
            || ! Hostname::same(self::string($record['content'] ?? null), $endpoint) || self::string($record['id'] ?? null) === '') {
            return TenantTarget::KEPT;
        }
        $this->apply($zone, [$record], [], $owner);
        return TenantTarget::DELETED;
    }

    public function publishTenantTarget(HostedZone $zone, string $target, string $endpoint, AppConfig $owner, ?string $ownedEndpoint): void
    {
        if ($owner->tenantDomainTarget === null || ! Hostname::same($owner->tenantDomainTarget, $target)) {
            throw DeployException::make('domains.tenant_target_occupied', ['target' => $target]);
        }
        if (Hostname::same($zone->name, $target)) {
            throw DeployException::make('domains.tenant_target_apex', ['target' => $target]);
        }
        if ($ownedEndpoint !== null && ! Hostname::same($ownedEndpoint, $endpoint)) {
            throw DeployException::make('domains.tenant_target_change', ['target' => $target]);
        }
        $current = $this->checkTenantTarget($zone, $target, $owner, $ownedEndpoint);
        if ($current !== null) {
            return;
        }
        $done = &$this->done;
        $this->write($zone, DnsRecord::cname($target, $endpoint), self::marker($owner), null, $owner, $done);
    }

    /**
     * Every A/AAAA/CNAME of $domain without this environment's marker is a conflict. The serving
     * CNAME replaces an existing CNAME in place (apply()); A/AAAA records go in the same change: the
     * environment's own always, foreign ones only with $force.
     */
    public function plan(array $records, string $domain, ?string $distributionDomain, bool $force, AppConfig $owner): array
    {
        $conflicts = [];
        $deletes = [];
        foreach (self::addressRecords($records, $domain) as $record) {
            $owned = self::owned($record, $owner);
            if (! $owned) {
                $conflicts[] = $record;
            }
            if (($record['type'] ?? null) !== 'CNAME' && ($owned || $force)) {
                $deletes[] = $record;
            }
        }
        return ['conflicts' => $conflicts, 'deletes' => $deletes];
    }

    /** The address records of $domain carrying this environment's marker. */
    public function removable(array $records, string $domain, string $distributionDomain, AppConfig $owner): array
    {
        return array_values(array_filter(self::addressRecords($records, $domain), fn (array $record) => self::owned($record, $owner)));
    }

    public function isSatisfied(DnsRecord $record, array $existing, AppConfig $owner): bool
    {
        if ($owner->tenantDomainTarget !== null && Hostname::same($owner->tenantDomainTarget, $record->name)
            && ($existing['proxied'] ?? null) !== false) {
            return false;
        }
        if (($existing['type'] ?? null) !== $record->type
            || ! Hostname::same(self::string($existing['name'] ?? null), $record->name)
            || ! Hostname::same(self::string($existing['content'] ?? null), $record->value)
            || ($existing['proxied'] ?? false) === true) {
            return false;
        }
        return ! self::serves($record, $owner) || self::owned($existing, $owner);
    }

    public function describe(array $record): string
    {
        $content = self::string($record['content'] ?? null);
        return Hostname::normalize(self::string($record['name'] ?? null)) . ' ' . self::string($record['type'] ?? null) . ' → ' . ($content === '' ? '?' : Hostname::normalize($content));
    }

    /**
     * Deletes first, then each upsert: the record of that name is re-read and updated in place (by its
     * id) or created. A failure after some changes names what was already changed.
     */
    public function apply(HostedZone $zone, array $deletes, array $upserts, AppConfig $owner): void
    {
        $done = &$this->done;
        try {
            foreach ($deletes as $record) {
                $this->deleteRecord($zone, $record, $done);
            }
            foreach ($upserts as $record) {
                self::serves($record, $owner)
                    ? $this->upsertServing($zone, $record, $owner, $done)
                    : $this->upsertValidation($zone, $record, $done);
            }
        } catch (DeployException $e) {
            if ($done === []) {
                throw $e;
            }
            $lines = implode("\n", array_map(fn (string $line) => "  $line", $done));
            // A record that appeared since the guard read the zone: its list, with what already changed.
            if (isset($e->params['records'])) {
                throw DeployException::make('cloudflare.partial_conflict', ['records' => (string) $e->params['records'], 'done' => $lines]);
            }
            throw DeployException::make('cloudflare.partial', ['detail' => (string) ($e->params['detail'] ?? $e->key), 'done' => $lines]);
        }
    }

    /** @param list<string> $done */
    private function upsertServing(HostedZone $zone, DnsRecord $record, AppConfig $owner, array &$done): void
    {
        $existing = self::addressRecords($this->records($zone, $record->name), $record->name);
        $foreign = array_values(array_filter($existing, fn (array $r) => ! self::owned($r, $owner)));
        if ($foreign !== [] && ! $this->force) {
            // Created since the guard read the zone.
            throw DeployException::make('domains.record_conflict', ['records' => implode("\n", array_map(fn (array $r) => '  ' . $this->describe($r), $foreign))]);
        }
        $cname = null;
        foreach ($existing as $current) {
            if ($cname === null && ($current['type'] ?? null) === 'CNAME') {
                $cname = $current;
            } else {
                $this->deleteRecord($zone, $current, $done);
            }
        }
        $this->write($zone, $record, self::marker($owner), $cname, $owner, $done);
    }

    /**
     * An identical CNAME (any comment) is left alone; anything else at the name is refused unless
     * --force-dns, which replaces it.
     *
     * @param list<string> $done
     */
    private function upsertValidation(HostedZone $zone, DnsRecord $record, array &$done): void
    {
        $existing = $this->records($zone, $record->name);
        foreach ($existing as $current) {
            if (($current['type'] ?? null) !== $record->type || ! Hostname::same(self::string($current['content'] ?? null), $record->value)) {
                continue;
            }
            if (($current['proxied'] ?? false) !== true) {
                return;
            }
            // Proxied, ACM cannot see it: ours is set to DNS only; another one is a conflict like any other.
            if (self::arcoComment($current)) {
                $this->write($zone, $record, self::string($current['comment'] ?? null), $current, null, $done);
                return;
            }
        }
        if ($existing !== [] && ! $this->force) {
            throw DeployException::make('cloudflare.validation_conflict', ['records' => implode("\n", array_map(fn (array $r) => '  ' . $this->describe($r), $existing))]);
        }
        $cname = null;
        foreach ($existing as $current) {
            if ($cname === null && ($current['type'] ?? null) === 'CNAME') {
                $cname = $current;
            } else {
                $this->deleteRecord($zone, $current, $done);
            }
        }
        $this->write($zone, $record, self::MARKER, $cname, null, $done);
    }

    /**
     * Updates $current in place (by id) or creates the record; nothing when $current already is it.
     *
     * @param array<string, mixed>|null $current
     * @param list<string> $done
     */
    private function write(HostedZone $zone, DnsRecord $record, string $comment, ?array $current, ?AppConfig $owner, array &$done): void
    {
        if ($current !== null && $owner !== null && $this->isSatisfied($record, $current, $owner)) {
            return;
        }
        $body = ['type' => $record->type, 'name' => $record->name, 'content' => $record->value, 'ttl' => self::TTL, 'proxied' => false, 'comment' => $comment];
        $id = $current === null ? null : self::string($current['id'] ?? null);
        if ($id === null || $id === '') {
            $this->api->post("zones/{$zone->id}/dns_records", $body);
            $done[] = "created $record->name $record->type → $record->value";
            return;
        }
        $this->api->patch("zones/{$zone->id}/dns_records/$id", $body);
        $done[] = 'updated ' . $this->describe($current) . " to $record->value (DNS only)";
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string> $done
     */
    private function deleteRecord(HostedZone $zone, array $record, array &$done): void
    {
        $id = self::string($record['id'] ?? null);
        if ($id === '') {
            return;
        }
        $this->api->delete("zones/{$zone->id}/dns_records/$id");
        $done[] = 'deleted ' . $this->describe($record);
    }

    private function lookup(string $name): ?HostedZone
    {
        $selected = null;
        foreach (self::maps($this->api->getAll('zones', ['name' => $name, 'status' => 'active', 'per_page' => 5])) as $zone) {
            $id = self::string($zone['id'] ?? null);
            $zoneName = self::string($zone['name'] ?? null);
            if ($id === '' || $zoneName === '') {
                throw DeployException::make('cloudflare.list_incomplete', ['call' => 'GET /zones']);
            }
            if (Hostname::same($zoneName, $name)) {
                if ($selected !== null) {
                    throw DeployException::make('cloudflare.list_incomplete', ['call' => 'GET /zones']);
                }
                $selected = new HostedZone($id, Hostname::normalize($zoneName), false);
            }
        }
        return $selected;
    }

    /** A serving or tenant target record, as opposed to an ACM validation CNAME. */
    private static function serves(DnsRecord $record, AppConfig $owner): bool
    {
        if ($owner->tenantDomainTarget !== null && Hostname::same($owner->tenantDomainTarget, $record->name)) {
            return true;
        }
        foreach ($owner->domains as $domain) {
            if (Hostname::same($domain, $record->name)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $record a comment Arco writes: `managed by arco` or an environment's marker */
    private static function arcoComment(array $record): bool
    {
        $comment = self::string($record['comment'] ?? null);
        return $comment === self::MARKER || str_starts_with($comment, self::MARKER . ': ');
    }

    /** @param array<string, mixed> $record */
    private static function owned(array $record, AppConfig $owner): bool
    {
        return ($record['comment'] ?? null) === self::marker($owner);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private static function addressRecords(array $records, string $name): array
    {
        return array_values(array_filter(
            $records,
            fn (array $r) => in_array($r['type'] ?? null, self::ADDRESS_TYPES, true) && Hostname::same(self::string($r['name'] ?? null), $name),
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function maps(mixed $value): array
    {
        $maps = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $maps[] = $item;
            }
        }
        return $maps;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
