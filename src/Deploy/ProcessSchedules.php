<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\EventBridge\EventBridgeClient;
use Aws\EventBridge\Exception\EventBridgeException;
use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;
use Closure;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ScheduleExpression;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * A process app's schedules: one EventBridge rule per schedule, invoking the console function's live alias
 * with {"arco":"schedule","name":"<name>"}. Rules are found by tag (tag:GetResources on `events:rule` with the
 * environment's `arco:app` / `arco:environment` tags and an `arco:schedule` tag naming the schedule), so the
 * Laravel scheduler rule (SchedulerRule), which has no `arco:schedule` tag, is never read or touched here.
 */
final class ProcessSchedules
{
    public const TAG = 'arco:schedule';
    public const TARGET_ID = 'arco-console';
    public const ENABLED = 'ENABLED';
    public const DISABLED = 'DISABLED';
    private const SUFFIX = '-scheduler';
    private const MAX_NAME = 64;

    public function __construct(
        private readonly EventBridgeClient $events,
        private readonly LambdaClient $lambda,
        private readonly ResourceGroupsTaggingAPIClient $tagging,
    ) {
    }

    /**
     * `arco-<app>-<env>-<name>-<hash8>-scheduler`: the hash (of `<app>/<env>/<name>`) tells apart names that
     * concatenate alike; over 64 characters the readable part is cut, the hash and suffix always stay. Matches
     * the IAM resource `rule/arco-*-scheduler`.
     */
    public static function ruleName(AppConfig $config, string $schedule): string
    {
        $tail = '-' . self::hash($config, $schedule) . self::SUFFIX;
        $readable = "arco-{$config->app}-{$config->environment}-$schedule";
        // A cut that ends on a hyphen would give `--<hash>`.
        return rtrim(substr($readable, 0, self::MAX_NAME - strlen($tail)), '-') . $tail;
    }

    /** The statement letting EventBridge invoke the console's live alias for one schedule's rule. */
    public static function statementId(AppConfig $config, string $schedule): string
    {
        return 'arco-schedule-' . self::hash($config, $schedule);
    }

    public static function input(string $schedule): string
    {
        return (string) json_encode(['arco' => 'schedule', 'name' => $schedule], JSON_UNESCAPED_SLASHES);
    }

    private static function hash(AppConfig $config, string $schedule): string
    {
        return substr(hash('sha256', "{$config->app}/{$config->environment}/$schedule"), 0, 8);
    }

    /**
     * Puts one rule per schedule of arco.php pointed at $aliasArn, and deletes the environment's schedule
     * rules no schedule declares any more (target, rule, invoke permission). An existing rule keeps its state
     * (a paused schedule stays paused); a new one starts disabled when any schedule rule of the environment
     * is disabled (the environment is paused). Every read and ownership check happens before the first change.
     *
     * @param string|null $aliasArn the console's live alias; null only without schedules
     * @param array<string, string> $tags the environment's tags (`arco:schedule` is added per rule)
     * @param Closure(string, array<string, string|int>): void $onStep
     * @param bool $force `deploy --force`: a rule of the same name another owner tagged is taken over
     * @throws DeployException deploy.not_owned, deploy.untagged_resource
     */
    public function sync(AppConfig $config, ?string $aliasArn, array $tags, Closure $onStep, bool $force = false): void
    {
        $found = $this->all($config);
        $declared = [];
        foreach ($config->schedules as $schedule) {
            $declared[self::ruleName($config, $schedule->name)] = $schedule;
        }
        $existing = [];
        $stale = [];
        $paused = false;
        foreach ($found as $rule) {
            $paused = $paused || $rule['state'] === self::DISABLED;
            $schedule = $declared[$rule['rule']] ?? null;
            if ($schedule !== null) {
                // A declared rule's own name: this environment's (listed by its tags), never stale. An
                // `arco:schedule` tag naming another schedule is corrected below, without a warning.
                $existing[$rule['rule']] = ['state' => $rule['state'], 'retag' => $schedule->name !== $rule['schedule'], 'forced' => false];
            } else {
                $stale[] = $rule;
            }
        }
        // A rule the tag listing does not show (another owner's, untagged, or just made and not listed yet) is
        // checked by name before anything changes.
        foreach ($declared as $name => $schedule) {
            if (isset($existing[$name])) {
                continue;
            }
            $rule = $this->describe($name);
            if ($rule === null) {
                continue;
            }
            $ruleTags = Aws::tagMap($this->events->listTagsForResource(['ResourceARN' => $rule['arn']])['Tags'] ?? []);
            Ownership::check($ruleTags, $config, $name, $force);
            $paused = $paused || $rule['state'] === self::DISABLED;
            // Another owner's (only past Ownership::check with --force) or untagged: taken over, with a warning.
            // This environment's with only `arco:schedule` missing or different: retagged silently.
            $forced = ! Ownership::tagged($ruleTags, $config);
            $retag = $forced || ($ruleTags[self::TAG] ?? null) !== $schedule->name;
            $existing[$name] = ['state' => $rule['state'], 'retag' => $retag, 'forced' => $forced];
        }
        if ($declared !== [] && $aliasArn === null) {
            throw new \LogicException('Schedules need the console alias.');
        }

        // Stale rules first: a duplicate of a declared schedule shares its statement id, put back below.
        foreach ($stale as $rule) {
            $this->delete($config, $rule['schedule'], $rule['rule']);
            $onStep('deploy.schedule_deleted', ['schedule' => $rule['schedule'], 'rule' => $rule['rule']]);
        }
        foreach ($declared as $name => $schedule) {
            $state = $existing[$name]['state'] ?? ($paused ? self::DISABLED : self::ENABLED);
            $ruleTags = [...$tags, self::TAG => $schedule->name];
            $ruleArn = $this->put($config, $name, $schedule->name, $schedule->every, $state, (string) $aliasArn, $ruleTags);
            if ($existing[$name]['retag'] ?? false) {
                $this->events->tagResource(['ResourceARN' => $ruleArn, 'Tags' => self::tagList($ruleTags)]);
            }
            if ($existing[$name]['forced'] ?? false) {
                $onStep('deploy.ownership_forced', ['resource' => $name, 'app' => $config->app, 'environment' => $config->environment]);
            }
            $onStep(isset($existing[$name]) ? 'deploy.schedule_updated' : 'deploy.schedule_created', ['schedule' => $schedule->name, 'rule' => $name]);
            if ($state === self::DISABLED) {
                $onStep('deploy.schedule_paused', ['schedule' => $schedule->name]);
            }
        }
    }

    /**
     * The environment's schedule rules by schedule name, from their tags, with their state. When two rules
     * carry the same schedule name, the one with the expected name wins.
     *
     * @return array<string, array{rule: string, state: string}>
     */
    public function rules(AppConfig $config): array
    {
        $rules = [];
        foreach ($this->all($config) as $rule) {
            if (! isset($rules[$rule['schedule']]) || $rule['rule'] === self::ruleName($config, $rule['schedule'])) {
                $rules[$rule['schedule']] = ['rule' => $rule['rule'], 'state' => $rule['state']];
            }
        }
        ksort($rules);
        return $rules;
    }

    /**
     * Every schedule rule of the environment: tagged with its `arco:app`, `arco:environment` and an
     * `arco:schedule`, sorted by schedule name. A listed rule that is gone by the time it is described is left out.
     *
     * @return list<array{schedule: string, rule: string, state: string}>
     */
    public function all(AppConfig $config): array
    {
        $filters = [];
        foreach (Ownership::tags($config) as $key => $value) {
            $filters[] = ['Key' => $key, 'Values' => [$value]];
        }
        $filters[] = ['Key' => self::TAG];
        $rules = [];
        $token = null;
        do {
            $page = Aws::call($this->tagging, 'GetResources', [
                'ResourceTypeFilters' => ['events:rule'],
                'TagFilters' => $filters,
                ...($token === null ? [] : ['PaginationToken' => $token]),
            ]);
            foreach (Aws::maps($page['ResourceTagMappingList'] ?? []) as $mapping) {
                $arn = Aws::string($mapping['ResourceARN'] ?? null);
                $tags = Aws::tagMap($mapping['Tags'] ?? []);
                $schedule = $tags[self::TAG] ?? '';
                // The filter is AWS's; the check is Arco's: only this environment's schedule rules.
                if ($arn === null || $schedule === '' || ! Ownership::tagged($tags, $config)) {
                    continue;
                }
                [$type, $name] = TaggedResources::parse($arn);
                // Arco's rules live on the default event bus (rule/<name>).
                if ($type !== 'events:rule' || str_contains($name, '/')) {
                    continue;
                }
                $rule = $this->describe($name);
                if ($rule !== null) {
                    $rules[] = ['schedule' => $schedule, 'rule' => $name, 'state' => $rule['state']];
                }
            }
            $token = Aws::string($page['PaginationToken'] ?? null);
            $token = $token === '' ? null : $token;
        } while ($token !== null);
        usort($rules, static fn (array $a, array $b): int => [$a['schedule'], $a['rule']] <=> [$b['schedule'], $b['rule']]);
        return $rules;
    }

    /**
     * Disables every schedule rule of the environment; the rules, targets and permissions stay.
     *
     * @return list<array{schedule: string, rule: string, changed: bool}> one entry per rule, `changed` false when it was already disabled
     */
    public function pauseAll(AppConfig $config): array
    {
        return $this->setAll($config, self::DISABLED);
    }

    /** @return list<array{schedule: string, rule: string, changed: bool}> */
    public function resumeAll(AppConfig $config): array
    {
        return $this->setAll($config, self::ENABLED);
    }

    /**
     * Deletes every schedule rule of the environment (arco remove).
     *
     * @param (Closure(string, string): void)|null $onEach called with the schedule and rule names before each is deleted
     */
    public function deleteAll(AppConfig $config, ?Closure $onEach = null): void
    {
        foreach ($this->all($config) as $rule) {
            if ($onEach !== null) {
                $onEach($rule['schedule'], $rule['rule']);
            }
            $this->delete($config, $rule['schedule'], $rule['rule']);
        }
    }

    /** Removes one rule's target, the rule and its invoke permission; pieces already gone are fine. */
    public function delete(AppConfig $config, string $schedule, string $rule): void
    {
        self::ignoreMissing(fn () => $this->events->removeTargets(['Rule' => $rule, 'Ids' => [self::TARGET_ID]]));
        self::ignoreMissing(fn () => $this->events->deleteRule(['Name' => $rule]));
        self::ignoreMissing(fn () => $this->lambda->removePermission([
            'FunctionName' => $config->consoleFunctionName(),
            'Qualifier' => ConsoleFunction::ALIAS,
            'StatementId' => self::statementId($config, $schedule),
        ]));
    }

    /** @return list<array{schedule: string, rule: string, changed: bool}> */
    private function setAll(AppConfig $config, string $state): array
    {
        $out = [];
        foreach ($this->all($config) as $rule) {
            $changed = $rule['state'] !== $state;
            if ($changed) {
                $state === self::DISABLED
                    ? $this->events->disableRule(['Name' => $rule['rule']])
                    : $this->events->enableRule(['Name' => $rule['rule']]);
            }
            $out[] = ['schedule' => $rule['schedule'], 'rule' => $rule['rule'], 'changed' => $changed];
        }
        return $out;
    }

    /**
     * @param array<string, string> $tags
     * @return string the rule ARN
     */
    private function put(AppConfig $config, string $name, string $schedule, string $every, string $state, string $aliasArn, array $tags): string
    {
        $expression = ScheduleExpression::toEventBridge($every) ?? throw new \LogicException("Schedule \"$schedule\" has no valid expression.");
        $result = $this->events->putRule([
            'Name' => $name,
            'Description' => "Arco: schedule $schedule ($every) on {$config->consoleFunctionName()}:" . ConsoleFunction::ALIAS,
            'ScheduleExpression' => $expression,
            'State' => $state === self::DISABLED ? self::DISABLED : self::ENABLED,
            'Tags' => self::tagList($tags),
        ]);
        $ruleArn = $result['RuleArn'] ?? null;
        if (! is_string($ruleArn) || $ruleArn === '') {
            throw new \UnexpectedValueException('EventBridge returned no rule ARN.');
        }
        $targets = $this->events->putTargets([
            'Rule' => $name,
            'Targets' => [['Id' => self::TARGET_ID, 'Arn' => $aliasArn, 'Input' => self::input($schedule)]],
        ]);
        $failed = $targets['FailedEntries'] ?? [];
        if (is_array($failed) && $failed !== []) {
            $entry = is_array($failed[0] ?? null) ? $failed[0] : [];
            $message = $entry['ErrorMessage'] ?? $entry['ErrorCode'] ?? 'unknown error';
            throw new \RuntimeException("EventBridge refused the target of schedule $schedule: " . (is_string($message) ? $message : 'unknown error'));
        }
        try {
            $this->lambda->addPermission([
                'FunctionName' => $config->consoleFunctionName(),
                'Qualifier' => ConsoleFunction::ALIAS,
                'StatementId' => self::statementId($config, $schedule),
                'Action' => 'lambda:InvokeFunction',
                'Principal' => 'events.amazonaws.com',
                'SourceArn' => $ruleArn,
            ]);
        } catch (LambdaException $e) {
            // Already there: the rule ARN, and so the statement, never changes.
            if ($e->getAwsErrorCode() !== 'ResourceConflictException') {
                throw $e;
            }
        }
        return $ruleArn;
    }

    /** @return array{arn: string, state: string}|null null when there is no rule of that name */
    private function describe(string $name): ?array
    {
        try {
            $rule = Aws::map($this->events->describeRule(['Name' => $name])->toArray());
        } catch (EventBridgeException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
        $state = Aws::string($rule['State'] ?? null);
        return ['arn' => Aws::string($rule['Arn'] ?? null) ?? '', 'state' => $state === self::DISABLED ? self::DISABLED : self::ENABLED];
    }

    /**
     * @param array<string, string> $tags
     * @return list<array{Key: string, Value: string}>
     */
    private static function tagList(array $tags): array
    {
        return array_map(static fn (string $key, string $value): array => ['Key' => $key, 'Value' => $value], array_keys($tags), array_values($tags));
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
