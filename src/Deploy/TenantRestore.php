<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Config\AppConfig;

/**
 * `arco tenants:restore`: turns the tenant domains mediator back on after an `arco remove` that stopped and left its
 * reserved concurrency at 0 (TenantRemoval never restores it, and a deploy refuses while it is 0).
 *
 * Read-only first: the function must exist, carry this app's and environment's tags (the same strict check as the
 * deploy: TenantMediator::assertOwned()) and be Arco's mediator by its description. No `--force` overrides any of it.
 * Then the one change, and only for the state `arco remove` leaves: reserved concurrency 0 is deleted and read back.
 * No reserved concurrency is already enabled; any other value is a limit someone set on purpose and is left alone.
 */
final class TenantRestore
{
    public const RESTORED = 'restored';
    public const ALREADY_ENABLED = 'already_enabled';
    public const LIMIT_KEPT = 'limit_kept';
    /** The environment has no mediator function: nothing was ever deployed, or a remove finished. Not an error. */
    public const NOTHING_TO_RESTORE = 'nothing_to_restore';

    public function __construct(private readonly AwsClients $aws)
    {
    }

    /**
     * @return array{outcome: 'restored'|'already_enabled'|'limit_kept'|'nothing_to_restore', reserved: int|null} reserved: the limit that was kept (limit_kept), else null
     * @throws DeployException tenants.restore_not_mediator, tenants.restore_unconfirmed,
     *         deploy.tenant_mediator_not_owned, deploy.tenant_mediator_untagged, deploy.tenant_mediator_tags_unreadable
     * @throws AwsException a refused or failed call; before the delete nothing has changed
     */
    public function restore(AppConfig $config): array
    {
        $name = $config->tenantMediatorFunctionName();
        $params = ['function' => $name, 'environment' => $config->environment];
        $function = (new OwnershipGuard($this->aws))->lambdaFunction($name, TenantMediator::MARKER);
        if ($function === null) {
            return ['outcome' => self::NOTHING_TO_RESTORE, 'reserved' => null];
        }
        TenantMediator::assertOwned($config, $function);
        if (! $function->arcoMade) {
            throw DeployException::make('tenants.restore_not_mediator', $params);
        }

        $lambda = $this->aws->lambda();
        $reserved = $this->reserved($name);
        if ($reserved === null) {
            return ['outcome' => self::ALREADY_ENABLED, 'reserved' => null];
        }
        if ($reserved !== 0) {
            return ['outcome' => self::LIMIT_KEPT, 'reserved' => is_int($reserved) ? $reserved : null];
        }

        $lambda->deleteFunctionConcurrency(['FunctionName' => $name]);
        $left = $this->reserved($name);
        if ($left !== null) {
            throw DeployException::make('tenants.restore_unconfirmed', [...$params, 'value' => is_scalar($left) ? (string) $left : 'unknown']);
        }
        return ['outcome' => self::RESTORED, 'reserved' => null];
    }

    /** The function's reserved concurrency; null when it has none. */
    private function reserved(string $name): mixed
    {
        return $this->aws->lambda()->getFunctionConcurrency(['FunctionName' => $name])['ReservedConcurrentExecutions'] ?? null;
    }
}
