<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\CloudFront\CloudFrontClient;
use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Domains\Aws;
use Multiapi\Arco\Deploy\Domains\CustomDomains;
use Multiapi\Arco\Deploy\Domains\Stacks;
use Multiapi\Arco\Deploy\Domains\TenantDomainStack;
use Multiapi\Arco\Deploy\Domains\TenantTarget;

/**
 * What `arco remove` does about tenantDomains() before anything else of the environment goes. Customer domains
 * (distribution tenants) hang off the tenant distribution and are created at runtime by the mediator function, so
 * the distribution may only be deleted when no tenant is left, and a tenant may only be deleted by an explicit
 * --force. Fail closed everywhere:
 *
 *  1. Ownership, read-only: the tenant stack, the mediator function and its role. Anything not this
 *     environment's, or unreadable, stops the remove with nothing changed.
 *  2. The mediator is drained: reserved concurrency 0, read back, then a wait of the function's own timeout plus
 *     PROPAGATION_MARGIN seconds for invocations already running. From here on the concurrency stays 0 whatever
 *     happens: remove never restores it.
 *  3. A fresh full listing of the distribution's tenants. A tenant that is not the environment's stops the remove
 *     (the distribution is never deleted under someone else's tenant); operations in progress are waited for.
 *  4. Owned tenants are deleted only with $forceTenants (the command line's explicit --force, never --yes or a
 *     typed confirmation): disabled, then deleted once CloudFront accepts it, ownership re-read before each
 *     change. A final listing must be empty.
 *  5. Then the tenant infrastructure goes: the target's DNS record when it is exactly the CNAME to the stack's
 *     routing endpoint, the CloudFront statement on the endpoint, the stack, the function and the role. A piece
 *     that is already gone is skipped, so a remove that stopped half way is resumed by running it again.
 */
final class TenantRemoval
{
    /** Seconds added to the function's timeout before the count: Lambda needs a moment to apply a concurrency of 0. */
    public const PROPAGATION_MARGIN = 60;
    /** How long a listing may show operations in progress before the remove stops, and between two listings. */
    public const SETTLE_TIMEOUT = 600;
    public const SETTLE_POLL = 15;
    /** How long one tenant may take to become deletable after it is disabled. */
    public const DELETE_TIMEOUT = 900;
    public const DELETE_POLL = 15;
    /** How long tenants this remove just deleted may still be listed before the final count stops the remove. */
    public const RECOUNT_TIMEOUT = 300;

    private const DENIED = ['AccessDenied', 'AccessDeniedException'];

    /**
     * @param Closure(string, string, AppConfig): string $releaseDns deletes the target's record when it is exactly the CNAME
     *        to the endpoint; returns a TenantTarget outcome (TenantDomains::release())
     * @param Closure(string): bool $revoke removes Arco's tenant statement from the Web Function's resource policy
     *        (CloudFrontAccess::revokeTenant())
     */
    public function __construct(
        private readonly AwsClients $aws,
        private readonly Sleeper $sleeper,
        private readonly Closure $releaseDns,
        private readonly Closure $revoke,
    ) {
    }

    /**
     * @param bool $forceTenants --force was given explicitly: owned tenants may be deleted
     * @param string|null $webFunctionArn the environment's Web Function, when it exists (its policy holds the statement)
     * @param callable(string, array<string, string|int>): void $step
     * @return bool whether the environment had tenant resources
     * @throws DeployException
     * @throws AwsException a failed read before anything changed
     */
    public function remove(AppConfig $config, bool $forceTenants, ?string $webFunctionArn, callable $step): bool
    {
        $stackName = TenantDomainStack::stackName($config);
        $function = $config->tenantMediatorFunctionName();
        // What the messages that say how to cancel a stopped remove (`arco tenants:restore --env=<environment>`) need.
        $ctx = ['function' => $function, 'environment' => $config->environment];
        $roleName = TenantMediator::roleName($config);

        // 1. Detection and ownership, read-only.
        $stacks = new Stacks($this->aws->cloudFormation(), $this->sleeper, 'domains.stack_');
        $stack = null;
        $stackDenied = null;
        try {
            $stack = $stacks->describe($stackName);
        } catch (AwsException $e) {
            if (! in_array($e->getAwsErrorCode(), self::DENIED, true)) {
                throw $e;
            }
            $stackDenied = $e;
        }
        // `arco-<app>-X-tenants` is also the custom domains stack of an environment called X-tenants. Without
        // tenantDomains(), a stack at that name that is not tagged for this environment is not our tenant stack: it is
        // ignored and never touched.
        if ($stack !== null && $config->tenantDomainTarget === null
            && (($stack['tags'][Ownership::APP] ?? null) !== $config->app || ($stack['tags'][Ownership::ENVIRONMENT] ?? null) !== $config->environment)) {
            $stack = null;
        }
        // A profile scoped to the documented minimal permissions cannot read the mediator's function or role, and an
        // environment that never had tenant domains does not need to: with no tenantDomains() in arco.php and no
        // tenant stack, a refused read of either is "does not exist". Any other case stops on a refused read.
        $tolerant = $config->tenantDomainTarget === null;
        $guard = new OwnershipGuard($this->aws);
        $denied = null;
        $mediator = null;
        $role = null;
        try {
            $mediator = $guard->lambdaFunction($function, TenantMediator::MARKER);
        } catch (AwsException $e) {
            $denied = self::isDenied($e) ? $e : throw $e;
        }
        try {
            $role = $guard->role($roleName);
        } catch (AwsException $e) {
            $denied ??= self::isDenied($e) ? $e : throw $e;
        }
        // Another environment may own a function or role at the mediator's names (`<app>-X-tenants` is also the name of
        // an environment called X-tenants). Without tenantDomains() and without a stack, only a resource carrying this
        // environment's tags (and, for the function, the mediator's description) is a leftover of ours; the rest is
        // not a tenant resource of this environment and is never touched.
        if ($tolerant && $stack === null) {
            $mediator = $mediator !== null && self::isOurs($config, $mediator, true) ? $mediator : null;
            $role = $role !== null && self::isOurs($config, $role, false) ? $role : null;
        }
        if ($stack === null && $mediator === null && $role === null) {
            if (! $tolerant && ($stackDenied !== null || $denied !== null)) {
                throw $stackDenied !== null
                    ? DeployException::make('remove.tenant_stack_unreadable', ['stack' => $stackName, 'error' => TenantInventory::describe($stackDenied)])
                    : $denied;
            }
            // Without a stack, a function and a role (a profile that cannot read them included) there is nothing of
            // the tenant domains Arco could delete.
            return false;
        }
        if ($stackDenied !== null) {
            throw DeployException::make('remove.tenant_stack_unreadable', ['stack' => $stackName, 'error' => TenantInventory::describe($stackDenied)]);
        }
        if ($denied !== null) {
            // Something of the tenant domains exists and another piece cannot be read: nothing is changed.
            throw $denied;
        }
        CustomDomains::assertOwned($stack, $stackName, $config);
        foreach ([$mediator, $role] as $resource) {
            if ($resource !== null) {
                self::assertOwned($config, $resource);
            }
        }
        $infra = $this->infra($stack, $stackName);

        try {
            // 2. Drain.
            if ($mediator !== null) {
                $this->drain($ctx, $step);
            }

            // 3 and 4. Count, then delete with --force.
            if ($infra !== null) {
                $this->tenants($config, $infra, $ctx, $forceTenants, $step);
            }

            // 5. The tenant infrastructure.
            if ($infra !== null) {
                $this->releaseDns($config, $infra, $step);
            }
            if ($stack !== null && $webFunctionArn !== null) {
                $step('remove.step_tenant_access', ['function' => $config->functionName()]);
                ($this->revoke)($webFunctionArn);
            }
            if ($stack !== null) {
                $step('remove.step_tenant_stack', ['stack' => $stackName]);
                $stacks->delete($stackName);
            }
            if ($mediator !== null) {
                $step('remove.step_tenant_function', ['function' => $function]);
                (new LambdaFunction($this->aws->lambda(), $this->sleeper, 'deploy.tenant_mediator_missing', 'deploy.tenant_mediator_failed'))->delete($function);
            }
            if ($role !== null) {
                $step('remove.step_tenant_role', ['role' => $roleName]);
                (new ExecutionRole($this->aws->iam()))->delete($roleName);
            }
        } catch (\Throwable $e) {
            $step('remove.tenant_stopped', $ctx);
            if ($e instanceof AwsException) {
                throw DeployException::make('remove.tenant_failed', [...$ctx, 'error' => TenantInventory::describe($e)], $e);
            }
            throw $e;
        }
        return true;
    }

    private static function isOurs(AppConfig $config, NamedResource $resource, bool $marker): bool
    {
        return $resource->unreadable === null
            && ($resource->tags[Ownership::APP] ?? null) === $config->app
            && ($resource->tags[Ownership::ENVIRONMENT] ?? null) === $config->environment
            && (! $marker || $resource->arcoMade);
    }

    private static function isDenied(AwsException $e): bool
    {
        return in_array($e->getAwsErrorCode(), self::DENIED, true);
    }

    /**
     * Reserved concurrency 0, confirmed, then the wait for invocations in flight. The function's configured timeout
     * is read, not assumed: a deploy of another Arco version may have changed it.
     *
     * @param callable(string, array<string, string|int>): void $step
     * @param array{function: string, environment: string} $ctx the mediator and environment the stop messages name
     */
    private function drain(array $ctx, callable $step): void
    {
        $function = $ctx['function'];
        $lambda = $this->aws->lambda();
        $step('remove.step_tenant_drain', $ctx);
        try {
            $lambda->putFunctionConcurrency(['FunctionName' => $function, 'ReservedConcurrentExecutions' => 0]);
            $reserved = Aws::call($lambda, 'GetFunctionConcurrency', ['FunctionName' => $function])['ReservedConcurrentExecutions'] ?? null;
            $timeout = Aws::call($lambda, 'GetFunctionConfiguration', ['FunctionName' => $function])['Timeout'] ?? null;
        } catch (AwsException $e) {
            throw DeployException::make('remove.tenant_drain_failed', [...$ctx, 'error' => TenantInventory::describe($e)], $e);
        }
        if ($reserved !== 0) {
            throw DeployException::make('remove.tenant_drain_unconfirmed', [...$ctx, 'value' => is_int($reserved) ? (string) $reserved : 'none']);
        }
        if (! is_int($timeout) || $timeout < 1) {
            throw DeployException::make('remove.tenant_drain_failed', [...$ctx, 'error' => 'the function timeout cannot be read']);
        }
        $seconds = $timeout + self::PROPAGATION_MARGIN;
        $step('remove.step_tenant_wait', ['function' => $function, 'seconds' => $seconds]);
        for ($left = $seconds; $left > 0; $left -= self::SETTLE_POLL) {
            $this->sleeper->sleep(min(self::SETTLE_POLL, $left));
        }
    }

    /**
     * @param array{distribution: string, connectionGroup: string, target: string, endpoint: string} $infra
     * @param callable(string, array<string, string|int>): void $step
     * @param array{function: string, environment: string} $ctx the mediator and environment the stop messages name
     */
    private function tenants(AppConfig $config, array $infra, array $ctx, bool $forceTenants, callable $step): void
    {
        $inventory = new TenantInventory($this->aws->cloudFront(), $infra['distribution'], $infra['connectionGroup'], $config, 'remove.tenant_', $ctx);
        $tenants = $this->settled($inventory, $ctx, $step);
        if ($tenants !== [] && ! $forceTenants) {
            throw DeployException::make('remove.tenants_need_force', ['count' => count($tenants), ...$ctx]);
        }
        $step('remove.step_tenant_count', ['count' => count($tenants)]);
        if ($tenants === []) {
            return;
        }
        $deleted = [];
        foreach ($tenants as $tenant) {
            $step('remove.step_tenant_delete', ['tenant' => $tenant->id, 'domains' => $tenant->label()]);
            $this->delete($inventory, $tenant->id, $ctx);
            $deleted[$tenant->id] = true;
        }
        $deadline = $this->sleeper->now() + self::RECOUNT_TIMEOUT;
        do {
            $left = $inventory->scan();
            if ($left === []) {
                return;
            }
            // Only tenants this remove just deleted may still show for a moment; anything else is new.
            $others = array_filter($left, static fn (Tenant $tenant): bool => ! isset($deleted[$tenant->id]) || ! $tenant->owned);
            if ($others !== [] || $this->sleeper->now() >= $deadline) {
                throw DeployException::make('remove.tenant_recount', ['count' => count($left), ...$ctx]);
            }
            $this->sleeper->sleep(self::SETTLE_POLL);
        } while (true);
    }

    /**
     * A listing in which no operation is in progress and every tenant is the environment's.
     *
     * @param callable(string, array<string, string|int>): void $step
     * @param array{function: string, environment: string} $ctx the mediator and environment the stop messages name
     * @return list<Tenant>
     */
    private function settled(TenantInventory $inventory, array $ctx, callable $step): array
    {
        $deadline = $this->sleeper->now() + self::SETTLE_TIMEOUT;
        $announced = false;
        do {
            $tenants = $inventory->scan();
            foreach ($tenants as $tenant) {
                if (! $tenant->owned) {
                    throw DeployException::make('remove.tenant_foreign', ['tenant' => $tenant->id, 'domains' => $tenant->label(), ...$ctx]);
                }
            }
            $busy = count(array_filter($tenants, static fn (Tenant $tenant): bool => $tenant->busy()));
            if ($busy === 0) {
                return $tenants;
            }
            if ($this->sleeper->now() >= $deadline) {
                throw DeployException::make('remove.tenant_busy', ['count' => $busy, ...$ctx]);
            }
            if (! $announced) {
                $step('remove.step_tenant_settle', ['count' => $busy]);
                $announced = true;
            }
            $this->sleeper->sleep(self::SETTLE_POLL);
        } while (true);
    }

    /**
     * Disables the tenant, then deletes it as soon as CloudFront accepts it. Each attempt reads the tenant again
     * (fresh ETag) and checks it is still the environment's: nothing else is ever changed or deleted.
     * @param array{function: string, environment: string} $ctx the mediator and environment the stop messages name
     */
    private function delete(TenantInventory $inventory, string $id, array $ctx): void
    {
        $cloudFront = $this->aws->cloudFront();
        $deadline = $this->sleeper->now() + self::DELETE_TIMEOUT;
        do {
            $tenant = $inventory->read($id);
            if ($tenant === null) {
                return;
            }
            if (! $tenant->owned) {
                throw DeployException::make('remove.tenant_foreign', ['tenant' => $id, 'domains' => $tenant->label(), ...$ctx]);
            }
            if ($tenant->etag === null || $tenant->enabled === null) {
                throw DeployException::make('remove.tenant_unverified', ['tenant' => $id, 'error' => 'CloudFront did not return its ETag or state', ...$ctx]);
            }
            if ($tenant->enabled) {
                $this->disable($cloudFront, $inventory, $tenant);
            } else {
                try {
                    Aws::call($cloudFront, 'DeleteDistributionTenant', ['Id' => $id, 'IfMatch' => $tenant->etag]);
                    return;
                } catch (AwsException $e) {
                    $code = $e->getAwsErrorCode();
                    if ($code === 'EntityNotFound' || $code === 'NoSuchDistributionTenant') {
                        return;
                    }
                    if (! in_array($code, ['ResourceNotDisabled', 'PreconditionFailed', 'InvalidIfMatchVersion'], true)) {
                        throw $e;
                    }
                }
            }
            if ($this->sleeper->now() >= $deadline) {
                throw DeployException::make('remove.tenant_delete_timeout', ['tenant' => $id, ...$ctx]);
            }
            $this->sleeper->sleep(self::DELETE_POLL);
        } while (true);
    }

    private function disable(CloudFrontClient $cloudFront, TenantInventory $inventory, Tenant $tenant): void
    {
        try {
            Aws::call($cloudFront, 'UpdateDistributionTenant', [
                'Id' => $tenant->id,
                'IfMatch' => $tenant->etag,
                'Enabled' => false,
                'DistributionId' => $inventory->distributionId,
                'ConnectionGroupId' => $inventory->connectionGroupId,
                'Domains' => array_map(static fn (string $domain): array => ['Domain' => $domain], $tenant->domains),
            ]);
        } catch (AwsException $e) {
            // The ETag moved under us: the next attempt reads it again.
            if (! in_array($e->getAwsErrorCode(), ['PreconditionFailed', 'InvalidIfMatchVersion'], true)) {
                throw $e;
            }
        }
    }

    /**
     * @param array{distribution: string, connectionGroup: string, target: string, endpoint: string} $infra
     * @param callable(string, array<string, string|int>): void $step
     */
    private function releaseDns(AppConfig $config, array $infra, callable $step): void
    {
        $step('remove.step_tenant_dns', ['target' => $infra['target'], 'endpoint' => $infra['endpoint']]);
        $outcome = ($this->releaseDns)($infra['target'], $infra['endpoint'], $config);
        if ($outcome === TenantTarget::KEPT) {
            $step('remove.tenant_dns_kept', ['target' => $infra['target'], 'endpoint' => $infra['endpoint']]);
        } elseif ($outcome === TenantTarget::MANUAL) {
            $step('remove.tenant_dns_manual', ['target' => $infra['target'], 'endpoint' => $infra['endpoint']]);
        }
    }

    /**
     * The stack's outputs; null when the stack does not exist or holds nothing (a first creation that rolled back).
     *
     * @param array{status: string, outputs: array<string, string>, tags: array<string, string>}|null $stack
     * @return array{distribution: string, connectionGroup: string, target: string, endpoint: string}|null
     * @throws DeployException domains.tenant_stack_unusable
     */
    private function infra(?array $stack, string $stackName): ?array
    {
        if ($stack === null || in_array($stack['status'], Stacks::EMPTY, true)) {
            return null;
        }
        $values = [];
        foreach (['distribution' => 'DistributionId', 'connectionGroup' => 'ConnectionGroupId', 'target' => 'Target', 'endpoint' => 'RoutingEndpoint'] as $key => $output) {
            $value = $stack['outputs'][$output] ?? '';
            if ($value === '') {
                throw DeployException::make('domains.tenant_stack_unusable', ['stack' => $stackName, 'status' => $stack['status']]);
            }
            $values[$key] = $value;
        }
        return $values;
    }

    /** Strict, like the deploy's: the resource carries this app's and environment's tags; nothing else counts. */
    private static function assertOwned(AppConfig $config, NamedResource $resource): void
    {
        if ($resource->unreadable !== null) {
            throw DeployException::make('remove.tags_unreadable', ['resource' => $resource->name, ...$resource->unreadable]);
        }
        $app = $resource->tags[Ownership::APP] ?? null;
        $environment = $resource->tags[Ownership::ENVIRONMENT] ?? null;
        if ($app === $config->app && $environment === $config->environment) {
            return;
        }
        if ($app === null && $environment === null) {
            throw DeployException::make('remove.tenant_untagged', ['resource' => $resource->name]);
        }
        throw DeployException::make('remove.not_owned', ['resource' => $resource->name, 'app' => $app ?? '-', 'environment' => $environment ?? '-']);
    }
}
