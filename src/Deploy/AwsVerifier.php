<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Exception\AwsException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Multiapi\Arco\Config\AppConfig;

final class AwsVerifier implements Verifier
{
    /** Pages of the failure log tail. */
    private const MAX_PAGES = 50;
    /** Pages of a whole-group search, the last-attempt fallback of the cold-start lookup. */
    private const FALLBACK_PAGES = 10;
    /** The new revision's instances write the most recent streams; this many are searched. */
    private const RECENT_STREAMS = 50;
    private const MAX_INSTANCES = 5;
    private const REPORT_POLL_SECONDS = 3;
    /** Instance ids go into a quoted filter pattern; anything unusual is skipped. */
    private const INSTANCE_ID = '/^[A-Za-z0-9._:\/\[\]$-]+$/';

    /** Seconds a last attempt may run past the deadline. */
    private const GRACE_SECONDS = 5;

    /** CloudWatch Logs calls made by the current cold-start lookup, and its deadline. */
    private int $calls = 0;
    private float $deadline = 0.0;

    /**
     * @param int $reportWaitSeconds wall-clock limit of the cold-start lookup
     * @param int $callBudget CloudWatch Logs calls the cold-start lookup may make (FilterLogEvents has a low TPS quota)
     */
    public function __construct(
        private readonly CloudWatchLogsClient $logs,
        private readonly ClientInterface $http,
        private readonly Sleeper $sleeper,
        private readonly int $attempts = 5,
        private readonly int $reportWaitSeconds = 30,
        private readonly int $callBudget = 40,
    ) {
    }

    public function verify(AppConfig $config, DeployResult $result, string $healthPath, int $sinceMs, ?callable $onStep = null): VerificationResult
    {
        $group = '/aws/lambda/web/' . $config->functionName();
        $reason = null;
        $reasonParams = [];
        $step = $onStep ?? static function (string $key, array $params = []): void {
        };
        if ($config->auth === 'iam') {
            // An unsigned probe always gets 403 from an IAM-authenticated endpoint, so the
            // platform init report is the only signal: it must exist and say success.
            [$status, $error] = [null, null];
            $step('deploy.cold_start_lookup', ['seconds' => $this->reportWaitSeconds]);
            [$report, $logsError] = $this->initReport($group, $result->revisionId, $sinceMs);
            $ok = $report !== null && $report['status'] === 'success';
            if ($report === null) {
                [$reason, $reasonParams] = $logsError !== null
                    ? ['deploy.verify_iam_logs_unreadable', ['error' => $logsError]]
                    : ['deploy.verify_iam_no_report', []];
            }
        } else {
            [$status, $error] = $this->probe($result->url() . $healthPath);
            $healthy = $status !== null && $status >= 200 && $status < 300;
            // The report adds the cold start, or explains a failure. A CloudWatch error only leaves
            // it unknown: the probe decides health.
            $step('deploy.cold_start_lookup', ['seconds' => $this->reportWaitSeconds]);
            [$report] = $this->initReport($group, $result->revisionId, $sinceMs);
            $ok = $healthy && ($report === null || $report['status'] === 'success');
        }

        return new VerificationResult(
            $ok,
            $status,
            $error,
            $report['status'] ?? null,
            $report['errorType'] ?? null,
            $report['durationMs'] ?? null,
            $ok ? [] : $this->tail($group, $report['stream'] ?? null, $sinceMs),
            $reason,
            $reasonParams,
        );
    }

    /** @return array{0: ?int, 1: ?string} */
    private function probe(string $url): array
    {
        $last = [null, null];
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            try {
                $status = $this->http->request('GET', $url, ['http_errors' => false, 'allow_redirects' => false, 'timeout' => 30, 'version' => '1.1'])->getStatusCode();
                if ($status < 500) {
                    return [$status, null];
                }
                $last = [$status, null];
            } catch (GuzzleException $e) {
                $last = [null, $e->getMessage()];
            }
            if ($attempt < $this->attempts) {
                $this->sleeper->sleep(3);
            }
        }
        return $last;
    }

    /**
     * The init report of the new revision's earliest instance: the cold start the first request
     * paid. A failed init of any new instance wins, since it fails verification.
     *
     * The new instances write the most recently active streams, so each attempt lists those
     * (DescribeLogStreams by LastEventTime) and searches only them, for initStart of the revision
     * and initReport per instance. A busy group with thousands of older streams then costs a few
     * calls instead of a scan that runs out of pages. A bounded whole-group search is the
     * last-attempt fallback. Platform events reach CloudWatch Logs with a delay, so this polls
     * until a wall-clock deadline, within a budget of API calls. CloudWatch errors other than a
     * missing log group end the lookup and are returned instead of thrown.
     *
     * @return array{0: array{status: string, errorType: ?string, durationMs: ?int, stream: string}|null, 1: ?string}
     *     the report, or null, and the CloudWatch error that stopped the lookup
     */
    private function initReport(string $group, string $revisionId, int $sinceMs): array
    {
        $this->calls = 0;
        $deadline = $this->deadline = $this->sleeper->now() + $this->reportWaitSeconds;
        $startPattern = sprintf('{ $.type = "platform.initStart" && $.record.revisionId = "%s" }', $revisionId);
        /** @var array<string, array{stream: string, timestamp: int}> $starts instanceId => first initStart */
        $starts = [];
        /** @var array<string, array{status: string, errorType: ?string, durationMs: ?int, stream: string}> $reports */
        $reports = [];
        while (true) {
            $lastAttempt = $this->sleeper->now() + self::REPORT_POLL_SECONDS > $deadline;
            try {
                $streams = $this->recentStreams($group, $sinceMs);
                $found = $streams === [] ? [] : $this->events($group, $sinceMs, $startPattern, $streams);
                if ($found === [] && $starts === [] && $lastAttempt) {
                    $found = $this->events($group, $sinceMs, $startPattern, null, self::FALLBACK_PAGES);
                }
                foreach ($found as $event) {
                    $instance = self::text(self::record($event['decoded'])['instanceId'] ?? null);
                    if (preg_match(self::INSTANCE_ID, $instance) === 1 && (! isset($starts[$instance]) || $event['timestamp'] < $starts[$instance]['timestamp'])) {
                        $starts[$instance] = ['stream' => $event['logStreamName'], 'timestamp' => $event['timestamp']];
                    }
                }
                uasort($starts, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

                $pending = array_slice(array_diff_key($starts, $reports), 0, self::MAX_INSTANCES, true);
                if ($pending !== []) {
                    $pattern = sprintf('{ $.type = "platform.initReport" && (%s) }', implode(' || ', array_map(
                        fn (string $instance) => sprintf('$.record.instanceId = "%s"', $instance),
                        array_map('strval', array_keys($pending)),
                    )));
                    $events = $this->events($group, $sinceMs, $pattern, array_values(array_unique(array_column($pending, 'stream'))));
                    if ($events === [] && $lastAttempt) {
                        // In case a report was written to another stream than its initStart.
                        $events = $this->events($group, $sinceMs, $pattern, null, self::FALLBACK_PAGES);
                    }
                    foreach ($events as $event) {
                        $record = self::record($event['decoded']);
                        $instance = self::text($record['instanceId'] ?? null);
                        if (isset($pending[$instance])) {
                            $reports[$instance] = [
                                'status' => self::text($record['status'] ?? null) ?: 'unknown',
                                'errorType' => isset($record['errorType']) ? self::text($record['errorType']) : null,
                                'durationMs' => is_numeric($duration = self::metrics($record)['totalDurationMs'] ?? null) ? (int) round((float) $duration) : null,
                                'stream' => $starts[$instance]['stream'],
                            ];
                        }
                    }
                }

                foreach ($reports as $report) {
                    if ($report['status'] !== 'success') {
                        return [$report, null];
                    }
                }
                $earliest = array_key_first($starts);
                if ($earliest !== null && isset($reports[$earliest])) {
                    return [$reports[$earliest], null];
                }
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                    return [self::earliestReport($starts, $reports), $e->getAwsErrorCode() ?? $e->getMessage()];
                }
            }
            if ($lastAttempt || $this->calls >= $this->callBudget) {
                break;
            }
            $this->sleeper->sleep(self::REPORT_POLL_SECONDS);
        }

        // Out of time or calls: the earliest instance that did report is the best estimate left.
        return [self::earliestReport($starts, $reports), null];
    }

    /** Counts a cold-start lookup call, or refuses it when the budget or the deadline is spent. */
    private function mayCall(): bool
    {
        if ($this->calls >= $this->callBudget || $this->sleeper->now() >= $this->deadline + self::GRACE_SECONDS) {
            return false;
        }
        $this->calls++;
        return true;
    }

    /**
     * @param array<string, array{stream: string, timestamp: int}> $starts sorted by timestamp
     * @param array<string, array{status: string, errorType: ?string, durationMs: ?int, stream: string}> $reports
     * @return array{status: string, errorType: ?string, durationMs: ?int, stream: string}|null
     */
    private static function earliestReport(array $starts, array $reports): ?array
    {
        foreach (array_keys($starts) as $instance) {
            if (isset($reports[$instance])) {
                return $reports[$instance];
            }
        }
        return null;
    }

    /**
     * Streams written since the deploy started, most recent first. lastEventTimestamp is
     * updated lazily by CloudWatch, so creation and ingestion times count too.
     *
     * @return list<string>
     */
    private function recentStreams(string $group, int $sinceMs): array
    {
        if (! $this->mayCall()) {
            return [];
        }
        $result = $this->logs->describeLogStreams([
            'logGroupName' => $group,
            'orderBy' => 'LastEventTime',
            'descending' => true,
            'limit' => self::RECENT_STREAMS,
        ]);
        $names = [];
        foreach (self::rows($result['logStreams'] ?? []) as $stream) {
            $latest = max(array_map(fn (string $field) => is_numeric($stream[$field] ?? null) ? (int) $stream[$field] : 0, ['creationTime', 'lastEventTimestamp', 'lastIngestionTime']));
            $name = self::text($stream['logStreamName'] ?? null);
            if ($name !== '' && $latest >= $sinceMs) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * @param list<string>|null $streams search only these log streams (in chunks of 100)
     * @return list<array{decoded: array<string, mixed>, logStreamName: string, message: string, timestamp: int}>
     */
    private function events(string $group, int $sinceMs, string $pattern, ?array $streams, int $maxPages = self::MAX_PAGES): array
    {
        $rows = [];
        foreach ($streams === null ? [null] : array_chunk($streams, 100) as $chunk) {
            array_push($rows, ...$this->filterAll($group, $sinceMs, $pattern, $chunk, $maxPages, budgeted: true));
        }
        $events = [];
        foreach ($rows as $event) {
            $message = self::text($event['message'] ?? null);
            $decoded = json_decode($message, true);
            $events[] = [
                'decoded' => is_array($decoded) ? self::stringKeyed($decoded) : [],
                'logStreamName' => self::text($event['logStreamName'] ?? null),
                'message' => $message,
                'timestamp' => is_numeric($event['timestamp'] ?? null) ? (int) $event['timestamp'] : 0,
            ];
        }
        return $events;
    }

    /**
     * Follows nextToken (bounded), since a page may be empty while matches remain: a search
     * walks the group's streams a batch at a time.
     *
     * @param list<string>|null $streams
     * @return list<array<string, mixed>>
     */
    private function filterAll(string $group, int $sinceMs, ?string $pattern, ?array $streams = null, int $maxPages = self::MAX_PAGES, bool $budgeted = false): array
    {
        $rows = [];
        $token = null;
        for ($page = 0; $page < $maxPages; $page++) {
            if ($budgeted && ! $this->mayCall()) {
                break;
            }
            $params = ['logGroupName' => $group, 'startTime' => $sinceMs];
            if ($pattern !== null) {
                $params['filterPattern'] = $pattern;
            }
            if ($streams !== null) {
                $params['logStreamNames'] = $streams;
            }
            if ($token !== null) {
                $params['nextToken'] = $token;
            }
            $result = $this->logs->filterLogEvents($params);
            array_push($rows, ...self::rows($result['events'] ?? []));
            $next = $result['nextToken'] ?? null;
            if (! is_string($next) || $next === '') {
                break;
            }
            $token = $next;
        }

        return $rows;
    }

    /** @return list<string> */
    private function tail(string $group, ?string $stream, int $sinceMs): array
    {
        try {
            $events = $stream !== null
                ? $this->logs->getLogEvents(['logGroupName' => $group, 'logStreamName' => $stream, 'limit' => 30, 'startFromHead' => false])['events'] ?? []
                : array_slice($this->filterAll($group, $sinceMs, null), -30);
        } catch (AwsException) {
            return [];
        }
        return array_map(fn (array $e) => rtrim(self::text($e['message'] ?? null)), self::rows($events));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function metrics(array $record): array
    {
        $metrics = $record['metrics'] ?? [];

        return is_array($metrics) ? self::stringKeyed($metrics) : [];
    }

    /**
     * @param array<mixed> $decoded
     * @return array<string, mixed>
     */
    private static function record(array $decoded): array
    {
        $record = $decoded['record'] ?? [];

        return is_array($record) ? self::stringKeyed($record) : [];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $keyed = [];
        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $events): array
    {
        $rows = [];
        foreach (is_iterable($events) ? $events : [] as $event) {
            if (is_array($event)) {
                $rows[] = self::stringKeyed($event);
            }
        }

        return $rows;
    }
}
