<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Aws\CloudFormation\CloudFormationClient;
use Aws\Exception\AwsException;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Sleeper;

final class Stacks
{
    private const POLL = 15;
    private const SUCCESS = ['CREATE_COMPLETE', 'UPDATE_COMPLETE'];
    /** States UpdateStack accepts. */
    private const UPDATABLE = ['CREATE_COMPLETE', 'UPDATE_COMPLETE', 'UPDATE_ROLLBACK_COMPLETE', 'IMPORT_COMPLETE', 'IMPORT_ROLLBACK_COMPLETE'];

    /** @param string $keys prefix of the error keys (failed, not_updatable): the domain flows keep their own wording */
    public function __construct(private readonly CloudFormationClient $cloudFormation, private readonly Sleeper $sleeper, private readonly string $keys = 'stack.')
    {
    }

    /** @return array{status: string, outputs: array<string, string>, tags: array<string, string>}|null */
    public function describe(string $name): ?array
    {
        $stack = $this->stack($name);
        if ($stack === null) {
            return null;
        }
        $tags = [];
        foreach (Aws::maps($stack['Tags'] ?? []) as $tag) {
            if (is_string($tag['Key'] ?? null) && is_string($tag['Value'] ?? null)) {
                $tags[$tag['Key']] = $tag['Value'];
            }
        }
        return ['status' => (string) Aws::string($stack['StackStatus'] ?? null), 'outputs' => self::outputs($stack), 'tags' => $tags];
    }

    /**
     * Creates or updates the stack and waits for it.
     *
     * @param array<string, string> $tags
     * @return array<string, string> stack outputs
     */
    public function deploy(string $name, string $body, array $tags, int $timeout = 1800): array
    {
        $stack = $this->stack($name);
        if ($stack !== null && str_ends_with((string) Aws::string($stack['StackStatus'] ?? null), '_IN_PROGRESS')) {
            $stack = $this->waitStable($name, $timeout);
        }
        // A first creation that rolled back can only be deleted.
        if ($stack !== null && in_array($stack['StackStatus'] ?? null, ['ROLLBACK_COMPLETE', 'ROLLBACK_FAILED'], true)) {
            $this->delete($name, $timeout);
            $stack = null;
        }

        if ($stack !== null && ! in_array($stack['StackStatus'] ?? null, self::UPDATABLE, true)) {
            throw DeployException::make($this->keys . 'not_updatable', ['stack' => $name, 'status' => (string) Aws::string($stack['StackStatus'] ?? null)]);
        }

        $args = [
            'StackName' => $name,
            'TemplateBody' => $body,
            'Tags' => array_map(fn (string $k, string $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), $tags),
        ];
        if ($stack === null) {
            Aws::call($this->cloudFormation, 'CreateStack', $args);
        } else {
            try {
                Aws::call($this->cloudFormation, 'UpdateStack', $args);
            } catch (AwsException $e) {
                if (str_contains((string) $e->getAwsErrorMessage(), 'No updates are to be performed')) {
                    return self::outputs($stack);
                }
                throw $e;
            }
        }

        $stack = $this->waitStable($name, $timeout) ?? throw DeployException::make($this->keys . 'failed', ['stack' => $name, 'status' => 'DELETED', 'reason' => '-']);
        $status = (string) Aws::string($stack['StackStatus'] ?? null);
        if (! in_array($status, self::SUCCESS, true)) {
            throw DeployException::make($this->keys . 'failed', ['stack' => $name, 'status' => $status, 'reason' => $this->failureReason($name)]);
        }
        return self::outputs($stack);
    }

    /** Deletes the stack and waits until it is gone. A missing stack is not an error. */
    public function delete(string $name, int $timeout = 1800): void
    {
        $stack = $this->stack($name);
        if ($stack !== null && str_ends_with((string) Aws::string($stack['StackStatus'] ?? null), '_IN_PROGRESS')) {
            $stack = $this->waitStable($name, $timeout);
        }
        if ($stack === null) {
            return;
        }
        Aws::call($this->cloudFormation, 'DeleteStack', ['StackName' => $name]);
        $stack = $this->waitStable($name, $timeout);
        if ($stack !== null && ($stack['StackStatus'] ?? null) !== 'DELETE_COMPLETE') {
            throw DeployException::make($this->keys . 'failed', ['stack' => $name, 'status' => (string) Aws::string($stack['StackStatus'] ?? null), 'reason' => $this->failureReason($name)]);
        }
    }

    /** @return array<string, mixed>|null the stack once no operation is in progress; null when it no longer exists */
    private function waitStable(string $name, int $timeout): ?array
    {
        for ($waited = 0; ; $waited += self::POLL) {
            $stack = $this->stack($name);
            if ($stack === null || ! str_ends_with((string) Aws::string($stack['StackStatus'] ?? null), '_IN_PROGRESS')) {
                return $stack;
            }
            if ($waited >= $timeout) {
                throw DeployException::make('deploy.timeout', ['what' => "stack $name"]);
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    /** @return array<string, mixed>|null */
    private function stack(string $name): ?array
    {
        try {
            $stacks = Aws::maps(Aws::call($this->cloudFormation, 'DescribeStacks', ['StackName' => $name])['Stacks'] ?? []);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ValidationError' && str_contains((string) $e->getAwsErrorMessage(), 'does not exist')) {
                return null;
            }
            throw $e;
        }
        $stack = $stacks[0] ?? null;
        return $stack === null || ($stack['StackStatus'] ?? null) === 'DELETE_COMPLETE' ? null : $stack;
    }

    /** The oldest resource failure of the latest operation: the cause, not the cascade of cancellations. */
    private function failureReason(string $name): string
    {
        $failures = [];
        foreach (Aws::maps(Aws::call($this->cloudFormation, 'DescribeStackEvents', ['StackName' => $name])['StackEvents'] ?? []) as $event) {
            $status = (string) Aws::string($event['ResourceStatus'] ?? null);
            $reason = (string) Aws::string($event['ResourceStatusReason'] ?? null);
            $isStack = ($event['LogicalResourceId'] ?? null) === $name;
            if ($isStack && in_array($status, ['CREATE_IN_PROGRESS', 'UPDATE_IN_PROGRESS', 'DELETE_IN_PROGRESS'], true)) {
                break;
            }
            if (! $isStack && str_ends_with($status, '_FAILED') && $reason !== '' && ! str_contains($reason, 'cancelled')) {
                $failures[] = (string) Aws::string($event['LogicalResourceId'] ?? null) . ': ' . $reason;
            }
        }
        return $failures === [] ? '-' : $failures[count($failures) - 1];
    }

    /**
     * @param array<string, mixed> $stack
     * @return array<string, string>
     */
    private static function outputs(array $stack): array
    {
        $outputs = [];
        foreach (Aws::maps($stack['Outputs'] ?? []) as $output) {
            if (is_string($output['OutputKey'] ?? null) && is_string($output['OutputValue'] ?? null)) {
                $outputs[$output['OutputKey']] = $output['OutputValue'];
            }
        }
        return $outputs;
    }
}
