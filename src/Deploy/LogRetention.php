<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Closure;

/** Sets how long CloudWatch keeps the log groups of an environment, and tags them for it. */
final class LogRetention
{
    private const DENIED = ['AccessDenied', 'AccessDeniedException'];

    public function __construct(private readonly CloudWatchLogsClient $logs)
    {
    }

    /**
     * Creates each group when missing (Lambda only creates it on first invoke, and a policy needs it),
     * then sets its retention in days, or with false removes the policy so logs never expire.
     *
     * The current retention is read first (logs:DescribeLogGroups): a group that already keeps logs for
     * $days (or, with false, has no policy) gets no retention call and is not reported as changed. When
     * that read is refused, every group gets the call and counts as changed, as before 0.9.
     *
     * With $tags, a new group is created with them and an existing one (often made untagged by Lambda)
     * is adopted: logs:TagResource on its ARN ($arn). A profile that may not tag gets the group created
     * untagged, and the retention is still set; those groups are returned.
     *
     * @param list<string> $groups
     * @param array<string, string> $tags
     * @param (Closure(string): string)|null $arn the ARN of a group, for tagging an existing one
     * @return array{untagged: list<array{group: string, error: string}>, changed: list<string>} the groups
     *         whose tags could not be added, and the groups whose retention this call changed (or may have)
     */
    public function apply(array $groups, int|false $days, array $tags = [], ?Closure $arn = null): array
    {
        $untagged = [];
        $changed = [];
        $target = $days === false ? null : $days;
        foreach ($groups as $group) {
            $current = $this->current($group);
            $error = $this->create($group, $tags, $arn);
            if ($error !== null) {
                $untagged[] = ['group' => $group, 'error' => $error];
            }
            if ($current !== self::UNKNOWN && $current !== self::MISSING && $current === $target) {
                continue;
            }
            $changed[] = $group;
            if ($days === false) {
                // A group created just now has no policy to remove.
                if ($current !== self::MISSING) {
                    $this->logs->deleteRetentionPolicy(['logGroupName' => $group]);
                }
            } else {
                $this->logs->putRetentionPolicy(['logGroupName' => $group, 'retentionInDays' => $days]);
            }
        }
        return ['untagged' => $untagged, 'changed' => $changed];
    }

    /** The group's retention could not be read (logs:DescribeLogGroups refused). */
    private const UNKNOWN = 'unknown';
    /** There is no group with the name yet. */
    private const MISSING = 'missing';

    /**
     * The group's retention in days, null when it never expires, or UNKNOWN / MISSING.
     *
     * @return int|null|self::UNKNOWN|self::MISSING
     */
    private function current(string $group): int|string|null
    {
        try {
            $result = $this->logs->describeLogGroups(['logGroupNamePrefix' => $group]);
        } catch (CloudWatchLogsException $e) {
            if (in_array($e->getAwsErrorCode(), self::DENIED, true)) {
                return self::UNKNOWN;
            }
            throw $e;
        }
        $found = $result->get('logGroups');
        // Sorted by name: the exact name comes first among the groups it is a prefix of.
        foreach (is_array($found) ? $found : [] as $candidate) {
            if (is_array($candidate) && ($candidate['logGroupName'] ?? null) === $group) {
                $retention = $candidate['retentionInDays'] ?? null;
                return is_int($retention) ? $retention : null;
            }
        }
        return self::MISSING;
    }

    /**
     * Creates the group (tagged), or tags the existing one.
     *
     * @param array<string, string> $tags
     * @param (Closure(string): string)|null $arn
     * @return string|null why the tags could not be added
     */
    private function create(string $group, array $tags, ?Closure $arn = null): ?string
    {
        try {
            $this->logs->createLogGroup(['logGroupName' => $group, ...($tags === [] ? [] : ['tags' => $tags])]);
            return null;
        } catch (CloudWatchLogsException $e) {
            if ($tags !== [] && in_array($e->getAwsErrorCode(), self::DENIED, true)) {
                // Creating with tags also needs logs:TagResource: create it untagged, and say so.
                $this->create($group, []);
                return self::message($e);
            }
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                throw $e;
            }
        }
        if ($tags === [] || $arn === null) {
            return null;
        }
        try {
            $this->logs->tagResource(['resourceArn' => $arn($group), 'tags' => $tags]);
        } catch (CloudWatchLogsException $e) {
            if (! in_array($e->getAwsErrorCode(), self::DENIED, true)) {
                throw $e;
            }
            return self::message($e);
        }
        return null;
    }

    private static function message(CloudWatchLogsException $e): string
    {
        $message = $e->getAwsErrorMessage();
        return is_string($message) && $message !== '' ? $message : $e->getMessage();
    }
}
