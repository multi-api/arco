<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use GuzzleHttp\ClientInterface;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Sleeper;

/**
 * The shared infrastructure of tenantDomains(): one CloudFormation stack (multi-tenant distribution and
 * connection group) and the target's CNAME to the connection group's routing endpoint. Distribution
 * tenants are created outside the stack and hang off both resources, so nothing here replaces, moves or
 * removes them: a deploy that would is refused before it changes anything.
 *
 * @phpstan-type Infra array{target: string, endpoint: string, distributionId: string, connectionGroupId: string}
 */
final class TenantDomains
{
    /** Logical ids of the resources distribution tenants belong to. */
    public const PROTECTED = ['Distribution', 'ConnectionGroup'];

    /** @param bool $manual the user creates the record (dns(false)): print it before verifying it */
    public function __construct(private readonly Stacks $stacks, private readonly TenantTarget $dns, private readonly bool $manual = false)
    {
    }

    /**
     * @param AwsClients $aws deploy account
     * @param AwsClients|null $dnsAws account of the dns() profile; null to use the deploy account
     * @param DnsProvider|null $provider the provider when it is not Route 53 (dns('cloudflare')); without
     *     one, a Cloudflare environment fails on its first DNS operation and never touches Route 53
     * @param ClientInterface|null $http for the public DNS queries of dns(false); null for a default client
     */
    public static function create(AppConfig $config, AwsClients $aws, ?AwsClients $dnsAws, Sleeper $sleeper, ?DnsProvider $provider = null, ?ClientInterface $http = null): self
    {
        $manual = false;
        $dns = match (true) {
            $provider instanceof CloudflareDns => new CloudflareTenantTargetDns($provider, $config),
            $provider !== null || $config->dnsCloudflare => self::unavailable(),
            $config->dnsManual && $dnsAws === null => $manual = ManualTenantTargetDns::system($http),
            default => new TenantTargetDns(new Route53Dns(($dnsAws ?? $aws)->route53(), $sleeper)),
        };
        return new self(new Stacks($aws->cloudFormation(), $sleeper, 'domains.stack_'), $dns, $manual !== false);
    }

    /**
     * Everything a deploy would refuse, read-only: a stack that is not this environment's or cannot be
     * trusted, a changed target, and a target that is an apex, has no zone or is occupied.
     *
     * @throws DeployException
     */
    public function preflight(AppConfig $config): void
    {
        $this->dns->check(self::target($config), $this->deployed($config)['endpoint'] ?? null);
    }

    /**
     * @param list<string> $cachedPaths
     * @param array<string, string> $tags
     * @param callable(string, array<string, string|int>): void $step
     * @param (callable(string): void)|null $onDistribution runs with the distribution id once the stack is
     *     complete, before any DNS change: AwsDeployer lets the distribution invoke the endpoint there
     * @param bool $originAccess sign origin requests; false only behind an emulator
     * @return Infra
     */
    public function deploy(AppConfig $config, string $endpointDomain, array $cachedPaths, array $tags, callable $step, ?callable $onDistribution = null, bool $originAccess = true): array
    {
        $target = self::target($config);
        $stackName = TenantDomainStack::stackName($config);
        $previous = $this->deployed($config);
        // Before anything slow is created, so an occupied target fails in seconds.
        $this->dns->check($target, $previous['endpoint'] ?? null);

        $step('domains.step_tenant_distribution', ['stack' => $stackName]);
        $template = TenantDomainStack::template($config, $endpointDomain, $cachedPaths, $originAccess);
        $outputs = $this->stacks->deployProtected($stackName, DomainStack::body($template), $tags, self::PROTECTED);
        $current = ['target' => $target];
        foreach (['endpoint' => 'RoutingEndpoint', 'distributionId' => 'DistributionId', 'connectionGroupId' => 'ConnectionGroupId'] as $key => $output) {
            $current[$key] = ($outputs[$output] ?? '') !== '' ? $outputs[$output] : throw DeployException::make('domains.distribution_missing', ['stack' => $stackName, 'output' => $output]);
        }
        // The change set kept both resources; values that moved anyway would strand the tenants created on them.
        if ($previous !== null && $previous !== $current) {
            throw DeployException::make('domains.tenant_target_change', ['target' => $target]);
        }
        if ($onDistribution !== null) {
            $onDistribution($current['distributionId']);
        }

        $step('domains.step_tenant_dns', ['target' => $target, 'endpoint' => $current['endpoint']]);
        if ($this->manual) {
            $step('domains.manual_records', []);
            $step('domains.manual_record', ['type' => 'CNAME', 'name' => $target, 'value' => $current['endpoint']]);
        }
        // Read again by the provider: the record may have changed during the CloudFront wait.
        $this->dns->publish($target, $current['endpoint'], $previous['endpoint'] ?? null);
        return $current;
    }

    /**
     * Deletes the target's DNS record when it is exactly the CNAME to $endpoint (arco remove, once no tenant is left).
     *
     * @return 'deleted'|'absent'|'kept'|'manual' a TenantTarget outcome
     */
    public function release(string $target, string $endpoint, AppConfig $owner): string
    {
        return $this->dns->release($target, $endpoint, $owner);
    }

    /**
     * What the environment's own stack serves now; null before its first complete deploy. Only a stack
     * with this environment's tags is read, so its routing endpoint is the one record value taken as ours.
     *
     * @return Infra|null
     *
     * @throws DeployException domains.stack_not_ours, domains.tenant_stack_unusable, domains.tenant_target_changed
     */
    private function deployed(AppConfig $config): ?array
    {
        $stackName = TenantDomainStack::stackName($config);
        $stack = $this->stacks->describe($stackName);
        CustomDomains::assertOwned($stack, $stackName, $config);
        // A first creation that rolled back holds no resource: nothing is owned yet.
        if ($stack === null || in_array($stack['status'], Stacks::EMPTY, true)) {
            return null;
        }
        $outputs = $stack['outputs'];
        foreach (['Target', 'RoutingEndpoint', 'DistributionId', 'ConnectionGroupId'] as $output) {
            if (($outputs[$output] ?? '') === '') {
                // Being created or deleted (another deploy or remove), or not the stack Arco writes.
                throw DeployException::make('domains.tenant_stack_unusable', ['stack' => $stackName, 'status' => $stack['status']]);
            }
        }
        $target = self::target($config);
        if (! Hostname::same($outputs['Target'], $target)) {
            throw DeployException::make('domains.tenant_target_changed', ['target' => $target, 'current' => $outputs['Target']]);
        }
        return ['target' => $target, 'endpoint' => $outputs['RoutingEndpoint'], 'distributionId' => $outputs['DistributionId'], 'connectionGroupId' => $outputs['ConnectionGroupId']];
    }

    private static function target(AppConfig $config): string
    {
        return $config->tenantDomainTarget ?? throw new \LogicException('tenantDomains() is not configured.');
    }

    /** dns('cloudflare') without a usable provider: no fallback to Route 53 or to a record made by hand. */
    private static function unavailable(): TenantTarget
    {
        return new class implements TenantTarget {
            public function check(string $target, ?string $ownedEndpoint): void
            {
                throw self::error();
            }

            public function publish(string $target, string $endpoint, ?string $ownedEndpoint): void
            {
                throw self::error();
            }

            public function release(string $target, string $endpoint, AppConfig $owner): string
            {
                throw self::error();
            }

            private static function error(): DeployException
            {
                return DeployException::make('cloudflare.token_missing', ['env' => CloudflareApi::TOKEN_ENV, 'permissions' => CloudflareApi::PERMISSIONS]);
            }
        };
    }
}
