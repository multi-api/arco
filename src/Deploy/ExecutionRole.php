<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Iam\Exception\IamException;
use Aws\Iam\IamClient;
use Aws\ResultInterface;
use Multiapi\Arco\Config\AppConfig;

final class ExecutionRole
{
    private const TRUST = [
        'Version' => '2012-10-17',
        'Statement' => [['Effect' => 'Allow', 'Principal' => ['Service' => 'lambda.amazonaws.com'], 'Action' => 'sts:AssumeRole']],
    ];

    public const REGION_TAG = 'arco:region';
    private const DENIED = ['AccessDenied', 'AccessDeniedException'];

    public function __construct(private readonly IamClient $iam)
    {
    }

    public static function roleName(AppConfig $config): string
    {
        return substr('arco-' . $config->functionName(), 0, 64);
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, string> $tags
     * @param string|null $region the app's region: a new role is tagged `arco:region`, an existing one
     *                            tagged with another region is refused (an app lives in one region), an
     *                            untagged one gets the tag
     * @param (callable(string, array<string, string|int>): void)|null $onStep told when the region tag could not be added,
     *                            when the boundary is set on an existing role (deploy.boundary_set) and when
     *                            a boundary no longer configured is left on it (deploy.boundary_kept)
     * @param string|null $boundary the permissions boundary (a policy ARN): a new role is created with it, an
     *                              existing one without it or with another gets it before its policy is
     *                              written (an account guard conditioned on iam:PermissionsBoundary then
     *                              allows the PutRolePolicy). Null never removes the role's boundary.
     * @return array{arn: string, created: bool}
     * @throws DeployException deploy.region_changed, deploy.boundary_denied
     */
    public function ensure(string $roleName, array $policy, array $tags, ?string $region = null, ?callable $onStep = null, ?string $boundary = null): array
    {
        $created = false;
        $current = null;
        if ($region !== null) {
            $tags[self::REGION_TAG] = $region;
        }
        try {
            $existing = $this->iam->getRole(['RoleName' => $roleName]);
            $arn = self::arn($existing);
            if ($region !== null) {
                $this->checkRegion($roleName, $existing, $region, $onStep);
            }
            // From the GetRole result already in hand: no extra call to compare the boundary.
            $current = self::boundary($existing);
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                throw $e;
            }
            $arn = self::arn($this->bounded($roleName, $boundary, 'iam:CreateRole', fn () => $this->iam->createRole([
                'RoleName' => $roleName,
                'AssumeRolePolicyDocument' => json_encode(self::TRUST, JSON_THROW_ON_ERROR),
                'Description' => 'Execution role managed by Arco',
                'Tags' => array_map(fn ($k, $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), $tags),
                ...($boundary === null ? [] : ['PermissionsBoundary' => $boundary]),
            ])));
            $created = true;
        }

        if (! $created && $boundary !== null && $current !== $boundary) {
            $this->bounded($roleName, $boundary, 'iam:PutRolePermissionsBoundary', fn () => $this->iam->putRolePermissionsBoundary(['RoleName' => $roleName, 'PermissionsBoundary' => $boundary]));
            if ($onStep !== null) {
                $onStep('deploy.boundary_set', ['role' => $roleName, 'boundary' => $boundary]);
            }
        } elseif ($boundary === null && $current !== null && $onStep !== null) {
            // Never DeleteRolePermissionsBoundary: removing a boundary is the account owner's call.
            $onStep('deploy.boundary_kept', ['role' => $roleName, 'boundary' => $current]);
        }

        $this->bounded($roleName, $boundary, 'iam:PutRolePolicy', fn () => $this->iam->putRolePolicy([
            'RoleName' => $roleName,
            'PolicyName' => 'arco',
            'PolicyDocument' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]));

        return ['arn' => $arn, 'created' => $created];
    }

    /**
     * Runs an IAM write; with a boundary configured, a denial is either the account guard that allows
     * the write only with exactly its boundary (condition key iam:PermissionsBoundary) or a deploy
     * profile without $action. The error names both, with the AWS error code (never its message).
     *
     * @param callable(): ResultInterface $call
     * @throws DeployException deploy.boundary_denied
     */
    private function bounded(string $roleName, ?string $boundary, string $action, callable $call): ResultInterface
    {
        try {
            return $call();
        } catch (IamException $e) {
            if ($boundary === null || ! in_array($e->getAwsErrorCode(), self::DENIED, true)) {
                throw $e;
            }
            throw DeployException::make('deploy.boundary_denied', ['role' => $roleName, 'boundary' => $boundary, 'action' => $action, 'error' => (string) $e->getAwsErrorCode()], $e);
        }
    }

    /** The role's current permissions boundary, from a GetRole result. */
    private static function boundary(ResultInterface $result): ?string
    {
        $role = $result->get('Role');
        $boundary = is_array($role) && is_array($role['PermissionsBoundary'] ?? null) ? ($role['PermissionsBoundary']['PermissionsBoundaryArn'] ?? null) : null;
        return is_string($boundary) && $boundary !== '' ? $boundary : null;
    }

    /**
     * The refusal, before any change: the role (account-global) was created for another region. Also
     * used by OwnershipGuard's pre-flight, where `--force` does not apply (it overrides ownership only).
     *
     * @throws DeployException deploy.region_changed
     */
    public static function refuseOtherRegion(string $roleName, ?string $current, string $region): void
    {
        if ($current !== null && $current !== $region) {
            throw DeployException::make('deploy.region_changed', ['resource' => $roleName, 'current' => $current, 'region' => $region]);
        }
    }

    /** Reads the tag from the GetRole result already in hand: no extra call to refuse; one TagRole to adopt. */
    private function checkRegion(string $roleName, ResultInterface $result, string $region, ?callable $onStep): void
    {
        $role = $result->get('Role');
        $current = null;
        foreach (is_array($role) && is_array($role['Tags'] ?? null) ? $role['Tags'] : [] as $tag) {
            if (is_array($tag) && ($tag['Key'] ?? null) === self::REGION_TAG && is_string($tag['Value'] ?? null)) {
                $current = $tag['Value'];
            }
        }
        if ($current === $region) {
            return;
        }
        self::refuseOtherRegion($roleName, $current, $region);
        try {
            $this->iam->tagRole(['RoleName' => $roleName, 'Tags' => [['Key' => self::REGION_TAG, 'Value' => $region]]]);
        } catch (IamException $e) {
            // Best effort: a deploy profile without iam:TagRole still deploys; the next one with it tags the role.
            if (! in_array($e->getAwsErrorCode(), self::DENIED, true)) {
                throw $e;
            }
            if ($onStep !== null) {
                $onStep('deploy.tags_not_added', ['resource' => $roleName, 'error' => (string) $e->getAwsErrorCode()]);
            }
        }
    }

    private static function arn(ResultInterface $result): string
    {
        $role = $result->get('Role');
        $arn = is_array($role) ? ($role['Arn'] ?? null) : null;
        if (! is_string($arn)) {
            throw new \UnexpectedValueException('IAM returned no role ARN.');
        }
        return $arn;
    }

    public function delete(string $roleName): void
    {
        foreach ([
            fn () => $this->iam->deleteRolePolicy(['RoleName' => $roleName, 'PolicyName' => 'arco']),
            fn () => $this->iam->deleteRole(['RoleName' => $roleName]),
        ] as $call) {
            try {
                $call();
            } catch (IamException $e) {
                if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                    throw $e;
                }
            }
        }
    }
}
