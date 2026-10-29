<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Aws\CloudFormation\CloudFormationClient;
use Aws\Exception\AwsException;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Sleeper;

final class Stacks
{
    private const POLL = 15;
    /** A change set is usually ready in seconds. */
    private const CHANGE_SET_POLL = 5;
    private const SUCCESS = ['CREATE_COMPLETE', 'UPDATE_COMPLETE'];
    /** States UpdateStack accepts. */
    private const UPDATABLE = ['CREATE_COMPLETE', 'UPDATE_COMPLETE', 'UPDATE_ROLLBACK_COMPLETE', 'IMPORT_COMPLETE', 'IMPORT_ROLLBACK_COMPLETE'];
    /**
     * States of a stack that holds no resource and can only be deleted: a first creation that rolled back, or
     * the REVIEW_IN_PROGRESS a change set on a new stack leaves when it is never executed.
     */
    public const EMPTY = ['ROLLBACK_COMPLETE', 'ROLLBACK_FAILED', 'REVIEW_IN_PROGRESS'];

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
     * The template the stack runs now (as it was submitted), or null when it cannot be read: any refused
     * or failed cloudformation:GetTemplate call, or a template that is not JSON.
     *
     * @return array<string, mixed>|null
     */
    public function template(string $name): ?array
    {
        try {
            $body = Aws::call($this->cloudFormation, 'GetTemplate', ['StackName' => $name, 'TemplateStage' => 'Original'])['TemplateBody'] ?? null;
        } catch (AwsException) {
            // Only used to say what an update changes: any failure means "unknown", reported as updated.
            return null;
        }
        if (is_string($body)) {
            $body = json_decode($body, true);
        }
        if (! is_array($body)) {
            return null;
        }
        $template = [];
        foreach ($body as $key => $value) {
            $template[(string) $key] = $value;
        }
        return $template;
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
        if (self::busy($stack)) {
            $stack = $this->waitStable($name, $timeout);
        }
        // A first creation that rolled back, or an empty stack left by an interrupted import, can only be deleted.
        if ($stack !== null && in_array($stack['StackStatus'] ?? null, self::EMPTY, true)) {
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

    /**
     * Imports existing resources into the stack (creating it when it does not exist) with an IMPORT change
     * set and waits for it. $body must be the template the stack runs plus the resources to import, each
     * with a DeletionPolicy and no reference to a resource not yet in the stack. A failed change set is
     * deleted (with the empty stack it left, for a new one); a failed import is rolled back by CloudFormation
     * (IMPORT_ROLLBACK_COMPLETE, still updatable). Both throw deploy.import_failed. An import cannot add or
     * change stack tags (CloudFormation refuses the change set): a stack created by it has none, so callers
     * that tell their stacks by tags create the stack first with deploy().
     *
     * @param list<array{ResourceType: string, LogicalResourceId: string, ResourceIdentifier: array<string, string>}> $resources
     */
    public function import(string $name, string $body, array $resources, int $timeout = 1800): void
    {
        $stack = $this->stack($name);
        if (self::busy($stack)) {
            $stack = $this->waitStable($name, $timeout);
        }
        if ($stack !== null && in_array($stack['StackStatus'] ?? null, self::EMPTY, true)) {
            $this->delete($name, $timeout);
            $stack = null;
        }
        if ($stack !== null && ! in_array($stack['StackStatus'] ?? null, self::UPDATABLE, true)) {
            throw DeployException::make($this->keys . 'not_updatable', ['stack' => $name, 'status' => (string) Aws::string($stack['StackStatus'] ?? null)]);
        }

        // The stack as the import finds it (a new one waits in review), to tell its result from an earlier state.
        $before = $stack === null ? ['REVIEW_IN_PROGRESS', ''] : [(string) Aws::string($stack['StackStatus']), self::updatedAt($stack)];
        $changeSet = ['StackName' => $name, 'ChangeSetName' => 'arco-import-' . (int) $this->sleeper->now()];
        Aws::call($this->cloudFormation, 'CreateChangeSet', [
            ...$changeSet,
            'ChangeSetType' => 'IMPORT',
            'TemplateBody' => $body,
            'ResourcesToImport' => $resources,
        ]);
        for ($waited = 0; ; $waited += self::CHANGE_SET_POLL) {
            $described = Aws::call($this->cloudFormation, 'DescribeChangeSet', $changeSet);
            $status = (string) Aws::string($described['Status'] ?? null);
            if ($status === 'CREATE_COMPLETE') {
                break;
            }
            if ($status === 'FAILED') {
                $this->discardChangeSet($changeSet, $stack === null);
                throw DeployException::make('deploy.import_failed', ['stack' => $name, 'reason' => Aws::string($described['StatusReason'] ?? null) ?? '-']);
            }
            if ($waited >= $timeout) {
                $this->discardChangeSet($changeSet, $stack === null);
                throw DeployException::make('deploy.timeout', ['what' => "change set {$changeSet['ChangeSetName']} of stack $name"]);
            }
            $this->sleeper->sleep(self::CHANGE_SET_POLL);
        }
        Aws::call($this->cloudFormation, 'ExecuteChangeSet', $changeSet);

        // The stack may still show its previous status right after ExecuteChangeSet (an UPDATE_COMPLETE, or the
        // IMPORT_COMPLETE of an earlier import): its status is only read once the change set has run.
        $gone = false;
        for ($waited = 0; ; $waited += self::CHANGE_SET_POLL) {
            try {
                $execution = (string) Aws::string(Aws::call($this->cloudFormation, 'DescribeChangeSet', $changeSet)['ExecutionStatus'] ?? null);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'ChangeSetNotFound') {
                    throw $e;
                }
                // CloudFormation removes a stack's change sets once one has been executed.
                $gone = true;
                break;
            }
            if (in_array($execution, ['EXECUTE_COMPLETE', 'EXECUTE_FAILED', 'OBSOLETE'], true)) {
                break;
            }
            if ($waited >= $timeout) {
                throw DeployException::make('deploy.timeout', ['what' => "stack $name"]);
            }
            $this->sleeper->sleep(self::CHANGE_SET_POLL);
        }
        $stack = $gone ? $this->waitForImport($name, $before, $timeout) : $this->waitStable($name, $timeout);
        $status = $stack === null ? 'DELETED' : (string) Aws::string($stack['StackStatus'] ?? null);
        if ($status !== 'IMPORT_COMPLETE') {
            throw DeployException::make('deploy.import_failed', ['stack' => $name, 'reason' => $stack === null ? $status : $this->failureReason($name)]);
        }
    }

    /**
     * The stack once the import whose change set CloudFormation already removed has ended. Without the change
     * set to ask, the stack is only taken as the result once it moved on from $before (an IMPORT_* operation
     * seen, another status or a newer LastUpdatedTime; never while it is still in review): right after
     * ExecuteChangeSet it may still show the status from before, an earlier import's IMPORT_COMPLETE included.
     * A stack still as it was when the wait ends never ran the import: deploy.import_failed.
     *
     * @param array{string, string} $before the status and LastUpdatedTime before ExecuteChangeSet
     * @return array<string, mixed>|null null when the stack no longer exists
     */
    private function waitForImport(string $name, array $before, int $timeout): ?array
    {
        $importing = false;
        for ($waited = 0; ; $waited += self::POLL) {
            $stack = $this->stack($name);
            $status = $stack === null ? null : (string) Aws::string($stack['StackStatus'] ?? null);
            $importing = $importing || ($status !== null && str_starts_with($status, 'IMPORT_') && self::busy($stack));
            // A stack still in review has not started the import, whatever time it reports.
            $moved = $importing || $stack === null
                || ($status !== 'REVIEW_IN_PROGRESS' && [$status, self::updatedAt($stack)] !== $before);
            if ($moved && ! self::busy($stack)) {
                return $stack;
            }
            if ($waited >= $timeout) {
                throw $moved
                    ? DeployException::make('deploy.timeout', ['what' => "stack $name"])
                    : DeployException::make('deploy.import_failed', ['stack' => $name, 'reason' => 'the change set was removed without running']);
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    /** @param array<string, mixed> $stack the stack's LastUpdatedTime as text ('' when it was never updated) */
    private static function updatedAt(array $stack): string
    {
        $time = $stack['LastUpdatedTime'] ?? null;
        return match (true) {
            $time instanceof \DateTimeInterface => $time->format(\DateTimeInterface::RFC3339_EXTENDED),
            is_string($time) => $time,
            default => '',
        };
    }

    /**
     * Deletes a change set that will not run, and the empty stack (REVIEW_IN_PROGRESS) an IMPORT on a new
     * stack leaves, which no later deploy could update.
     *
     * @param array{StackName: string, ChangeSetName: string} $changeSet
     */
    private function discardChangeSet(array $changeSet, bool $newStack): void
    {
        Aws::call($this->cloudFormation, 'DeleteChangeSet', $changeSet);
        if ($newStack) {
            Aws::call($this->cloudFormation, 'DeleteStack', ['StackName' => $changeSet['StackName']]);
        }
    }

    /** Deletes the stack and waits until it is gone. A missing stack is not an error. */
    public function delete(string $name, int $timeout = 1800): void
    {
        $stack = $this->stack($name);
        if (self::busy($stack)) {
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

    /**
     * Whether an operation runs on the stack, one that ends by itself. REVIEW_IN_PROGRESS (a change set on a
     * new stack that was never executed) does not: it stays until the stack is deleted.
     *
     * @param array<string, mixed>|null $stack
     */
    private static function busy(?array $stack): bool
    {
        $status = $stack === null ? '' : (string) Aws::string($stack['StackStatus'] ?? null);
        return str_ends_with($status, '_IN_PROGRESS') && $status !== 'REVIEW_IN_PROGRESS';
    }

    /** @return array<string, mixed>|null the stack once no operation is in progress (or left in review); null when it no longer exists */
    private function waitStable(string $name, int $timeout): ?array
    {
        for ($waited = 0; ; $waited += self::POLL) {
            $stack = $this->stack($name);
            if (! self::busy($stack)) {
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
            if ($isStack && in_array($status, ['CREATE_IN_PROGRESS', 'UPDATE_IN_PROGRESS', 'DELETE_IN_PROGRESS', 'IMPORT_IN_PROGRESS'], true)) {
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
