<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;

/** Sets how long CloudWatch keeps the log groups of an environment. */
final class LogRetention
{
    public function __construct(private readonly CloudWatchLogsClient $logs)
    {
    }

    /**
     * Creates each group when missing (Lambda only creates it on first invoke, and a policy needs it),
     * then sets its retention in days, or with false removes the policy so logs never expire.
     *
     * @param list<string> $groups
     */
    public function apply(array $groups, int|false $days): void
    {
        foreach ($groups as $group) {
            try {
                $this->logs->createLogGroup(['logGroupName' => $group]);
            } catch (CloudWatchLogsException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                    throw $e;
                }
            }
            if ($days === false) {
                $this->logs->deleteRetentionPolicy(['logGroupName' => $group]);
            } else {
                $this->logs->putRetentionPolicy(['logGroupName' => $group, 'retentionInDays' => $days]);
            }
        }
    }
}
