<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\EventBridge\EventBridgeClient;
use Aws\EventBridge\Exception\EventBridgeException;
use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Multiapi\Arco\Config\AppConfig;

/**
 * The scheduler: an EventBridge rule that invokes the console function's live alias with
 * {"command":"schedule:run"} every N minutes, aligned to the clock (minute 0, N, 2N... of each hour),
 * so tasks like dailyAt('09:00') fire on their minute.
 */
final class SchedulerRule
{
    public const TARGET_ID = 'arco-console';
    public const STATEMENT_ID = 'arco-scheduler';
    public const INPUT = '{"command":"schedule:run"}';

    public function __construct(private readonly EventBridgeClient $events, private readonly LambdaClient $lambda)
    {
    }

    /** EventBridge rule names are unique per account and region, and at most 64 characters. */
    public static function name(AppConfig $config): string
    {
        $name = 'arco-' . $config->functionName() . '-scheduler';
        return strlen($name) <= 64 ? $name : substr($name, 0, 55) . '-' . substr(md5($name), 0, 8);
    }

    /** @param int $every minutes between runs, a divisor of 60 (SchedulerInterval::ALLOWED) */
    public static function expression(int $every): string
    {
        return match ($every) {
            1 => 'cron(* * * * ? *)',
            60 => 'cron(0 * * * ? *)',
            default => "cron(0/$every * * * ? *)",
        };
    }

    /**
     * Creates or updates the rule, points it at the console's live alias and lets EventBridge invoke it.
     *
     * @param array<string, string> $tags
     */
    public function put(AppConfig $config, string $consoleAliasArn, array $tags): void
    {
        $name = self::name($config);
        $every = $config->scheduler ?? throw new \LogicException('No scheduler is configured.');
        $rule = $this->events->putRule([
            'Name' => $name,
            'Description' => "Arco: schedule:run every $every min on {$config->consoleFunctionName()}:" . ConsoleFunction::ALIAS,
            'ScheduleExpression' => self::expression($every),
            'State' => 'ENABLED',
            'Tags' => array_map(static fn (string $key, string $value): array => ['Key' => $key, 'Value' => $value], array_keys($tags), array_values($tags)),
        ]);
        $ruleArn = $rule['RuleArn'] ?? null;
        if (! is_string($ruleArn) || $ruleArn === '') {
            throw new \UnexpectedValueException('EventBridge returned no rule ARN.');
        }

        $targets = $this->events->putTargets([
            'Rule' => $name,
            'Targets' => [['Id' => self::TARGET_ID, 'Arn' => $consoleAliasArn, 'Input' => self::INPUT]],
        ]);
        $failed = $targets['FailedEntries'] ?? [];
        if (is_array($failed) && $failed !== []) {
            $entry = is_array($failed[0] ?? null) ? $failed[0] : [];
            $message = $entry['ErrorMessage'] ?? $entry['ErrorCode'] ?? 'unknown error';
            throw new \RuntimeException('EventBridge refused the scheduler target: ' . (is_string($message) ? $message : 'unknown error'));
        }

        try {
            $this->lambda->addPermission([
                'FunctionName' => $config->consoleFunctionName(),
                'Qualifier' => ConsoleFunction::ALIAS,
                'StatementId' => self::STATEMENT_ID,
                'Action' => 'lambda:InvokeFunction',
                'Principal' => 'events.amazonaws.com',
                'SourceArn' => $ruleArn,
            ]);
        } catch (LambdaException $e) {
            // The statement is already there: the rule ARN, and so the statement, never changes.
            if ($e->getAwsErrorCode() !== 'ResourceConflictException') {
                throw $e;
            }
        }
    }

    public function exists(AppConfig $config): bool
    {
        try {
            $this->events->describeRule(['Name' => self::name($config)]);
            return true;
        } catch (EventBridgeException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Whether the console's live alias still has the statement letting EventBridge invoke it: the trace
     * put() leaves, readable with Lambda permissions alone. A missing function, alias or policy, or a
     * refused lambda:GetPolicy, all mean "no trace".
     */
    public function hasInvokePermission(AppConfig $config): bool
    {
        try {
            $policy = $this->lambda->getPolicy(['FunctionName' => $config->consoleFunctionName(), 'Qualifier' => ConsoleFunction::ALIAS])['Policy'] ?? null;
        } catch (LambdaException $e) {
            if (in_array($e->getAwsErrorCode(), ['ResourceNotFoundException', 'AccessDeniedException'], true)) {
                return false;
            }
            throw $e;
        }
        $document = is_string($policy) ? json_decode($policy, true) : null;
        $statements = is_array($document) ? ($document['Statement'] ?? []) : [];
        foreach (is_array($statements) ? $statements : [] as $statement) {
            if (is_array($statement) && ($statement['Sid'] ?? null) === self::STATEMENT_ID) {
                return true;
            }
        }
        return false;
    }

    /** Removes the target, the rule and the invoke permission; pieces already gone are fine. */
    public function delete(AppConfig $config): void
    {
        $name = self::name($config);
        self::ignoreMissing(fn () => $this->events->removeTargets(['Rule' => $name, 'Ids' => [self::TARGET_ID]]));
        self::ignoreMissing(fn () => $this->events->deleteRule(['Name' => $name]));
        self::ignoreMissing(fn () => $this->lambda->removePermission([
            'FunctionName' => $config->consoleFunctionName(),
            'Qualifier' => ConsoleFunction::ALIAS,
            'StatementId' => self::STATEMENT_ID,
        ]));
    }

    /** @param callable(): mixed $call */
    private static function ignoreMissing(callable $call): void
    {
        try {
            $call();
        } catch (EventBridgeException | LambdaException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
    }
}
