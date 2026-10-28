<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Aws\LambdaCore\Exception\LambdaCoreException;
use Aws\LambdaCore\LambdaCoreClient;
use Multiapi\Arco\Config\AppConfig;

/**
 * The Lambda network connector of an environment with vpc() and its operator role: the role the service
 * assumes to manage the network interfaces. One connector per environment, named like the web function.
 * The lambda-core API cannot read a connector's tags, so ownership is the operator role: a connector with
 * this name whose OperatorRole is Arco's role for the environment (IAM can read that role's tags).
 */
final class NetworkConnectors
{
    private const SERVICE = 'network-connectors.lambda.amazonaws.com';
    private const TIMEOUT = 600;
    private const POLL = 10;
    private const SUFFIX = '-network';
    /** What lambda-core may answer while a role created seconds ago is not assumable yet (LambdaHelpers::retryRolePropagation). */
    private const ROLE_ERRORS = ['ValidationException', 'InvalidParameterValueException', 'AccessDeniedException'];

    public function __construct(
        private readonly LambdaCoreClient $core,
        private readonly ExecutionRole $roles,
        private readonly string $account,
        private readonly Sleeper $sleeper,
    ) {
    }

    /**
     * `arco-<app>-<env>-network`, at most 64 characters; the suffix is kept so it never equals the execution role.
     * A name that does not fit is shortened and keeps a hash of the function name, so two environments with a
     * long common prefix never share an operator role.
     */
    public static function operatorRoleName(AppConfig $config): string
    {
        $name = 'arco-' . $config->functionName();
        if (strlen($name . self::SUFFIX) <= 64) {
            return $name . self::SUFFIX;
        }
        $hash = '-' . substr(hash('sha256', $config->functionName()), 0, 8);
        return substr($name, 0, 64 - strlen($hash) - strlen(self::SUFFIX)) . $hash . self::SUFFIX;
    }

    /** @return array<string, mixed> */
    public static function operatorPolicy(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                // As AWS documents it: a ManagedResourceOperator condition on CreateNetworkInterface denies it.
                [
                    'Effect' => 'Allow',
                    'Action' => 'ec2:CreateNetworkInterface',
                    'Resource' => ['arn:aws:ec2:*:*:network-interface/*', 'arn:aws:ec2:*:*:subnet/*', 'arn:aws:ec2:*:*:security-group/*'],
                ],
                [
                    'Effect' => 'Allow',
                    'Action' => 'ec2:CreateTags',
                    'Resource' => 'arn:aws:ec2:*:*:network-interface/*',
                    'Condition' => ['StringEquals' => ['ec2:ManagedResourceOperator' => self::SERVICE]],
                ],
                [
                    'Effect' => 'Allow',
                    'Action' => ['ec2:DeleteNetworkInterface', 'ec2:DescribeNetworkInterfaces', 'ec2:DescribeSubnets', 'ec2:DescribeSecurityGroups'],
                    'Resource' => '*',
                ],
            ],
        ];
    }

    private function roleArn(AppConfig $config): string
    {
        return "arn:aws:iam::{$this->account}:role/" . self::operatorRoleName($config);
    }

    /**
     * Creates the operator role and the connector, or updates the connector to the configured subnets and
     * groups, and waits until it is usable. Returns the version ARN a revision pins. A FAILED connector that
     * the live revision does not pin is deleted and created again (a fixed vpc() would otherwise never apply:
     * an update needs ACTIVE); one the live revision pins stops the deploy.
     *
     * @param array<string, string> $tags
     * @param callable(string, array<string, string|int>): void $step
     * @param string|null $liveVersionArn the connector version the live revision pins, if any
     * @throws DeployException deploy.network_foreign, deploy.network_failed, deploy.network_timeout
     */
    public function ensure(AppConfig $config, array $tags, callable $step, ?string $liveVersionArn = null): string
    {
        $vpc = $config->vpc ?? throw new \LogicException('NetworkConnectors::ensure needs vpc().');
        $name = $config->functionName();
        $step('deploy.step_network', ['connector' => $name, 'subnets' => implode(', ', $vpc->subnets), 'groups' => implode(', ', $vpc->securityGroups)]);

        // Before any write: a foreign connector must not leave a created or rewritten role behind.
        $current = $this->find($config);

        ['arn' => $roleArn] = $this->roles->ensure(self::operatorRoleName($config), self::operatorPolicy(), $tags, null, $step, $config->permissionsBoundary);
        $desired = [
            'SubnetIds' => $vpc->subnets,
            'SecurityGroupIds' => $vpc->securityGroups,
            'NetworkProtocol' => 'IPv4',
            'AssociatedComputeResourceTypes' => ['WebFunction'],
        ];

        if ($current !== null && $current['State'] === 'FAILED' && ($liveVersionArn === null || ! self::pins($current, $liveVersionArn))) {
            // Nothing serves through it: replaced, so a corrected vpc() applies.
            $failure = $this->failed($name, $current, 'State');
            $step('deploy.network_replacing', ['connector' => $name, 'reason' => $failure->params['reason'] ?? '', 'code' => $failure->params['code'] ?? '']);
            try {
                $this->core->deleteNetworkConnector(['Identifier' => $name]);
            } catch (LambdaCoreException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                    throw $e;
                }
            }
            $this->waitGone($name);
            $current = null;
        }

        if ($current === null) {
            // Retried even for an existing role: ensure() has just rewritten its inline policy, and lambda-core
            // checks the role's EC2 permissions when the connector is created.
            $created = LambdaHelpers::retryRolePropagation($this->sleeper, true, self::ROLE_ERRORS, fn () => $this->core->createNetworkConnector([
                'Name' => $name,
                'Configuration' => ['VpcEgressConfiguration' => $desired],
                'OperatorRole' => $roleArn,
                ...($tags === [] ? [] : ['Tags' => $tags]),
            ]));
            $active = $this->wait($name, $step, fn (array $c) => $c['State'] === 'ACTIVE');
            return self::version($created['VersionArn'] ?? null, $active['LatestVersionArn'] ?? null);
        }

        if ($current['State'] === 'PENDING') {
            $current = $this->wait($name, $step, fn (array $c) => $c['State'] === 'ACTIVE');
        }
        if ($current['State'] !== 'ACTIVE') {
            throw $this->failed($name, $current, 'State');
        }
        if (($current['LastUpdateStatus'] ?? null) === 'InProgress') {
            $current = $this->wait($name, $step, fn (array $c) => ($c['LastUpdateStatus'] ?? null) !== 'InProgress');
        }
        $latest = self::text($current['LatestVersionArn'] ?? null);
        if (self::sameConfiguration($current, $desired)) {
            return self::version($latest);
        }

        $update = $this->core->updateNetworkConnector(['Identifier' => $name, 'Configuration' => ['VpcEgressConfiguration' => $desired]]);
        // The new version is the target. A read can still show the previous update's status (a stale Failed
        // included), so a status counts only once the read reflects this update.
        $target = self::text($update['VersionArn'] ?? null);
        $modified = self::stamp($current['LastModified'] ?? null);
        $reflects = static fn (array $c): bool => $target !== ''
            ? self::text($c['LatestVersionArn'] ?? null) === $target || ($modified !== '' && self::stamp($c['LastModified'] ?? null) !== $modified)
            : self::text($c['LatestVersionArn'] ?? null) !== $latest;
        $updated = $this->wait(
            $name,
            $step,
            fn (array $c) => ($c['LastUpdateStatus'] ?? null) === 'Successful' && ($target !== '' ? self::text($c['LatestVersionArn'] ?? null) === $target : $reflects($c)),
            fn (array $c) => ($c['LastUpdateStatus'] ?? null) === 'Failed' && $reflects($c),
        );
        $step('deploy.network_updated', ['connector' => $name, 'version' => self::int($updated['LatestVersion'] ?? null)]);
        return self::version($updated['LatestVersionArn'] ?? null, $target);
    }

    /**
     * The first non-empty version ARN: a revision without one would silently run outside the VPC.
     *
     * @throws \UnexpectedValueException
     */
    private static function version(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (self::text($candidate) !== '') {
                return self::text($candidate);
            }
        }
        throw new \UnexpectedValueException('Lambda returned no network connector version ARN.');
    }

    /**
     * The GetNetworkConnector result when Arco owns the connector of this environment, null when there is none.
     *
     * @return array<string, mixed>|null
     * @throws DeployException deploy.network_foreign
     */
    public function find(AppConfig $config): ?array
    {
        $connector = $this->get($config->functionName());
        if ($connector === null) {
            return null;
        }
        if (($connector['OperatorRole'] ?? null) !== $this->roleArn($config)) {
            throw DeployException::make('deploy.network_foreign', ['connector' => $config->functionName(), 'role' => self::operatorRoleName($config)]);
        }
        return $connector;
    }

    /**
     * Deletes the versions that are neither the latest nor in $keepVersionArns. A failure is a warning:
     * the deploy already succeeded.
     *
     * @param list<string> $keepVersionArns
     * @param callable(string, array<string, string|int>): void $step
     */
    public function pruneVersions(AppConfig $config, array $keepVersionArns, callable $step): void
    {
        $name = $config->functionName();
        try {
            /** @var array<string, int> $versions version ARN => number */
            $versions = [];
            /** @var array{Id?: string, Arn?: string} $identity the connector's id and ARN, as the list shows them */
            $identity = [];
            $marker = null;
            do {
                $page = $this->core->listNetworkConnectorVersions(['Identifier' => $name, ...($marker === null ? [] : ['Marker' => $marker])]);
                foreach (is_array($page['NetworkConnectorVersions'] ?? null) ? $page['NetworkConnectorVersions'] : [] as $version) {
                    $arn = is_array($version) ? $version['VersionArn'] ?? null : null;
                    $number = is_array($version) ? $version['Version'] ?? null : null;
                    // Only a version-qualified ARN ending in its own number is ever deleted: an unqualified one
                    // would delete the whole connector. Anything unexpected in the list, and nothing is pruned.
                    if (! is_string($arn) || ! is_numeric($number) || ! str_ends_with($arn, ':' . (int) $number)) {
                        return;
                    }
                    $versions[$arn] = (int) $number;
                    foreach (['Id', 'Arn'] as $key) {
                        if (is_string($version[$key] ?? null) && $version[$key] !== '') {
                            $identity[$key] = $version[$key];
                        }
                    }
                }
                $marker = is_string($page['NextMarker'] ?? null) && $page['NextMarker'] !== '' ? $page['NextMarker'] : null;
            } while ($marker !== null);
        } catch (AwsException $e) {
            $step('deploy.network_prune_failed', ['connector' => $name, 'region' => (string) $config->region, 'error' => self::error($e)]);
            return;
        }
        if ($versions === []) {
            return;
        }
        // A revision may name the connector by its id or by its name: kept versions are matched by number,
        // among the ARNs that are this connector's (pins()). One that cannot be read, and nothing is pruned.
        $connector = ['Name' => $name, 'Id' => $identity['Id'] ?? '', 'Arn' => $identity['Arn'] ?? ''];
        $keep = [max($versions)];
        foreach ($keepVersionArns as $kept) {
            $number = self::versionNumber($kept);
            if ($number === null) {
                return;
            }
            if (self::pins($connector, $kept)) {
                $keep[] = $number;
            }
        }
        foreach ($versions as $arn => $number) {
            if (in_array($number, $keep, true)) {
                continue;
            }
            try {
                $this->core->deleteNetworkConnector(['Identifier' => $arn]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                    $step('deploy.network_prune_failed', ['connector' => $name, 'region' => (string) $config->region, 'error' => self::error($e)]);
                }
            }
        }
    }

    /**
     * Deletes the connector (every version), waits until it is gone, then the operator role. The caller checks
     * ownership first (find()).
     *
     * @param callable(string, array<string, string|int>): void $step
     * @throws DeployException deploy.network_failed, deploy.network_timeout
     */
    public function delete(AppConfig $config, callable $step): void
    {
        $name = $config->functionName();
        try {
            $this->core->deleteNetworkConnector(['Identifier' => $name]);
            $this->waitGone($name);
        } catch (LambdaCoreException $e) {
            // Gone already (a second delete fails once the connector has disappeared).
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
        $this->roles->delete(self::operatorRoleName($config));
        $step('deploy.network_removed', ['connector' => $name]);
    }

    /**
     * Whether $versionArn (a revision's egressNetworkConnectorArn) is a version of $connector (a GetNetworkConnector
     * result). AWS names the connector in the ARN by its id (nc-...); its name is accepted too.
     *
     * @param array<string, mixed> $connector
     */
    public static function pins(array $connector, string $versionArn): bool
    {
        $parts = explode(':', $versionArn);
        if (count($parts) < 7 || $parts[5] !== 'network-connector') {
            return false;
        }
        $arn = self::text($connector['Arn'] ?? null);
        return in_array($parts[6], array_filter([self::text($connector['Id'] ?? null), self::text($connector['Name'] ?? null)]), true)
            || ($arn !== '' && ($versionArn === $arn || str_starts_with($versionArn, $arn . ':')));
    }

    /** The version number at the end of a connector version ARN; null when it is not one. */
    private static function versionNumber(string $versionArn): ?int
    {
        $parts = explode(':', $versionArn);
        return count($parts) === 8 && $parts[5] === 'network-connector' && ctype_digit($parts[7]) ? (int) $parts[7] : null;
    }

    /**
     * What arco info shows of the connector: its id, latest version and state.
     *
     * @param array<string, mixed> $connector
     * @return array{connector: string, version: int, state: string}
     */
    public static function summary(array $connector): array
    {
        $id = self::text($connector['Id'] ?? null);
        return ['connector' => $id !== '' ? $id : self::text($connector['Name'] ?? null), 'version' => self::int($connector['LatestVersion'] ?? null), 'state' => self::text($connector['State'] ?? null)];
    }

    /** @return array<string, mixed>|null */
    private function get(string $name): ?array
    {
        try {
            $result = $this->core->getNetworkConnector(['Identifier' => $name])->toArray();
        } catch (LambdaCoreException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
        /** @var array<string, mixed> $result */
        $result['State'] = self::text($result['State'] ?? null);
        return $result;
    }

    /**
     * Polls until $done; a FAILED connector, or an update that $updateFailed says failed, stops with its reason.
     *
     * @param callable(string, array<string, string|int>): void $step
     * @param callable(array<string, mixed>): bool $done
     * @param (callable(array<string, mixed>): bool)|null $updateFailed
     * @return array<string, mixed>
     */
    private function wait(string $name, callable $step, callable $done, ?callable $updateFailed = null): array
    {
        $deadline = $this->sleeper->now() + self::TIMEOUT;
        $announced = false;
        while (true) {
            $connector = $this->get($name) ?? throw DeployException::make('deploy.network_failed', ['connector' => $name, 'reason' => 'gone', 'code' => 'ResourceNotFoundException', 'region' => $this->core->getRegion()]);
            if ($connector['State'] === 'FAILED') {
                throw $this->failed($name, $connector, 'State');
            }
            if ($updateFailed !== null && $updateFailed($connector)) {
                throw $this->failed($name, $connector, 'LastUpdateStatus');
            }
            if ($done($connector)) {
                return $connector;
            }
            if ($this->sleeper->now() >= $deadline) {
                throw DeployException::make('deploy.network_timeout', ['connector' => $name, 'seconds' => self::TIMEOUT]);
            }
            if (! $announced) {
                $step('deploy.network_waiting', ['connector' => $name]);
                $announced = true;
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    private function waitGone(string $name): void
    {
        $deadline = $this->sleeper->now() + self::TIMEOUT;
        while (($connector = $this->get($name)) !== null) {
            if ($connector['State'] === 'DELETE_FAILED') {
                throw $this->failed($name, $connector, 'State');
            }
            if ($this->sleeper->now() >= $deadline) {
                throw DeployException::make('deploy.network_timeout', ['connector' => $name, 'seconds' => self::TIMEOUT]);
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    /**
     * @param array<string, mixed> $connector
     * @param 'State'|'LastUpdateStatus' $field which status failed: its reason code and text are read from the matching keys
     */
    private function failed(string $name, array $connector, string $field): DeployException
    {
        $prefix = $field === 'State' ? 'State' : 'LastUpdateStatus';
        $code = self::text($connector[$prefix . 'ReasonCode'] ?? null);
        $reason = self::text($connector[$prefix . 'Reason'] ?? null);
        return DeployException::make('deploy.network_failed', [
            'connector' => $name,
            'reason' => $reason !== '' ? $reason : ($code !== '' ? $code : self::text($connector[$field] ?? null)),
            'code' => $code,
            'region' => $this->core->getRegion(),
        ]);
    }

    /**
     * Same subnets and groups (as sets), protocol and compute types: what Arco would send again.
     *
     * @param array<string, mixed> $connector
     * @param array<string, mixed> $desired
     */
    private static function sameConfiguration(array $connector, array $desired): bool
    {
        $configuration = $connector['Configuration'] ?? null;
        $vpc = is_array($configuration) && is_array($configuration['VpcEgressConfiguration'] ?? null) ? $configuration['VpcEgressConfiguration'] : [];
        $set = static function (mixed $values): array {
            $list = is_array($values) ? array_values(array_map(static fn (mixed $v): string => self::text($v), $values)) : [];
            sort($list);
            return $list;
        };
        return $set($vpc['SubnetIds'] ?? null) === $set($desired['SubnetIds'])
            && $set($vpc['SecurityGroupIds'] ?? null) === $set($desired['SecurityGroupIds'])
            && ($vpc['NetworkProtocol'] ?? null) === $desired['NetworkProtocol']
            && $set($vpc['AssociatedComputeResourceTypes'] ?? null) === $set($desired['AssociatedComputeResourceTypes']);
    }

    private static function error(AwsException $e): string
    {
        return trim((string) $e->getAwsErrorCode() . ' ' . (string) $e->getAwsErrorMessage());
    }

    /** A LastModified value as comparable text (the SDK parses timestamps into DateTime objects). */
    private static function stamp(mixed $value): string
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('U.u'),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
