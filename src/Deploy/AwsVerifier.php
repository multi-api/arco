<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Exception\AwsException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Multiapi\Arco\Config\AppConfig;

final class AwsVerifier implements Verifier
{
    /** Pages of a log search (the failure tail, or one stream prefix of the cold-start lookup). */
    private const MAX_PAGES = 50;
    private const POLL_SECONDS = 4;
    /** Seconds a last attempt may run past the deadline. */
    private const GRACE_SECONDS = 5;
    /** A deploy that starts this soon after midnight UTC also searches the previous day's streams. */
    private const MIDNIGHT_MARGIN_SECONDS = 300;

    /** CloudWatch Logs calls made by the current cold-start lookup, and its deadline. */
    private int $calls = 0;
    private float $deadline = 0.0;

    /**
     * @param int $coldStartSeconds public endpoints: seconds after the probe passed to keep looking for the cold start
     * @param int $iamReportSeconds auth('iam'): seconds to wait for the init report, the only health signal
     * @param int $callBudget CloudWatch Logs calls one lookup may make (FilterLogEvents has a low TPS quota)
     */
    public function __construct(
        private readonly CloudWatchLogsClient $logs,
        private readonly ClientInterface $http,
        private readonly Sleeper $sleeper,
        private readonly int $attempts = 5,
        private readonly int $coldStartSeconds = 60,
        private readonly int $iamReportSeconds = 75,
        private readonly int $callBudget = 40,
    ) {
    }

    public function verify(AppConfig $config, DeployResult $result, string $healthPath, int $sinceMs, ?callable $onStep = null): VerificationResult
    {
        $reason = null;
        $reasonParams = [];
        $healthyAt = null;
        if ($config->auth === 'iam') {
            // An unsigned probe always gets 403 from an IAM-authenticated endpoint, so the
            // platform init report is the only signal: it must exist and say success.
            [$status, $error] = [null, null];
            if ($onStep !== null) {
                $onStep('deploy.cold_start_lookup', ['seconds' => $this->iamReportSeconds]);
            }
            [$report, $logsError] = $this->initReport($config, $result->revisionId, $sinceMs, $this->sleeper->now() + $this->iamReportSeconds);
            $ok = $report !== null && $report['status'] === 'success';
            if ($report === null) {
                [$reason, $reasonParams] = $logsError !== null
                    ? ['deploy.verify_iam_logs_unreadable', ['error' => $logsError]]
                    : ['deploy.verify_iam_no_report', []];
            }
        } else {
            // The probe alone decides: the cold start is read at the end of the deploy (coldStart()),
            // since platform events reach CloudWatch Logs up to ~40 s late.
            [$status, $error] = $this->probe($result->url() . $healthPath);
            $ok = $status !== null && $status >= 200 && $status < 300;
            $report = null;
            if ($ok) {
                $healthyAt = $this->sleeper->now();
            } else {
                // One look, without waiting, for an init error to explain the failure.
                [$report] = $this->initReport($config, $result->revisionId, $sinceMs, $this->sleeper->now());
            }
        }

        return new VerificationResult(
            $ok,
            $status,
            $error,
            $report['status'] ?? null,
            $report['errorType'] ?? null,
            $report['durationMs'] ?? null,
            $ok ? [] : $this->tail($this->group($config), $report['stream'] ?? null, $sinceMs),
            $reason,
            $reasonParams,
            $healthyAt,
        );
    }

    public function coldStart(AppConfig $config, DeployResult $result, int $sinceMs, VerificationResult $verification, ?callable $onStep = null): VerificationResult
    {
        if (! $verification->ok || $verification->initDurationMs !== null) {
            return $verification;
        }
        // The budget runs from the moment the probe passed, so time spent on custom domains counts.
        $deadline = ($verification->healthyAt ?? $this->sleeper->now()) + $this->coldStartSeconds;
        if ($onStep !== null) {
            $onStep('deploy.cold_start_lookup', ['seconds' => max(0, (int) round($deadline - $this->sleeper->now()))]);
        }
        [$report] = $this->initReport($config, $result->revisionId, $sinceMs, $deadline);

        return $report === null || $report['status'] !== 'success'
            ? $verification
            : $verification->withInit($report['status'], $report['errorType'], $report['durationMs']);
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
     * The init report of the new revision's earliest instance (by event timestamp): the cold start
     * the first request paid. A failed init of any new instance wins.
     *
     * Lambda Web Functions name each instance's stream `<YYYY/MM/DD>/<function>[<revision>]<id>...`,
     * so a stream-name prefix finds exactly the new revision's streams in any busy group. Events
     * arrive with a lag (10 to 36 s seen), so this polls until $deadline within a call budget; once a
     * report shows up it looks once more, because an earlier init may be ingested later. CloudWatch
     * errors other than a missing log group end the lookup and are returned, never thrown.
     *
     * @return array{0: array{status: string, errorType: ?string, durationMs: ?int, stream: string}|null, 1: ?string}
     *     the report, or null, and the CloudWatch error that stopped the lookup
     */
    private function initReport(AppConfig $config, string $revisionId, int $sinceMs, float $deadline): array
    {
        $this->calls = 0;
        $this->deadline = $deadline;
        $group = $this->group($config);
        /** @var array<string, array{status: string, errorType: ?string, durationMs: ?int, stream: string, timestamp: int}> $reports instanceId => report */
        $reports = [];
        $confirming = false;
        while (true) {
            $lastAttempt = $this->sleeper->now() + self::POLL_SECONDS > $deadline;
            try {
                foreach ($this->streamPrefixes($config->functionName(), $revisionId, $sinceMs) as $prefix) {
                    foreach ($this->filterAll($group, $sinceMs, '{ $.type = "platform.initReport" }', null, $prefix, budgeted: true) as $event) {
                        $decoded = json_decode(self::text($event['message'] ?? null), true);
                        $record = self::record(is_array($decoded) ? $decoded : []);
                        $instance = self::text($record['instanceId'] ?? null) ?: self::text($event['logStreamName'] ?? null);
                        $timestamp = is_numeric($event['timestamp'] ?? null) ? (int) $event['timestamp'] : PHP_INT_MAX;
                        if (! isset($reports[$instance]) || $timestamp < $reports[$instance]['timestamp']) {
                            $reports[$instance] = [
                                'status' => self::text($record['status'] ?? null) ?: 'unknown',
                                'errorType' => isset($record['errorType']) ? self::text($record['errorType']) : null,
                                'durationMs' => is_numeric($duration = self::metrics($record)['totalDurationMs'] ?? null) ? (int) round((float) $duration) : null,
                                'stream' => self::text($event['logStreamName'] ?? null),
                                'timestamp' => $timestamp,
                            ];
                        }
                    }
                }
                foreach ($reports as $report) {
                    if ($report['status'] !== 'success') {
                        return [self::strip($report), null];
                    }
                }
                if ($reports !== [] && ($confirming || $lastAttempt)) {
                    return [self::earliest($reports), null];
                }
                $confirming = $reports !== [];
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                    return [$reports === [] ? null : self::earliest($reports), $e->getAwsErrorCode() ?? $e->getMessage()];
                }
            }
            if ($lastAttempt || $this->calls >= $this->callBudget) {
                break;
            }
            $this->sleeper->sleep(self::POLL_SECONDS);
        }

        return [$reports === [] ? null : self::earliest($reports), null];
    }

    /**
     * Stream-name prefixes of the revision's instances: today's UTC date, plus the previous day
     * when the deploy started on another day or within a few minutes after midnight.
     *
     * @return list<string>
     */
    private function streamPrefixes(string $function, string $revisionId, int $sinceMs): array
    {
        $now = (int) floor($this->sleeper->now());
        $since = intdiv($sinceMs, 1000);
        $dates = [gmdate('Y/m/d', $now)];
        if (gmdate('Y/m/d', $since) !== $dates[0]) {
            $dates[] = gmdate('Y/m/d', $since);
        } elseif ($since % 86400 < self::MIDNIGHT_MARGIN_SECONDS) {
            $dates[] = gmdate('Y/m/d', $since - 86400);
        }
        return array_map(fn (string $date) => "$date/{$function}[{$revisionId}]", $dates);
    }

    /**
     * @param array<string, array{status: string, errorType: ?string, durationMs: ?int, stream: string, timestamp: int}> $reports
     * @return array{status: string, errorType: ?string, durationMs: ?int, stream: string}
     */
    private static function earliest(array $reports): array
    {
        uasort($reports, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);
        return self::strip($reports[(string) array_key_first($reports)]);
    }

    /**
     * @param array{status: string, errorType: ?string, durationMs: ?int, stream: string, timestamp: int} $report
     * @return array{status: string, errorType: ?string, durationMs: ?int, stream: string}
     */
    private static function strip(array $report): array
    {
        unset($report['timestamp']);
        return $report;
    }

    private function group(AppConfig $config): string
    {
        return '/aws/lambda/web/' . $config->functionName();
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
     * Follows nextToken (bounded), since a page may be empty while matches remain: a search
     * walks the group's streams a batch at a time.
     *
     * @param list<string>|null $streams
     * @return list<array<string, mixed>>
     */
    private function filterAll(string $group, int $sinceMs, ?string $pattern, ?array $streams = null, ?string $streamPrefix = null, int $maxPages = self::MAX_PAGES, bool $budgeted = false): array
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
            if ($streamPrefix !== null) {
                $params['logStreamNamePrefix'] = $streamPrefix;
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
