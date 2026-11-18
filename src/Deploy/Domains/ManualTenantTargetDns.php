<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;

/** @internal */
final class ManualTenantTargetDns implements TenantTarget
{
    /**
     * @param \Closure(string): (array<array-key, mixed>|false) $resolve
     * @param (\Closure(string): (string|false))|null $findZone
     * @param (\Closure(string, string): (array<array-key, mixed>|false))|null $lookup every public answer for a name
     *        and a record type (CNAME, A, AAAA): [] when the name has none, false when that is not certain
     */
    public function __construct(private readonly \Closure $resolve, private readonly ?\Closure $findZone = null, private readonly ?\Closure $lookup = null)
    {
    }

    /**
     * Arco cannot read the user's zone, only what it serves: the target must not resolve yet, or be the
     * one CNAME to the endpoint of this environment's stack (a deploy resumed after the record was created).
     */
    public function check(string $target, ?string $ownedEndpoint): void
    {
        $this->preflight($target);
        $cnames = $this->answers($target, 'CNAME');
        if ($cnames !== []) {
            if (count($cnames) === 1 && $ownedEndpoint !== null && self::isCname($cnames[0], $target, $ownedEndpoint)) {
                return;
            }
            throw DeployException::make('domains.tenant_target_occupied', ['target' => $target]);
        }
        foreach (['A', 'AAAA'] as $type) {
            if ($this->answers($target, $type) !== []) {
                throw DeployException::make('domains.tenant_target_occupied', ['target' => $target]);
            }
        }
    }

    /** The user creates the record: this only confirms the exact CNAME is public. */
    public function publish(string $target, string $endpoint, ?string $ownedEndpoint): void
    {
        if ($ownedEndpoint !== null && ! Hostname::same($ownedEndpoint, $endpoint)) {
            throw DeployException::make('domains.tenant_target_change', ['target' => $target]);
        }
        $this->verify($target, $endpoint);
    }

    /** Arco cannot read or change the user's zone: the caller prints the record to delete. */
    public function release(string $target, string $endpoint, AppConfig $owner): string
    {
        return self::MANUAL;
    }

    /** @return list<mixed> */
    private function answers(string $target, string $type): array
    {
        $answers = $this->lookup === null ? false : ($this->lookup)($target, $type);
        if (! is_array($answers)) {
            throw DeployException::make('domains.tenant_target_unverified', ['target' => $target]);
        }
        return array_values($answers);
    }

    private static function isCname(mixed $answer, string $target, string $endpoint): bool
    {
        if (! is_array($answer)) {
            return false;
        }
        $record = Aws::map($answer);
        $name = Aws::string($record['host'] ?? null);
        $value = Aws::string($record['target'] ?? null);
        return ($record['type'] ?? null) === 'CNAME' && $name !== null && $value !== null
            && Hostname::same($name, $target) && Hostname::same($value, $endpoint);
    }

    public function preflight(string $target): string
    {
        $zone = $this->findZone === null ? false : ($this->findZone)($target);
        if ($zone === false || $zone === '') {
            throw DeployException::make('domains.tenant_target_zone_missing', ['target' => $target]);
        }
        if (Hostname::same($zone, $target)) {
            throw DeployException::make('domains.tenant_target_apex', ['target' => $target]);
        }
        if (! str_ends_with(Hostname::normalize($target), '.' . Hostname::normalize($zone))) {
            throw DeployException::make('domains.tenant_target_zone_missing', ['target' => $target]);
        }
        return $zone;
    }

    /** @param ClientInterface|null $http for the public DNS queries; null for a default client */
    public static function system(?ClientInterface $http = null): self
    {
        $resolver = new PublicDnsResolver($http ?? new Client());
        return new self($resolver->resolve(...), $resolver->zone(...), $resolver->records(...));
    }

    public function verify(string $target, string $endpoint): void
    {
        $results = ($this->resolve)($target);
        if (is_array($results) && count($results) === 1 && self::isCname($results[0] ?? null, $target, $endpoint)) {
            return;
        }
        throw DeployException::make('domains.tenant_manual_cname_missing', ['target' => $target, 'endpoint' => $endpoint]);
    }
}