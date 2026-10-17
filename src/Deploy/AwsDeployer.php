<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Closure;
use Multiapi\Arco\Arco;
use Multiapi\Arco\Aws\AwsClients;
use Aws\Exception\AwsException;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Deploy\Domains\Certificates;
use Multiapi\Arco\Deploy\Domains\CustomDomains;
use Multiapi\Arco\Deploy\Domains\DomainStack;
use Multiapi\Arco\Deploy\Domains\Stacks;
use Multiapi\Arco\Logs\LogGroups;
use Multiapi\Arco\Package\ConsoleArtifacts;
use Multiapi\Arco\Package\Package;
use Multiapi\Arco\Secrets\SecretResolver;

final class AwsDeployer implements Deployer
{
    private const KEEP_PACKAGES = 20;
    /** Console versions kept besides the live one: the newest, so recent rollbacks keep a matching console. */
    private const KEEP_CONSOLE_VERSIONS = 4;

    /**
     * @param AwsClients|null $dns clients for the account holding the DNS zones (dns() profile or
     *                             --dns-profile); null for the deploy account
     * @param bool $forceDns replace existing DNS records that point elsewhere (--force-dns)
     */
    public function __construct(
        private readonly AwsClients $aws,
        private readonly Sleeper $sleeper = new Sleeper(),
        private readonly ?AwsClients $dns = null,
        private readonly bool $forceDns = false,
    ) {
    }

    public function prepare(AppConfig $config, Package $package, ?ConsoleArtifacts $console, array $variables, ?callable $onStep = null): PreparedDeploy
    {
        $step = $onStep ?? static function (string $key, array $params = []): void {};
        $function = $config->functionName();
        $account = $this->aws->accountId();
        $region = (string) $config->region;
        $web = new WebFunctions($this->aws->lambdaWeb(), $this->sleeper);
        $bucket = CodeBucket::name($account, $region);
        $codeBucket = new CodeBucket($this->aws->s3());

        $step('deploy.step_bucket', ['bucket' => $bucket]);
        $codeBucket->ensure($bucket, $region, $account);

        $key = self::prefix($config) . "{$package->hash}.zip";
        $step('deploy.step_upload', ['size' => (int) round($package->bytes / 1048576)]);
        $versionId = $codeBucket->upload($bucket, $key, $package->path);

        $tags = self::tags($config);
        $roleCreated = false;
        if ($config->role !== null) {
            $roleArn = $config->role;
        } else {
            $step('deploy.step_role', ['role' => ExecutionRole::roleName($config)]);
            // runtime/* stays readable even when this console carries bin/php: the role is shared by every
            // console version, and an older download-based one can still be live (a failed verification,
            // a console skipped for size, arco rollback). Dropping it waits for the storage clean-up.
            $policy = PolicyBuilder::document($region, $account, $function, SecretResolver::references($variables), $config->permissions, $bucket,
                array_map(fn (QueueConfig $queue) => $queue->arn($config), $config->queues),
                array_map(fn (QueueConfig $queue) => $config->workerFunctionName($queue->name), $config->queues));
            ['arn' => $roleArn, 'created' => $roleCreated] = (new ExecutionRole($this->aws->iam()))->ensure(ExecutionRole::roleName($config), $policy, $tags);
        }

        $revision = RevisionFactory::revision($config, $bucket, $key, $versionId, $roleArn, $variables);
        $previous = null;
        if ($web->get($function) === null) {
            $step('deploy.step_function', ['function' => $function]);
            $revisionId = $web->create($function, $revision, RevisionFactory::initialEndpoint($config), $tags, $roleCreated);
        } else {
            $endpoint = $web->endpoint($function);
            $previous = $endpoint === null ? null : WebFunctions::activeRevision($endpoint);
            $step('deploy.step_revision', ['function' => $function]);
            $revisionId = $web->createRevision($function, $revision, $roleCreated);
        }
        $web->waitRevision($function, $revisionId);

        $consoleVersion = null;
        $previousConsole = null;
        $consoleKey = null;
        $workerVersions = [];
        if ($console !== null) {
            // The console zip also runs the queue workers: it is built (and uploaded) when queues exist even if the console is off.
            if ($config->consoleEnabled()) {
                $step('deploy.step_console', ['function' => $config->consoleFunctionName()]);
            }
            $consoleKey = self::consolePrefix($config) . "{$console->package->hash}.zip";
            $consoleObject = $codeBucket->upload($bucket, $consoleKey, $console->package->path);
            if ($console->download !== null) {
                $codeBucket->uploadBinary($bucket, $console->download->key, $console->download->path, $console->download->bytes);
            }
            $runtimeVariables = [...$variables, ...self::binaryVariables($bucket, $console)];
            if ($config->consoleEnabled()) {
                $functions = $this->consoleFunction();
                $previousConsole = $functions->liveVersion($config->consoleFunctionName());
                $consoleVersion = $functions->deploy($config, $bucket, $consoleKey, $consoleObject, $roleArn, $runtimeVariables, $tags, $roleCreated);
            }
            $workers = $this->workerFunctions();
            foreach ($config->queues as $queue) {
                $name = $config->workerFunctionName($queue->name);
                $step('deploy.step_worker', ['function' => $name, 'queue' => $queue->name]);
                $previousWorker = $workers->function()->liveVersion($name);
                $workerVersions[$queue->name] = [
                    'version' => $workers->deploy($config, $queue, $bucket, $consoleKey, $consoleObject, $roleArn, $runtimeVariables, $tags, $roleCreated),
                    'previous' => $previousWorker,
                ];
            }
        }

        return new PreparedDeploy($revisionId, $previous, $bucket, $key, $consoleVersion, $previousConsole, $consoleKey, $console?->download?->key, $workerVersions);
    }

    /**
     * Where the console bootstrap downloads the FrankenPHP binary from, and its checksum. No S3
     * version is pinned: runtime/* keys are immutable and the checksum verifies the bytes.
     *
     * @return array<string, string>
     */
    private static function binaryVariables(string $bucket, ConsoleArtifacts $console): array
    {
        if ($console->download === null) {
            return [];
        }
        return ['ARCO_BINARY_BUCKET' => $bucket, 'ARCO_BINARY_KEY' => $console->download->key, 'ARCO_BINARY_SHA256' => $console->download->sha256];
    }

    public function release(AppConfig $config, PreparedDeploy $prepared, ?callable $onStep = null): DeployResult
    {
        $step = $onStep ?? static function (string $key, array $params = []): void {};
        $function = $config->functionName();
        $web = new WebFunctions($this->aws->lambdaWeb(), $this->sleeper);

        $step('deploy.step_traffic', ['revision' => $prepared->revisionId]);
        $web->waitEndpoint($function);
        $web->route($function, $prepared->revisionId, $config);
        $endpoint = $web->waitEndpoint($function, 600, $prepared->revisionId);

        return new DeployResult(
            WebFunctions::text($endpoint['domainName'] ?? null),
            $prepared->revisionId,
            $prepared->previousRevisionId,
            $prepared->bucket,
            $prepared->key,
            [],
            $prepared->consoleVersion,
            $prepared->previousConsoleVersion,
            $prepared->workerVersions,
        );
    }

    public function prune(AppConfig $config, PreparedDeploy $prepared, ?callable $onStep = null): void
    {
        $step = $onStep ?? static function (string $key, array $params = []): void {};
        if ($prepared->consoleVersion !== null) {
            try {
                // The live alias is read again right before deleting: it may have moved since prepare().
                $this->consoleFunction()->pruneVersions(
                    $config->consoleFunctionName(),
                    self::KEEP_CONSOLE_VERSIONS,
                    array_values(array_filter([$prepared->previousConsoleVersion, $prepared->consoleVersion])),
                );
            } catch (AwsException $e) {
                $step('deploy.prune_versions_failed', ['function' => $config->consoleFunctionName(), 'error' => self::awsError($e)]);
            }
        }
        foreach ($prepared->workerVersions as $queue => $versions) {
            $name = $config->workerFunctionName($queue);
            try {
                $this->workerFunctions()->function()->pruneVersions(
                    $name,
                    self::KEEP_CONSOLE_VERSIONS,
                    array_values(array_filter([$versions['previous'], $versions['version']])),
                );
            } catch (AwsException $e) {
                $step('deploy.prune_versions_failed', ['function' => $name, 'error' => self::awsError($e)]);
            }
        }
        try {
            // Web and console zips are pruned apart: console zips never count towards the web zips kept.
            $codeBucket = new CodeBucket($this->aws->s3());
            $codeBucket->prune($prepared->bucket, self::prefix($config), self::KEEP_PACKAGES, [$prepared->key], self::consolePrefix($config));
            if ($prepared->consoleKey !== null) {
                $codeBucket->prune($prepared->bucket, self::consolePrefix($config), self::KEEP_PACKAGES, [$prepared->consoleKey]);
            }
        } catch (AwsException $e) {
            $step('deploy.prune_packages_failed', ['bucket' => $prepared->bucket, 'error' => self::awsError($e)]);
        }
        try {
            // Shared by every Arco app of the account and region: a binary goes only when no published
            // version of any Arco function references it (each can be rolled back to). This deploy's own
            // binary is kept outright: the tag listing may not show a function created seconds ago.
            $aws = $this->aws;
            (new RuntimeBinaries($aws->taggingApi(), $aws->lambda(), $aws->s3()))
                ->prune($prepared->bucket, (new \DateTimeImmutable())->setTimestamp((int) $this->sleeper->now()), $prepared->binaryKey === null ? [] : [$prepared->binaryKey]);
        } catch (\Exception $e) {
            $step('deploy.prune_runtime_failed', ['bucket' => $prepared->bucket, 'error' => $e instanceof AwsException ? self::awsError($e) : $e->getMessage()]);
        }
    }

    private static function awsError(AwsException $e): string
    {
        $message = $e->getAwsErrorMessage();
        return is_string($message) && $message !== '' ? $message : $e->getMessage();
    }

    public function activateConsole(AppConfig $config, string $version): void
    {
        $this->consoleFunction()->point($config->consoleFunctionName(), $version);
    }

    public function rollback(AppConfig $config, string $revisionId, ?string $consoleVersion = null, array $workerVersions = []): void
    {
        $web = new WebFunctions($this->aws->lambdaWeb(), $this->sleeper);
        $web->waitEndpoint($config->functionName());
        $web->route($config->functionName(), $revisionId, $config);
        $web->waitEndpoint($config->functionName(), 600, $revisionId);
        if ($consoleVersion !== null) {
            $this->consoleFunction()->point($config->consoleFunctionName(), $consoleVersion);
        }
        foreach ($workerVersions as $queue => $version) {
            // Only the alias moves back: the event source mapping follows it.
            $this->workerFunctions()->function()->point($config->workerFunctionName((string) $queue), $version);
        }
    }

    public function syncWorkers(AppConfig $config, array $workerVersions, Closure $onStep): void
    {
        $workers = $this->workerFunctions();
        $failed = static fn (string $queue, string $function, \Throwable $e) => $onStep('deploy.worker_failed', ['queue' => $queue, 'function' => $function, 'error' => self::errorText($e)]);
        foreach ($config->queues as $queue) {
            $version = $workerVersions[$queue->name]['version'] ?? null;
            if ($version === null) {
                continue;
            }
            try {
                $workers->activate($config, $queue, $version, $onStep);
            } catch (\Throwable $e) {
                $failed($queue->name, $config->workerFunctionName($queue->name), $e);
            }
        }

        // Workers of queues that left arco.php. Without queues in arco.php, the lookup is only worth a
        // warning when the profile may read functions: profiles that never used queues stay quiet.
        $kept = array_map(static fn (QueueConfig $queue): string => $queue->name, $config->queues);
        try {
            $stale = array_values(array_diff($workers->existing($config, self::tags($config)), $kept));
        } catch (\Throwable $e) {
            // Nothing to clean up is the common case: an app without queues never hears about a failed lookup.
            if ($config->queues !== []) {
                $onStep('deploy.worker_scan_failed', ['error' => self::errorText($e)]);
            }
            return;
        }
        foreach ($stale as $queue) {
            try {
                $workers->remove($config, $queue);
                $onStep('deploy.worker_removed', ['queue' => $queue, 'function' => $config->workerFunctionName($queue)]);
            } catch (\Throwable $e) {
                $failed($queue, $config->workerFunctionName($queue), $e);
            }
        }
    }

    private static function errorText(\Throwable $e): string
    {
        $message = $e instanceof AwsException ? $e->getAwsErrorMessage() : null;
        return is_string($message) && $message !== '' ? $message : $e->getMessage();
    }

    public function attachDomains(AppConfig $config, DeployResult $result, array $cachedPaths, ?callable $onStep = null): DeployResult
    {
        if ($config->domains === []) {
            return $result;
        }
        $step = $onStep ?? static function (string $key, array $params = []): void {};
        $domains = $this->customDomains($config)->deploy($config, $result->domainName, $cachedPaths, self::tags($config), $step);
        return $result->withDomains($domains);
    }

    public function hasDomainResources(AppConfig $config): bool
    {
        return $this->stackExists($config) || ($config->domains !== [] && $this->tolerateAccessDenied(
            fn () => (new Certificates($this->aws->acm(), $this->sleeper))->managed($config) !== [],
        ));
    }

    public function remove(AppConfig $config, ?callable $onStep = null): void
    {
        $step = $onStep ?? static function (string $key, array $params = []): void {};
        if ($config->domains !== [] || $this->stackExists($config)) {
            $this->customDomains($config)->remove($config, $step);
        }
        // The rule goes first: it would otherwise keep invoking a function that no longer exists.
        if ($this->schedulerRuleExists($config, fn (string $error) => $step('remove.scheduler_unreadable', ['rule' => SchedulerRule::name($config), 'error' => $error]))) {
            $step('remove.step_scheduler', ['rule' => SchedulerRule::name($config)]);
            $this->schedulerRule()->delete($config);
        }
        $this->removeWorkers($config, $step);
        $this->removeResources($config, $step);
        $step('remove.step_console', ['function' => $config->consoleFunctionName()]);
        $this->consoleFunction()->delete($config->consoleFunctionName());
        $step('remove.step_function', ['function' => $config->functionName()]);
        (new WebFunctions($this->aws->lambdaWeb(), $this->sleeper))->delete($config->functionName());
        if ($config->role === null) {
            (new ExecutionRole($this->aws->iam()))->delete(ExecutionRole::roleName($config));
        }
    }

    /**
     * Deletes the event source mappings and functions of the environment's workers: the queues in
     * arco.php and any left from queues removed earlier (found by tag; when that lookup fails, only the
     * queues in arco.php are removed, with a warning when there are any).
     *
     * @param callable(string, array<string, string|int>): void $step
     */
    private function removeWorkers(AppConfig $config, callable $step): void
    {
        $workers = $this->workerFunctions();
        $queues = array_map(static fn (QueueConfig $queue): string => $queue->name, $config->queues);
        try {
            $queues = array_values(array_unique([...$queues, ...$workers->existing($config, self::tags($config))]));
        } catch (\Throwable $e) {
            // Only the queues in arco.php are removed; without any, there is nothing to warn about.
            if ($config->queues !== []) {
                $step('remove.worker_scan_failed', ['error' => self::errorText($e)]);
            }
        }
        foreach ($queues as $queue) {
            $step('remove.step_worker', ['queue' => $queue, 'function' => $config->workerFunctionName($queue)]);
            $workers->remove($config, $queue);
        }
    }

    /**
     * Deletes the resources stack of the queues. Queues are retained: each is listed with its message
     * count, for the user to read and delete.
     *
     * @param callable(string, array<string, string|int>): void $step
     */
    private function removeResources(AppConfig $config, callable $step): void
    {
        $name = ResourcesStack::name($config);
        $stacks = new Stacks($this->aws->cloudFormation(), $this->sleeper);
        try {
            $stack = $stacks->describe($name);
        } catch (AwsException $e) {
            // Profiles that never used queues may lack CloudFormation permissions: no stack.
            if (in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true)) {
                return;
            }
            throw $e;
        }
        if ($stack === null) {
            return;
        }
        if (($stack['tags']['arco:app'] ?? null) !== $config->app || ($stack['tags']['arco:environment'] ?? null) !== $config->environment) {
            // Not ours: left alone, the rest of the remove goes on.
            return;
        }
        $urls = $this->stackQueues($name);
        $step('remove.step_resources', ['stack' => $name]);
        $stacks->delete($name);
        $queues = new Queues($this->aws->sqs(), $this->aws->lambda());
        foreach ($urls as $url) {
            $step('remove.queue_kept', ['queue' => self::queueName($url), 'url' => $url, 'messages' => $queues->messageCount($url) ?? '?']);
        }
    }

    public function consoleExists(AppConfig $config): bool
    {
        return $this->consoleFunction()->get($config->consoleFunctionName()) !== null;
    }

    public function removeConsole(AppConfig $config, Closure $onStep): void
    {
        // The scheduler rule goes before the function it invokes.
        $this->removeScheduler($config, $onStep);
        $this->consoleFunction()->delete($config->consoleFunctionName());
        $onStep('deploy.console_removed', ['function' => $config->consoleFunctionName()]);
    }

    public function removeScheduler(AppConfig $config, Closure $onStep): void
    {
        $unreadable = fn (string $error) => $onStep('deploy.scheduler_unreadable', ['rule' => SchedulerRule::name($config), 'function' => $config->consoleFunctionName(), 'error' => $error]);
        if ($this->schedulerRuleExists($config, $unreadable)) {
            $this->schedulerRule()->delete($config);
            $onStep('deploy.scheduler_removed', []);
        }
    }

    public function syncScheduler(AppConfig $config, Closure $onStep): void
    {
        if ($config->scheduler === null) {
            // Without EventBridge read access, only a rule this console's alias still lets in is worth a warning.
            $unreadable = function (string $error) use ($config, $onStep): void {
                if ($this->schedulerRule()->hasInvokePermission($config)) {
                    $onStep('deploy.scheduler_unreadable', ['rule' => SchedulerRule::name($config), 'function' => $config->consoleFunctionName(), 'error' => $error]);
                }
            };
            if ($this->schedulerRuleExists($config, $unreadable)) {
                $this->schedulerRule()->delete($config);
                $onStep('deploy.scheduler_removed', []);
            }
            return;
        }
        $function = $config->consoleFunctionName();
        $alias = $this->consoleFunction()->aliasArn($function) ?? throw DeployException::make('deploy.console_missing', ['function' => $function]);
        $paused = $this->schedulerRule()->put($config, $alias, self::tags($config));
        $onStep('deploy.scheduler_set', ['every' => $config->scheduler]);
        if ($paused) {
            $onStep('deploy.scheduler_paused', ['environment' => $config->environment]);
        }
    }

    public function applyLogRetention(AppConfig $config, Closure $onStep): void
    {
        if ($config->logRetention === null) {
            return;
        }
        $scope = self::logRetentionScope($config);
        $groups = LogGroups::for($config, 'web');
        if ($config->consoleEnabled()) {
            $groups = [...$groups, ...LogGroups::for($config, 'console')];
        }
        (new LogRetention($this->aws->logs()))->apply([...$groups, ...LogGroups::for($config, 'queues')], $config->logRetention);
        // {groups} is a code (web, all, web_queues, all_queues) the step printer translates: only the groups touched are named.
        $onStep(...self::logRetentionStep($config->logRetention, $scope));
    }

    /**
     * Which log groups get the retention: web always, the console's while it is on, and each queue
     * worker's whenever queues exist (workers run even with the console off).
     */
    public static function logRetentionScope(AppConfig $config): string
    {
        return ($config->consoleEnabled() ? 'all' : 'web') . ($config->queues === [] ? '' : '_queues');
    }

    /**
     * The step reporting an applied retention: its key (days, one day or never) and params.
     *
     * @return array{0: string, 1: array<string, string|int>}
     */
    public static function logRetentionStep(int|false $days, string $scope): array
    {
        return match (true) {
            $days === false => ['deploy.log_retention_never', ['groups' => $scope]],
            $days === 1 => ['deploy.log_retention_one_day', ['groups' => $scope]],
            default => ['deploy.log_retention', ['days' => $days, 'groups' => $scope]],
        };
    }

    public function describe(AppConfig $config): ?AppStatus
    {
        $web = new WebFunctions($this->aws->lambdaWeb(), $this->sleeper);
        $function = $web->get($config->functionName());
        if ($function === null) {
            return null;
        }
        $endpoint = $web->endpoint($config->functionName());
        $consoleVersion = null;
        $consoleVersionError = null;
        try {
            // No lookup while the console is off: the function is not expected to exist.
            if ($config->consoleEnabled()) {
                $consoleVersion = $this->consoleFunction()->liveVersion($config->consoleFunctionName());
            }
        } catch (AwsException $e) {
            // Shown as unknown (e.g. without lambda:GetAlias), never as "not deployed yet".
            $consoleVersionError = $e->getAwsErrorCode() ?? 'UnknownError';
        }
        $distribution = null;
        if ($config->domains !== []) {
            try {
                $distribution = $this->customDomains($config)->describe($config);
            } catch (AwsException) {
                // The function status is still useful without CloudFormation access.
            }
        }
        $schedulerPaused = null;
        if ($config->scheduler !== null) {
            try {
                $state = $this->schedulerRule()->state($config);
                $schedulerPaused = $state === null ? null : $state === SchedulerRule::DISABLED;
            } catch (AwsException) {
                // Unknown without EventBridge read access; the rest of the status still shows.
            }
        }
        return new AppStatus(
            $config->functionName(),
            WebFunctions::text($function['state'] ?? null),
            $endpoint === null ? null : WebFunctions::text($endpoint['domainName'] ?? null),
            $endpoint === null ? null : WebFunctions::activeRevision($endpoint),
            $distribution['domain'] ?? null,
            $distribution['status'] ?? null,
            $config->consoleEnabled() ? $config->consoleFunctionName() : null,
            $consoleVersion,
            $consoleVersionError,
            $schedulerPaused,
            $this->queueStatus($config),
        );
    }

    /**
     * The URL of each queue and the live version of its worker. A version that cannot be read (e.g.
     * without lambda:GetAlias) is unknown, with its error code, never "not deployed yet".
     *
     * @return array<string, array{url: ?string, version: ?string, error: ?string}>
     */
    private function queueStatus(AppConfig $config): array
    {
        if ($config->queues === []) {
            return [];
        }
        $located = $config;
        if ($config->account === null) {
            try {
                $located = $config->withAccount($this->aws->accountId());
            } catch (\Throwable) {
                $located = null;
            }
        }
        $workers = $this->workerFunctions()->function();
        $status = [];
        foreach ($config->queues as $queue) {
            $version = null;
            $error = null;
            try {
                $version = $workers->liveVersion($config->workerFunctionName($queue->name));
            } catch (AwsException $e) {
                $error = $e->getAwsErrorCode() ?? 'UnknownError';
            }
            $status[$queue->name] = ['url' => $located === null ? null : $queue->url($located), 'version' => $version, 'error' => $error];
        }
        return $status;
    }

    public function deployResources(AppConfig $config, Closure $onStep): void
    {
        $name = ResourcesStack::name($config);
        $created = ResourcesStack::createdQueues($config);
        $stacks = new Stacks($this->aws->cloudFormation(), $this->sleeper);
        try {
            $stack = $stacks->describe($name);
        } catch (AwsException $e) {
            // Without queues, profiles that never used them may lack CloudFormation permissions: no stack.
            if ($created !== [] || ! in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true)) {
                throw $e;
            }
            return;
        }
        if ($stack === null && $created === []) {
            return;
        }
        if ($stack !== null && (($stack['tags']['arco:app'] ?? null) !== $config->app || ($stack['tags']['arco:environment'] ?? null) !== $config->environment)) {
            throw DeployException::make('stack.not_ours', ['stack' => $name, 'app' => $config->app, 'environment' => $config->environment]);
        }
        $owned = $stack === null ? [] : $this->stackQueues($name);
        $queues = new Queues($this->aws->sqs(), $this->aws->lambda());

        // A new QueueName (fifo switched) would make CloudFormation replace the queue: the old one is
        // retained with its messages and the new one may collide with it. Stop before any change.
        foreach ($created as $queue) {
            $names = [ResourcesStack::logicalId($queue->name) => $queue->physicalName($config)];
            $dlq = $queue->dlqName($config);
            if ($dlq !== null) {
                $names[ResourcesStack::dlqLogicalId($queue->name)] = $dlq;
            }
            foreach ($names as $id => $to) {
                if (isset($owned[$id]) && self::queueName($owned[$id]) !== $to) {
                    throw DeployException::make('deploy.queue_replaced', ['queue' => $queue->name, 'from' => self::queueName($owned[$id]), 'to' => $to]);
                }
            }
        }

        // A queue removed earlier is retained under its name: CloudFormation would fail creating it
        // again, so the deploy stops here, before any change, and offers to reference it instead.
        foreach ($created as $queue) {
            if (! isset($owned[ResourcesStack::logicalId($queue->name)])) {
                $url = $queues->url($queue->physicalName($config));
                if ($url !== null) {
                    throw DeployException::make('deploy.queue_retained', ['queue' => $queue->name, 'existing' => "queue('{$queue->name}', existing: '" . self::queueArn($url) . "')"]);
                }
            }
            $dlq = $queue->dlqName($config);
            if ($dlq !== null && ! isset($owned[ResourcesStack::dlqLogicalId($queue->name)]) && $queues->url($dlq) !== null) {
                throw DeployException::make('deploy.queue_dlq_retained', ['queue' => $queue->name, 'dlq' => $dlq]);
            }
        }

        $template = ResourcesStack::template($config);
        /** @var array<string, mixed> $resources */
        $resources = $template['Resources'];
        $removed = array_diff_key($owned, $resources);

        $onStep('deploy.step_resources', ['stack' => $name]);
        if ($created === []) {
            // The queues stay (Retain): only the empty stack goes.
            $stacks->delete($name);
        } else {
            $stacks->deploy($name, ResourcesStack::body($template), self::tags($config));
            foreach ($created as $queue) {
                $key = isset($owned[ResourcesStack::logicalId($queue->name)]) ? 'deploy.queue_updated' : 'deploy.queue_created';
                $onStep($key, ['queue' => $queue->name, 'name' => $queue->physicalName($config)]);
            }
        }
        foreach ($removed as $url) {
            // A created queue switched to `existing:` itself (and its dead-letter queue, still its
            // redrive target) is still in use: no advice to delete it.
            $referenced = self::stillReferenced($config, $url);
            if ($referenced !== null) {
                $onStep($referenced[0], ['queue' => self::queueName($url), 'as' => $referenced[1]]);
                continue;
            }
            $onStep('deploy.queue_kept', ['queue' => self::queueName($url), 'url' => $url, 'messages' => $queues->messageCount($url) ?? '?']);
        }
    }

    /**
     * Whether a queue that left the resources stack is an `existing` queue of arco.php, or the
     * dead-letter queue Arco gave it (`<name>-dlq`, plus `.fifo`).
     *
     * @return array{0: string, 1: string}|null the step key and the arco.php queue name
     */
    private static function stillReferenced(AppConfig $config, string $url): ?array
    {
        $removed = QueueConfig::parseExisting($url);
        if ($removed === null) {
            return null;
        }
        foreach ($config->queues as $queue) {
            if ($queue->existing === null) {
                continue;
            }
            $referenced = QueueConfig::parseExisting($queue->url($config));
            if ($referenced === null || $referenced['region'] !== $removed['region'] || $referenced['account'] !== $removed['account']) {
                continue;
            }
            if ($referenced['name'] === $removed['name']) {
                return ['deploy.queue_now_existing', $queue->name];
            }
            $fifo = str_ends_with($referenced['name'], '.fifo');
            $dlq = ($fifo ? substr($referenced['name'], 0, -5) : $referenced['name']) . '-dlq' . ($fifo ? '.fifo' : '');
            if ($dlq === $removed['name']) {
                return ['deploy.queue_dlq_now_existing', $queue->name];
            }
        }
        return null;
    }

    /** @return array<string, string> URL of each SQS queue the stack owns, by logical id */
    private function stackQueues(string $stack): array
    {
        $queues = [];
        $result = Domains\Aws::call($this->aws->cloudFormation(), 'DescribeStackResources', ['StackName' => $stack]);
        foreach (Domains\Aws::maps($result['StackResources'] ?? []) as $resource) {
            $id = Domains\Aws::string($resource['LogicalResourceId'] ?? null);
            $url = Domains\Aws::string($resource['PhysicalResourceId'] ?? null);
            $status = (string) Domains\Aws::string($resource['ResourceStatus'] ?? null);
            if ($id !== null && $url !== null && ($resource['ResourceType'] ?? null) === 'AWS::SQS::Queue'
                && ! in_array($status, ['CREATE_FAILED', 'DELETE_COMPLETE', 'DELETE_SKIPPED'], true)) {
                $queues[$id] = $url;
            }
        }
        return $queues;
    }

    private static function queueName(string $url): string
    {
        return substr($url, (int) strrpos($url, '/') + 1);
    }

    /** The ARN of a queue from its URL (https://sqs.<region>.amazonaws.com/<account>/<name>). */
    private static function queueArn(string $url): string
    {
        $parsed = \Multiapi\Arco\Config\QueueConfig::parseExisting($url);
        return $parsed === null ? $url : "arn:aws:sqs:{$parsed['region']}:{$parsed['account']}:{$parsed['name']}";
    }

    /** @return array<string, string> */
    private static function tags(AppConfig $config): array
    {
        // Stable on purpose: a tag change would update every tagged resource (and the CloudFront stack) on
        // each Arco upgrade. The Arco version is in each revision's description instead.
        return ['arco:app' => $config->app, 'arco:environment' => $config->environment];
    }

    /** S3 prefix of the environment's web zips. */
    private static function prefix(AppConfig $config): string
    {
        return "{$config->app}/{$config->environment}/";
    }

    /** S3 prefix of the environment's console zips (inside the web prefix). */
    private static function consolePrefix(AppConfig $config): string
    {
        return self::prefix($config) . 'console-';
    }

    private function consoleFunction(): ConsoleFunction
    {
        return new ConsoleFunction($this->aws->lambda(), $this->sleeper);
    }

    private function workerFunctions(): WorkerFunctions
    {
        return new WorkerFunctions($this->aws->lambda(), $this->sleeper, new Queues($this->aws->sqs(), $this->aws->lambda()), $this->aws->taggingApi());
    }

    private function schedulerRule(): SchedulerRule
    {
        return new SchedulerRule($this->aws->eventBridge(), $this->aws->lambda());
    }

    /**
     * Whether the scheduler rule exists. When EventBridge refuses the lookup, $onDenied gets the AWS
     * message (the caller warns: the rule may still be there) and the answer is "no rule".
     *
     * @param Closure(string): void $onDenied
     */
    private function schedulerRuleExists(AppConfig $config, Closure $onDenied): bool
    {
        try {
            return $this->schedulerRule()->exists($config);
        } catch (AwsException $e) {
            if (! in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true)) {
                throw $e;
            }
            $onDenied(self::awsError($e));
            return false;
        }
    }

    private function customDomains(AppConfig $config): CustomDomains
    {
        return CustomDomains::create($config, $this->aws, $this->dns, $this->sleeper, $this->forceDns);
    }

    /**
     * Domains removed from arco.php may have left their stack behind. Profiles that never used
     * domains may lack CloudFormation permissions, which then means "no stack".
     */
    private function stackExists(AppConfig $config): bool
    {
        return $this->tolerateAccessDenied(fn () => (new Stacks($this->aws->cloudFormation(), $this->sleeper))->describe(DomainStack::stackName($config)) !== null);
    }

    /** @param callable(): bool $check */
    private function tolerateAccessDenied(callable $check): bool
    {
        try {
            return $check();
        } catch (AwsException $e) {
            if (in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true)) {
                return false;
            }
            throw $e;
        }
    }
}
