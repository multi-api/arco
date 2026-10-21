<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;

/**
 * Stands in for a provider that cannot be used (dns('cloudflare') without a token): every DNS operation
 * fails with the reason, so custom domains never fall back to another provider (Route 53) or to manual
 * records. Commands check the token before any change; this is the safety net behind them.
 *
 * @internal
 */
final class UnavailableDns implements DnsProvider
{
    /** @param array<string, string|int> $params */
    public function __construct(private readonly string $key, private readonly array $params = [])
    {
    }

    public static function cloudflareTokenMissing(): self
    {
        return new self('cloudflare.token_missing', ['env' => CloudflareApi::TOKEN_ENV, 'permissions' => CloudflareApi::PERMISSIONS]);
    }

    public function zoneFor(string $name): ?HostedZone
    {
        throw $this->error();
    }

    public function records(HostedZone $zone, string $name): array
    {
        throw $this->error();
    }

    public function servingRecords(string $domain, string $distributionDomain): array
    {
        throw $this->error();
    }

    public function plan(array $records, string $domain, ?string $distributionDomain, bool $force, AppConfig $owner): array
    {
        throw $this->error();
    }

    public function removable(array $records, string $domain, string $distributionDomain, AppConfig $owner): array
    {
        throw $this->error();
    }

    public function isSatisfied(DnsRecord $record, array $existing, AppConfig $owner): bool
    {
        throw $this->error();
    }

    public function describe(array $record): string
    {
        throw $this->error();
    }

    public function apply(HostedZone $zone, array $deletes, array $upserts, AppConfig $owner): void
    {
        throw $this->error();
    }

    private function error(): DeployException
    {
        return DeployException::make($this->key, $this->params);
    }
}
