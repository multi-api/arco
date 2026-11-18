<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * The Resource Groups Tagging API is eventually consistent: it can keep listing a resource for many minutes after
 * it was deleted (a network connector stayed listed for more than ten). Before `arco remove` counts a listed
 * resource it deletes as still there, it confirms the resource exists with one direct, read-only call for its type.
 *
 * Only a definite answer drops a resource: not found (or a terminal deleted state). A type with no check here, a
 * refused check or any other error keeps it listed, so remove never reports `removed` on a guess. Retained resources
 * (the data remove keeps) are never checked, and IAM roles need no check: TaggedResources reads them with
 * iam:GetRole, never from the Tagging API.
 */
final class ExistingResources
{
    public function __construct(private readonly AwsClients $aws)
    {
    }

    /**
     * $resources without the ones remove deletes that are confirmed gone.
     *
     * @template T of array{type: string, name: string, arn: string, retained: bool}
     * @param list<T> $resources
     * @return list<T>
     */
    public function filter(AppConfig $config, array $resources): array
    {
        return array_values(array_filter($resources, fn (array $resource): bool => $resource['retained'] || ! $this->gone($config, $resource)));
    }

    /**
     * Whether a direct read says the resource no longer exists. False when it exists, when Arco has no check for
     * its type, or when the check fails (denied, throttled, unexpected answer).
     *
     * @param array{type: string, name: string, arn: string, retained: bool} $resource
     */
    public function gone(AppConfig $config, array $resource): bool
    {
        try {
            return match ($resource['type']) {
                // The Web Function, however the Tagging API spells its ARN: gone only when no function by its name is left.
                'lambda:web-function' => $this->webFunctionGone($resource['name']),
                'lambda:function' => $resource['name'] === $config->functionName()
                    ? $this->webFunctionGone($resource['name']) && $this->functionGone($resource['arn'])
                    : $this->functionGone($resource['arn']),
                'lambda:network-connector' => $this->connectorGone($resource['name']),
                'events:rule' => $this->ruleGone($resource['name']),
                'resource-groups:group' => self::missing(fn () => Aws::call($this->aws->resourceGroups(), 'GetGroup', ['Group' => $resource['name']]), ['NotFoundException']),
                'cloudformation:stack' => $this->stackGone($resource['arn']),
                'ec2:vpc-endpoint' => $this->endpointGone($resource['name']),
                'cloudfront:distribution-tenant' => self::missing(fn () => Aws::call($this->aws->cloudFront(), 'GetDistributionTenant', ['Identifier' => $resource['name']]), ['EntityNotFound']),
                'cloudfront:connection-group' => self::missing(fn () => Aws::call($this->aws->cloudFront(), 'GetConnectionGroup', ['Identifier' => $resource['name']]), ['EntityNotFound']),
                'cloudfront:function' => self::missing(fn () => Aws::call($this->aws->cloudFront(), 'DescribeFunction', ['Name' => $resource['name']]), ['NoSuchFunctionExists']),
                default => false,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    private function webFunctionGone(string $name): bool
    {
        return self::missing(fn () => Aws::call($this->aws->lambdaWeb(), 'GetWebFunction', ['functionName' => self::unqualified($name)]), ['ResourceNotFoundException']);
    }

    /** A console or worker function (lambda:GetFunctionConfiguration, which remove already reads). */
    private function functionGone(string $arn): bool
    {
        return self::missing(fn () => Aws::call($this->aws->lambda(), 'GetFunctionConfiguration', ['FunctionName' => $arn]), ['ResourceNotFoundException']);
    }

    /** The ARN names the connector by its id (nc-...), which GetNetworkConnector accepts as the identifier. */
    private function connectorGone(string $id): bool
    {
        return self::missing(fn () => $this->aws->lambdaCore()->getNetworkConnector(['Identifier' => $id]), ['ResourceNotFoundException']);
    }

    /** A rule of the default bus is `<name>`, one of another bus `<bus>/<name>`. */
    private function ruleGone(string $name): bool
    {
        $at = strrpos($name, '/');
        $args = $at === false ? ['Name' => $name] : ['EventBusName' => substr($name, 0, $at), 'Name' => substr($name, $at + 1)];
        return self::missing(fn () => Aws::call($this->aws->eventBridge(), 'DescribeRule', $args), ['ResourceNotFoundException']);
    }

    /** By its ARN (the stack id): a deleted stack still answers, with DELETE_COMPLETE. */
    private function stackGone(string $arn): bool
    {
        $stacks = null;
        $missing = self::missing(function () use ($arn, &$stacks): void {
            $stacks = Aws::maps(Aws::call($this->aws->cloudFormation(), 'DescribeStacks', ['StackName' => $arn])['Stacks'] ?? []);
        }, [], 'does not exist');
        if ($missing) {
            return true;
        }
        return $stacks === [] || (Aws::string(($stacks[0] ?? [])['StackStatus'] ?? null) === 'DELETE_COMPLETE');
    }

    private function endpointGone(string $id): bool
    {
        $endpoints = null;
        $missing = self::missing(function () use ($id, &$endpoints): void {
            $endpoints = Aws::maps(Aws::call($this->aws->ec2(), 'DescribeVpcEndpoints', ['VpcEndpointIds' => [$id]])['VpcEndpoints'] ?? []);
        }, ['InvalidVpcEndpointId.NotFound']);
        if ($missing || $endpoints === []) {
            return true;
        }
        return in_array(strtolower((string) Aws::string(($endpoints[0] ?? [])['State'] ?? null)), ['deleted'], true);
    }

    /**
     * Whether $read failed with one of $codes (or, for a ValidationError, with a message containing $message).
     * Any other error is rethrown.
     *
     * @param list<string> $codes
     */
    private static function missing(callable $read, array $codes, ?string $message = null): bool
    {
        try {
            $read();
            return false;
        } catch (AwsException $e) {
            $code = (string) $e->getAwsErrorCode();
            if (in_array($code, $codes, true)) {
                return true;
            }
            if ($message !== null && $code === 'ValidationError' && str_contains((string) $e->getAwsErrorMessage(), $message)) {
                return true;
            }
            throw $e;
        }
    }

    /** A function name without a `:qualifier` (version or alias). */
    private static function unqualified(string $name): string
    {
        return explode(':', $name, 2)[0];
    }
}
