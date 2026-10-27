<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Multiapi\Arco\Arco;
use Multiapi\Arco\Aws\AwsClients;
use Aws\Exception\AwsException;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Config\GenericTableConfig;
use Multiapi\Arco\Config\StorageConfig;
use Multiapi\Arco\Config\TableConfig;
use Multiapi\Arco\Deploy\Domains\Certificates;
use Multiapi\Arco\Deploy\Domains\CloudFrontAccess;
use Multiapi\Arco\Deploy\Domains\CustomDomains;
use Multiapi\Arco\Deploy\Domains\DnsProvider;
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
     * @param bool $force change name-addressed resources another app or environment owns, or that carry
     *                    no Arco tags (deploy --force); never used by remove
     * @param DnsProvider|null $dnsProvider the DNS provider when it is not Route 53 (dns('cloudflare'))
     */
    public function __construct(
        private readonly AwsClients $aws,
        private readonly Sleeper $sleeper = new Sleeper(),
        private readonly ?AwsClients $dns = null,
        private readonly bool $forceDns = false,
        private readonly bool $force = false,
        private readonly ?DnsProvider $dnsProvider = null,
        /** HTTP client for the check through CloudFront after the endpoint is locked; null for a default one */
        private readonly ?ClientInterface $http = null,
    ) {
    }

    /** @var array<string, true> names whose ownership this deployer already checked (checkOwnership()) */
    private array $verified = [];

    public function checkOwnership(AppConfig $config, Closure $onStep): void
    {
        $guard = new OwnershipGuard($this->aws);
        $names = [];
        $resources = [];
        if ($config->role === null) {
            $role = $guard->role($names[] = ExecutionRole::roleName($config));
            // Before any change (bucket, upload, policy), and even with --force: it overrides ownership, not the region.
            if ($role !== null) {
                ExecutionRole::refuseOtherRegion($role->name, $role->tags[ExecutionRole::REGION_TAG] ?? null, (string) $config->region);
            }
            $resources[] = $role;
        }
        $resources[] = $guard->webFunction($names[] = $config->functionName(), self::prefix($config));
        // Checked whether the console is on or off: a deploy updates it, or deletes it once verified.
        $resources[] = $guard->lambdaFunction($names[] = $config->consoleFunctionName(), OwnershipGuard::CONSOLE_MARKER);
        foreach ($config->queues as $queue) {
            $resources[] = $guard->lambdaFunction($names[] = $config->workerFunctionName($queue->name), OwnershipGuard::WORKER_MARKER);
        }
        $rule = $guard->schedulerRule(SchedulerRule::name($config));
        if ($rule !== null && OwnershipGuard::isUndescribed($rule) && ! ($config->consoleEnabled() && $config->scheduler !== null)) {
            // No rule will be put; one that may exist is only deleted once it can be described (the
            // scheduler steps warn otherwise), and then it is checked there.
            $rule = null;
        } else {
            $names[] = SchedulerRule::name($config);
        }
        $resources[] = $rule;
        // Read-only, before claim() tags anything: a stack refusal later would come after those tags.
        $this->checkStacks($config);
        $guard->claim($config, array_values(array_filter($resources)), $this->force, $onStep);
        foreach ($names as $name) {
            $this->verified[$name] = true;
        }
    }

    /**
     * The stacks a deploy would refuse to update (stack.not_ours, domains.stack_not_ours) are read
     * here, before checkOwnership() tags or changes anything, and refused even with --force (it overrides
     * the ownership of functions, role and rule, never of a stack). deployResources() and the domains
     * step check again when they run.
     *
     * @throws DeployException stack.not_ours, domains.stack_not_ours
     * @throws AwsException a refused read of the resources stack while the config has resources (the
     *         deploy would stop on it in deployResources() anyway)
     */
    private function checkStacks(AppConfig $config): void
    {
        $stacks = new Stacks($this->aws->cloudFormation(), $this->sleeper);
        /** @var array<string, mixed> $resources */
        $resources = ResourcesStack::template($config)['Resources'];
        // Without resources, someone else's stack with the name is left alone: nothing to refuse.
        if ($resources !== []) {
            $name = ResourcesStack::name($config);
            $stack = $stacks->describe($name);
            if ($stack !== null && ! self::ownsStack($stack, $config)) {
                throw DeployException::make('stack.not_ours', ['stack' => $name, 'app' => $config->app, 'environment' => $config->environment]);
            }
        }
        if ($config->domains !== []) {
            $name = DomainStack::stackName($config);
            try {
                $stack = $stacks->describe($name);
            } catch (AwsException $e) {
                // The domains step reports a refused CloudFormation read when it runs.
                if (in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true)) {
                    return;
                }
                throw $e;
            }
            CustomDomains::assertOwned($stack, $name, $config);
        }
    }

    /** @param array{tags: array<string, string>} $stack */
    private static function ownsStack(array $stack, AppConfig $config): bool
    {
        return ($stack['tags']['arco:app'] ?? null) === $config->app && ($stack['tags']['arco:environment'] ?? null) === $config->environment;
    }

    /**
     * Checks one name-addressed resource outside a deploy's checkOwnership() (scheduler:set --apply, the
     * console's removal): skipped when this deployer already checked it.
     *
     * @param Closure(OwnershipGuard): ?NamedResource $read
     * @param callable(string, array<string, string|int>): void $onStep
     */
    private function claimOne(AppConfig $config, string $name, Closure $read, callable $onStep): void
    {
        if (isset($this->verified[$name])) {
            return;
        }
        $guard = new OwnershipGuard($this->aws);
        $resource = $read($guard);
        $guard->claim($config, $resource === null ? [] : [$resource], $this->force, $onStep);
        $this->verified[$name] = true;
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
            $step('deploy.step_role', ['role' => ExecutionRole::roleName($config), ...($config->permissionsBoundary === null ? [] : ['boundary' => $config->permissionsBoundary])]);
            // runtime/* stays readable even when this console carries bin/php: the role is shared by every
            // console version, and an older download-based one can still be live (a failed verification,
            // a console skipped for size, arco rollback). Dropping it waits for the storage clean-up.
            $policy = PolicyBuilder::document($region, $account, $function, SecretResolver::references($variables), $config->permissions, $bucket,
                array_map(fn (QueueConfig $queue) => $queue->arn($config), $config->queues),
                array_map(fn (QueueConfig $queue) => $config->workerFunctionName($queue->name), $config->queues),
                array_map(fn (StorageConfig $storage) => $storage->arn($config), $config->storage),
                array_map(fn (TableConfig $table) => $table->arn($config), array_values(array_filter([$config->cacheTable, $config->failedJobsTable]))),
                array_map(fn (GenericTableConfig $table) => $table->arn($config), $config->tables));
            ['arn' => $roleArn, 'created' => $roleCreated] = (new ExecutionRole($this->aws->iam()))->ensure(ExecutionRole::roleName($config), $policy, $tags, $region, $step, $config->permissionsBoundary);
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
            // The console and the workers are updated together (LambdaFunction::deployAll): each runs the
            // same ~180 MB zip, and Lambda takes its time over each code update.
            $requests = [];
            if ($config->consoleEnabled()) {
                $functions = $this->consoleFunction();
                $previousConsole = $functions->liveVersion($config->consoleFunctionName());
                $requests[] = $functions->request($config, $bucket, $consoleKey, $consoleObject, $roleArn, $runtimeVariables, $tags, $roleCreated);
            }
            $workers = $this->workerFunctions();
            $previousWorkers = [];
            foreach ($config->queues as $queue) {
                $name = $config->workerFunctionName($queue->name);
                $step('deploy.step_worker', ['function' => $name, 'queue' => $queue->name]);
                $previousWorkers[$queue->name] = $workers->function()->liveVersion($name);
                $requests[] = $workers->request($config, $queue, $bucket, $consoleKey, $consoleObject, $roleArn, $runtimeVariables, $tags, $roleCreated);
            }
            $versions = LambdaFunction::deployAll($this->sleeper, $requests);
            if ($config->consoleEnabled()) {
                $consoleVersion = $versions[$config->consoleFunctionName()];
            }
            foreach ($config->queues as $queue) {
                $workerVersions[$queue->name] = ['version' => $versions[$config->workerFunctionName($queue->name)], 'previous' => $previousWorkers[$queue->name]];
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
        $current = $web->waitEndpoint($function);
        if (! RevisionFactory::protectsEndpoint($config) && ($current['authType'] ?? null) === 'IamAuth' && $this->domainStackLeft($config, $step)) {
            // domain() left arco.php (or an emulator now serves it): Arco's CloudFront statement goes before the
            // endpoint takes the configured auth again. The distribution outlives the deploy (only remove deletes
            // its stack), so with auth('iam') it would otherwise keep serving the app. Without a stack there is no
            // distribution to invoke the endpoint and nothing to revoke: an auth('iam') → auth('public') change of
            // an environment that never had domain() makes no policy call, as in 0.11.3.
            $functionArn = WebFunctions::text($current['functionArn'] ?? null);
            if (RevisionFactory::authType($config) === 'ApplicationManaged') {
                $step('deploy.endpoint_unprotected', ['function' => $function]);
                $this->cloudFrontAccess($web)->revoke($functionArn);
            } elseif ($this->cloudFrontAccess($web)->revoke($functionArn)) {
                // Printed only when a statement went.
                $step('deploy.oac_revoked', ['function' => $function]);
            }
        }
        $web->route($function, $prepared->revisionId, $config, self::routedAuth($config, $current));
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
        $current = $web->waitEndpoint($config->functionName());
        $web->route($config->functionName(), $revisionId, $config, self::routedAuth($config, $current));
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

    public function attachDomains(AppConfig $config, DeployResult $result, array $cachedPaths, ?callable $onStep = null, string $healthPath = '/up'): DeployResult
    {
        if ($config->domains === []) {
            return $result;
        }
        $step = $onStep ?? static function (string $key, array $params = []): void {};
        if (! RevisionFactory::protectsEndpoint($config)) {
            // An emulator endpoint (AWS_ENDPOINT_URL): no origin access control, no policy, the configured auth.
            $step('deploy.endpoint_unprotected_emulator', ['function' => $config->functionName()]);
            return $result->withDomains($this->customDomains($config)->deploy($config, $result->domainName, $cachedPaths, self::tags($config), $step));
        }
        $access = $this->cloudFrontAccess(new WebFunctions($this->aws->lambdaWeb(), $this->sleeper));
        $domains = $this->customDomains($config)->deploy($config, $result->domainName, $cachedPaths, self::tags($config), $step,
            fn (string $distributionId, string $distributionDomain) => $access->protect($config, $distributionId, $distributionDomain, $healthPath, $step));
        return $result->withDomains($domains);
    }

    /**
     * The auth type traffic moves with. With domain(), the endpoint keeps the one it has: it becomes
     * IamAuth only once its distribution signs and may invoke it (CloudFrontAccess::protect(), after
     * the domains stack), so an upgrade never leaves CloudFront unable to reach it.
     *
     * @param array<string, mixed> $endpoint
     */
    private static function routedAuth(AppConfig $config, array $endpoint): string
    {
        $current = $endpoint['authType'] ?? null;
        return RevisionFactory::protectsEndpoint($config) && in_array($current, ['IamAuth', 'ApplicationManaged'], true) ? $current : RevisionFactory::authType($config);
    }

    private function cloudFrontAccess(WebFunctions $web): CloudFrontAccess
    {
        return new CloudFrontAccess($web, $this->aws->cloudFront(), $this->sleeper, $this->http ?? new Client());
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
        $queues = $this->workerQueues($config, $step);
        // Before anything is deleted: a resource another app or environment owns stops the whole remove.
        $this->checkRemoval($config, $queues);
        if ($config->domains !== [] || $this->stackExists($config)) {
            $this->customDomains($config)->remove($config, $step);
        }
        // The rule goes first: it would otherwise keep invoking a function that no longer exists.
        if ($this->schedulerRuleExists($config, fn (string $error) => $step('remove.scheduler_unreadable', ['rule' => SchedulerRule::name($config), 'error' => $error]))) {
            $step('remove.step_scheduler', ['rule' => SchedulerRule::name($config)]);
            $this->schedulerRule()->delete($config);
        }
        $this->removeWorkers($config, $queues, $step);
        $this->removeResources($config, $step);
        $step('remove.step_console', ['function' => $config->consoleFunctionName()]);
        $this->consoleFunction()->delete($config->consoleFunctionName());
        $step('remove.step_function', ['function' => $config->functionName()]);
        (new WebFunctions($this->aws->lambdaWeb(), $this->sleeper))->delete($config->functionName());
        if ($config->role === null) {
            (new ExecutionRole($this->aws->iam()))->delete(ExecutionRole::roleName($config));
        }
        $this->removeGroup($config, $step);
    }

    /**
     * Last in a remove: the Resource Group, when its query is this environment's. A convenience view, so
     * nothing here fails the remove: a refusal or another owner's group is a note.
     *
     * @param callable(string, array<string, string|int>): void $step
     */
    private function removeGroup(AppConfig $config, callable $step): void
    {
        $name = EnvironmentGroup::name($config);
        try {
            if ((new EnvironmentGroup($this->aws->resourceGroups()))->delete($config)) {
                $step('remove.step_group', ['group' => $name]);
            }
        } catch (DeployException $e) {
            $step($e->key, $e->params);
        } catch (AwsException $e) {
            $denied = in_array($e->getAwsErrorCode(), EnvironmentGroup::DENIED, true);
            $step($denied ? 'remove.group_denied' : 'remove.group_failed', ['group' => $name, 'error' => self::awsError($e)]);
        } catch (\Throwable $e) {
            // Anything else (a dropped connection, an unexpected answer): still only a note, the remove succeeded.
            $step('remove.group_failed', ['group' => $name, 'error' => self::errorText($e)]);
        }
    }

    public function syncGroup(AppConfig $config, Closure $onStep): void
    {
        $name = EnvironmentGroup::name($config);
        try {
            $changed = (new EnvironmentGroup($this->aws->resourceGroups()))->ensure($config);
        } catch (DeployException $e) {
            $onStep($e->key, $e->params);
            return;
        } catch (AwsException $e) {
            $denied = in_array($e->getAwsErrorCode(), EnvironmentGroup::DENIED, true);
            $onStep($denied ? 'deploy.group_denied' : 'deploy.group_failed', ['group' => $name, 'error' => self::awsError($e)]);
            return;
        }
        // Printed when the deploy created the group or updated its query: an unchanged group is not news.
        if ($changed) {
            $onStep('deploy.group_ready', ['group' => $name]);
        }
    }

    public function resources(AppConfig $config): array
    {
        return (new TaggedResources($this->aws->taggingApi(), new OwnershipGuard($this->aws)))->list($config);
    }

    /**
     * The queues whose workers remove deletes: the queues in arco.php and any left from queues removed
     * earlier (found by tag; when that lookup fails, only the queues in arco.php, with a warning when
     * there are any).
     *
     * @param callable(string, array<string, string|int>): void $step
     * @return list<string>
     */
    private function workerQueues(AppConfig $config, callable $step): array
    {
        $queues = array_map(static fn (QueueConfig $queue): string => $queue->name, $config->queues);
        try {
            return array_values(array_unique([...$queues, ...$this->workerFunctions()->existing($config, self::tags($config))]));
        } catch (\Throwable $e) {
            // Only the queues in arco.php are removed; without any, there is nothing to warn about.
            if ($config->queues !== []) {
                $step('remove.worker_scan_failed', ['error' => self::errorText($e)]);
            }
            return $queues;
        }
    }

    /**
     * Reads the tags of every name-addressed resource remove deletes and refuses, before any is
     * deleted, when one belongs to another app or environment (or carries no sign of Arco).
     *
     * @param list<string> $queues
     * @throws DeployException remove.not_owned, remove.untagged_resource, remove.tags_unreadable
     */
    private function checkRemoval(AppConfig $config, array $queues): void
    {
        $guard = new OwnershipGuard($this->aws);
        $rule = $guard->schedulerRule(SchedulerRule::name($config));
        // A rule EventBridge refuses to describe is not deleted either (remove.scheduler_unreadable).
        $resources = [$rule !== null && OwnershipGuard::isUndescribed($rule) ? null : $rule];
        foreach ($queues as $queue) {
            $resources[] = $guard->lambdaFunction($config->workerFunctionName($queue), OwnershipGuard::WORKER_MARKER);
        }
        $resources[] = $guard->lambdaFunction($config->consoleFunctionName(), OwnershipGuard::CONSOLE_MARKER);
        $resources[] = $guard->webFunction($config->functionName(), self::prefix($config));
        if ($config->role === null) {
            $role = $guard->role(ExecutionRole::roleName($config));
            // The role (and CloudFront) serve the function in the role's region: a region() change followed by
            // remove would otherwise delete them from under the environment still live there.
            if ($role !== null) {
                ExecutionRole::refuseOtherRegion($role->name, $role->tags[ExecutionRole::REGION_TAG] ?? null, (string) $config->region);
            }
            $resources[] = $role;
        }
        $guard->checkRemoval($config, array_values(array_filter($resources)));
    }

    /**
     * Deletes the event source mappings and functions of the environment's workers (workerQueues()).
     *
     * @param list<string> $queues
     * @param callable(string, array<string, string|int>): void $step
     */
    private function removeWorkers(AppConfig $config, array $queues, callable $step): void
    {
        $workers = $this->workerFunctions();
        foreach ($queues as $queue) {
            $step('remove.step_worker', ['queue' => $queue, 'function' => $config->workerFunctionName($queue)]);
            $workers->remove($config, $queue);
        }
    }

    /**
     * Deletes the resources stack. Queues, buckets and tables are retained: each is listed with what
     * is still in it (messages, files, items), for the user to read and delete.
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
            // Profiles that never used queues, storage or tables may lack CloudFormation permissions: no stack.
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
        $owned = $this->ownedResources($name);
        $step('remove.step_resources', ['stack' => $name]);
        $stacks->delete($name);
        $this->reportKept($owned, $step, 'remove.');
    }

    public function consoleExists(AppConfig $config): bool
    {
        return $this->consoleFunction()->get($config->consoleFunctionName()) !== null;
    }

    public function removeConsole(AppConfig $config, Closure $onStep): void
    {
        $this->claimOne($config, $config->consoleFunctionName(), fn (OwnershipGuard $guard) => $guard->lambdaFunction($config->consoleFunctionName(), OwnershipGuard::CONSOLE_MARKER), $onStep);
        // The scheduler rule goes before the function it invokes.
        $this->removeScheduler($config, $onStep);
        $this->consoleFunction()->delete($config->consoleFunctionName());
        $onStep('deploy.console_removed', ['function' => $config->consoleFunctionName()]);
    }

    public function removeScheduler(AppConfig $config, Closure $onStep): void
    {
        $unreadable = fn (string $error) => $onStep('deploy.scheduler_unreadable', ['rule' => SchedulerRule::name($config), 'function' => $config->consoleFunctionName(), 'error' => $error]);
        if ($this->schedulerRuleExists($config, $unreadable)) {
            $this->claimRule($config, $onStep);
            $this->schedulerRule()->delete($config);
            $onStep('deploy.scheduler_removed', []);
        }
    }

    /** @param callable(string, array<string, string|int>): void $onStep */
    private function claimRule(AppConfig $config, callable $onStep): void
    {
        $this->claimOne($config, SchedulerRule::name($config), fn (OwnershipGuard $guard) => $guard->schedulerRule(SchedulerRule::name($config)), $onStep);
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
                $this->claimRule($config, $onStep);
                $this->schedulerRule()->delete($config);
                $onStep('deploy.scheduler_removed', []);
            }
            return;
        }
        $function = $config->consoleFunctionName();
        $alias = $this->consoleFunction()->aliasArn($function) ?? throw DeployException::make('deploy.console_missing', ['function' => $function]);
        $this->claimRule($config, $onStep);
        $paused = $this->schedulerRule()->put($config, $alias, self::tags($config));
        $onStep('deploy.scheduler_set', ['every' => $config->scheduler]);
        if ($paused) {
            $onStep('deploy.scheduler_paused', ['environment' => $config->environment]);
        }
    }

    /** Days CloudWatch keeps the logs of an environment without a logRetention() setting. */
    public const DEFAULT_LOG_RETENTION = 30;

    public function applyLogRetention(AppConfig $config, Closure $onStep): void
    {
        // No setting: the default applies (and is announced when it changes a group), so groups never silently keep logs forever.
        $days = $config->logRetention ?? self::DEFAULT_LOG_RETENTION;
        $scope = self::logRetentionScope($config);
        $groups = LogGroups::for($config, 'web');
        if ($config->consoleEnabled()) {
            $groups = [...$groups, ...LogGroups::for($config, 'console')];
        }
        $region = (string) $config->region;
        // Lambda makes a group untagged on the first invoke: Arco adopts it and tags it for the environment.
        ['untagged' => $untagged, 'changed' => $changed] = (new LogRetention($this->aws->logs()))->apply([...$groups, ...LogGroups::for($config, 'queues')], $days, self::tags($config),
            fn (string $group): string => LogGroups::arn($region, $this->aws->accountId(), $group));
        if ($untagged !== []) {
            // One note for the deploy, not one per group, and only when a tag was attempted and refused.
            $onStep('deploy.log_tags_not_added', ['groups' => implode(', ', array_column($untagged, 'group')), 'error' => $untagged[0]['error']]);
        }
        if ($config->logRetention === null) {
            // The default is announced when it changes a group (new, or kept forever until now), not on every deploy.
            if ($changed !== []) {
                $onStep('deploy.log_retention_default', ['groups' => $scope]);
            }
            return;
        }
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
        $located = $this->located($config);
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
            $this->queueStatus($config, $located),
            $located?->account,
        );
    }

    /**
     * The config with its account, read from AWS only when arco.php has none and a queue or a created
     * storage needs it (URL, bucket name); null when it could not be read. Apps without these make no call.
     */
    private function located(AppConfig $config): ?AppConfig
    {
        if ($config->account !== null) {
            return $config;
        }
        $created = array_filter($config->storage, fn ($storage) => $storage->existing === null);
        if ($config->queues === [] && $created === []) {
            return null;
        }
        try {
            return $config->withAccount($this->aws->accountId());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The URL of each queue and the live version of its worker. A version that cannot be read (e.g.
     * without lambda:GetAlias) is unknown, with its error code, never "not deployed yet".
     *
     * @return array<string, array{url: ?string, version: ?string, error: ?string}>
     */
    private function queueStatus(AppConfig $config, ?AppConfig $located): array
    {
        if ($config->queues === []) {
            return [];
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
        $template = ResourcesStack::template($config);
        /** @var array<string, mixed> $resources */
        $resources = $template['Resources'];
        $wanted = $resources !== [];
        $created = ResourcesStack::createdQueues($config);
        $storage = ResourcesStack::createdStorage($config);
        $tables = ResourcesStack::createdTables($config);
        $generic = ResourcesStack::createdGenericTables($config);
        $stacks = new Stacks($this->aws->cloudFormation(), $this->sleeper);
        try {
            $stack = $stacks->describe($name);
        } catch (AwsException $e) {
            // Without resources, profiles that never used them may lack CloudFormation permissions: no stack.
            if ($wanted || ! in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true)) {
                throw $e;
            }
            return;
        }
        if ($stack === null && ! $wanted) {
            return;
        }
        if ($stack !== null && (($stack['tags']['arco:app'] ?? null) !== $config->app || ($stack['tags']['arco:environment'] ?? null) !== $config->environment)) {
            if (! $wanted) {
                // Someone else's stack with this name, and nothing to deploy: left alone.
                return;
            }
            throw DeployException::make('stack.not_ours', ['stack' => $name, 'app' => $config->app, 'environment' => $config->environment]);
        }
        $owned = $stack === null ? [] : $this->ownedResources($name);
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
                if (isset($owned[$id]) && self::queueName($owned[$id]['id']) !== $to) {
                    throw DeployException::make('deploy.queue_replaced', ['queue' => $queue->name, 'from' => self::queueName($owned[$id]['id']), 'to' => $to]);
                }
            }
        }
        // The same for a bucket or table whose name would change.
        $names = [];
        foreach ($storage as $bucket) {
            $names[ResourcesStack::bucketLogicalId($bucket->name)] = ["storage:{$bucket->name}", $bucket->bucketName($config)];
        }
        foreach ($tables as $table) {
            $names[ResourcesStack::tableLogicalId($table->kind)] = [$table->kind, $table->tableName($config)];
        }
        foreach ($generic as $table) {
            $names[ResourcesStack::genericTableLogicalId($table->name)] = ["table:{$table->name}", $table->tableName($config)];
        }
        foreach ($names as $id => [$resource, $to]) {
            if (isset($owned[$id]) && $owned[$id]['id'] !== $to) {
                throw DeployException::make('deploy.resource_replaced', ['resource' => $resource, 'from' => $owned[$id]['id'], 'to' => $to]);
            }
        }

        // A queue, bucket or table removed earlier is retained under its name: CloudFormation would
        // fail creating it again, so the deploy stops here, before any change, and offers to reference
        // it instead. A bucket name another account holds stops it the same way.
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
        $buckets = new Buckets($this->aws->s3());
        foreach ($storage as $bucket) {
            if (isset($owned[ResourcesStack::bucketLogicalId($bucket->name)])) {
                continue;
            }
            $bucketName = $bucket->bucketName($config);
            match ($buckets->exists($bucketName)) {
                'ours' => throw DeployException::make('deploy.storage_retained', ['storage' => $bucket->name, 'existing' => "storage('{$bucket->name}', existing: '{$bucketName}')"]),
                'taken' => throw DeployException::make('deploy.bucket_taken', ['bucket' => $bucketName]),
                'free' => null,
            };
        }
        $lookup = new Tables($this->aws->dynamoDb());
        foreach ($tables as $table) {
            if (! isset($owned[ResourcesStack::tableLogicalId($table->kind)]) && $lookup->exists($table->tableName($config))) {
                $method = $table->kind === 'cache' ? 'cache' : 'failedJobsTable';
                throw DeployException::make('deploy.table_retained', ['kind' => $table->kind, 'table' => $table->tableName($config), 'existing' => "{$method}(existing: '{$table->tableName($config)}')"]);
            }
        }
        foreach ($generic as $table) {
            if (! isset($owned[ResourcesStack::genericTableLogicalId($table->name)]) && $lookup->exists($table->tableName($config))) {
                throw DeployException::make('deploy.generic_table_retained', ['kind' => 'table', 'name' => $table->name, 'table' => $table->tableName($config), 'existing' => "table('{$table->name}', existing: '{$table->tableName($config)}')"]);
            }
        }

        $removed = array_diff_key($owned, $resources);

        $onStep('deploy.step_resources', ['stack' => $name]);
        if (! $wanted) {
            // The queues, buckets and tables stay (Retain): only the empty stack goes.
            $stacks->delete($name);
        } else {
            // What the stack runs now, to say which resources this update changes. Unknown: reported as changed.
            $changed = $stack === null ? [] : ResourcesStack::changedResources($stacks->template($name), $template);
            try {
                $stacks->deploy($name, ResourcesStack::body($template), self::tags($config));
            } catch (DeployException $e) {
                throw self::publicPolicyFailure($config, $e) ?? $e;
            }
            foreach ($created as $queue) {
                $id = ResourcesStack::logicalId($queue->name);
                $key = ! isset($owned[$id]) ? 'deploy.queue_created' : (self::isChanged($changed, $id) ? 'deploy.queue_changed' : 'deploy.queue_updated');
                $onStep($key, ['queue' => $queue->name, 'name' => $queue->physicalName($config)]);
            }
            foreach ($storage as $bucket) {
                $id = ResourcesStack::bucketLogicalId($bucket->name);
                $key = ! isset($owned[$id]) ? 'deploy.storage_created' : (self::isChanged($changed, $id) ? 'deploy.storage_changed' : 'deploy.storage_updated');
                $onStep($key, ['storage' => $bucket->name, 'bucket' => $bucket->bucketName($config)]);
                if ($bucket->public !== []) {
                    $onStep('deploy.storage_public', ['storage' => $bucket->name, 'prefixes' => implode(', ', $bucket->public), 'url' => $bucket->url($config)]);
                }
            }
            foreach ($tables as $table) {
                $id = ResourcesStack::tableLogicalId($table->kind);
                $key = ! isset($owned[$id]) ? 'deploy.table_created' : (self::isChanged($changed, $id) ? 'deploy.table_changed' : 'deploy.table_updated');
                $onStep($key, ['kind' => $table->kind, 'name' => $table->tableName($config)]);
            }
            foreach ($generic as $table) {
                $id = ResourcesStack::genericTableLogicalId($table->name);
                $key = ! isset($owned[$id]) ? 'deploy.generic_table_created' : (self::isChanged($changed, $id) ? 'deploy.generic_table_changed' : 'deploy.generic_table_updated');
                $onStep($key, ['table' => $table->name, 'name' => $table->tableName($config)]);
            }
        }
        $this->reportKept($removed, $onStep, 'deploy.', $config);
    }

    /** @param array<string, true>|null $changed null: unknown, so any resource may have changed */
    private static function isChanged(?array $changed, string $id): bool
    {
        return $changed === null || isset($changed[$id]);
    }

    /**
     * The stack failed on the bucket policy of a public prefix: say why in Arco's words. AWS names the
     * account (or bucket) Block Public Access setting that refused the policy; a plain access denial
     * may be that setting or the profile lacking s3:PutBucketPolicy.
     */
    private static function publicPolicyFailure(AppConfig $config, DeployException $e): ?DeployException
    {
        $reason = (string) ($e->params['reason'] ?? '');
        if ($e->key !== 'stack.failed') {
            return null;
        }
        foreach (ResourcesStack::createdStorage($config) as $storage) {
            if ($storage->public === []) {
                continue;
            }
            $bucket = $storage->bucketName($config);
            $onPolicy = str_starts_with($reason, ResourcesStack::bucketPolicyLogicalId($storage->name) . ':');
            if (($onPolicy || str_contains($reason, $bucket)) && preg_match('/BlockPublicPolicy|RestrictPublicBuckets|block public access|public policies are blocked/i', $reason) === 1) {
                return DeployException::make('deploy.public_blocked', ['bucket' => $bucket], $e);
            }
            if ($onPolicy && preg_match('/access ?denied|not authorized/i', $reason) === 1) {
                return DeployException::make('deploy.public_policy_denied', ['bucket' => $bucket, 'reason' => $reason], $e);
            }
        }
        return null;
    }

    /**
     * Names each queue, bucket and table that left the resources stack, which AWS kept (Retain), with
     * what is still in it. On deploy, one that arco.php now references with `existing` (or the
     * dead-letter queue of such a queue) is still in use: no advice to delete it.
     *
     * @param array<string, array{type: string, id: string}> $removed
     * @param callable(string, array<string, string|int>): void $step
     * @param 'deploy.'|'remove.' $prefix
     */
    private function reportKept(array $removed, callable $step, string $prefix, ?AppConfig $config = null): void
    {
        $queues = new Queues($this->aws->sqs(), $this->aws->lambda());
        $buckets = new Buckets($this->aws->s3());
        $tables = new Tables($this->aws->dynamoDb());
        foreach ($removed as $resource) {
            $id = $resource['id'];
            $referenced = $config === null ? null : self::stillReferenced($config, $resource['type'], $id);
            if ($referenced !== null) {
                $step($referenced[0], $referenced[1]);
                continue;
            }
            match ($resource['type']) {
                'AWS::SQS::Queue' => $step($prefix . 'queue_kept', ['queue' => self::queueName($id), 'url' => $id, 'messages' => $queues->messageCount($id) ?? '?']),
                'AWS::S3::Bucket' => $step($prefix . 'storage_kept', ['bucket' => $id, 'objects' => match ($buckets->hasObjects($id)) {
                    true => 'some',
                    false => 'none',
                    null => 'unknown',
                }]),
                default => ($items = $tables->itemCount($id)) === null
                    ? $step($prefix . 'table_kept_unknown', ['table' => $id])
                    : $step($prefix . 'table_kept', ['table' => $id, 'items' => $items]),
            };
        }
    }

    /**
     * Whether a resource that left the resources stack is referenced in arco.php with `existing`.
     *
     * @return array{0: string, 1: array<string, string>}|null the step key and its params
     */
    private static function stillReferenced(AppConfig $config, string $type, string $id): ?array
    {
        if ($type === 'AWS::SQS::Queue') {
            $queue = self::queueReferenced($config, $id);
            return $queue === null ? null : [$queue[0], ['queue' => self::queueName($id), 'as' => $queue[1]]];
        }
        if ($type === 'AWS::S3::Bucket') {
            foreach ($config->storage as $storage) {
                if ($storage->existing === $id) {
                    return ['deploy.storage_now_existing', ['bucket' => $id, 'as' => $storage->name]];
                }
            }
            return null;
        }
        foreach ([$config->cacheTable, $config->failedJobsTable] as $table) {
            if ($table !== null && $table->existing === $id) {
                return ['deploy.table_now_existing', ['table' => $id, 'as' => $table->kind]];
            }
        }
        // By ARN: an `existing` ARN of another region or account with the same name is another table.
        $removed = GenericTableConfig::arnOf($id, $config);
        foreach ($config->tables as $table) {
            if ($table->existing !== null && GenericTableConfig::sameTable($table->arn($config), $removed)) {
                return ['deploy.generic_table_now_existing', ['table' => $id, 'as' => $table->name]];
            }
        }
        return null;
    }

    /**
     * Whether a queue that left the resources stack is an `existing` queue of arco.php, or the
     * dead-letter queue Arco gave it (`<name>-dlq`, plus `.fifo`).
     *
     * @return array{0: string, 1: string}|null the step key and the arco.php queue name
     */
    private static function queueReferenced(AppConfig $config, string $url): ?array
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

    /** Resource types of the resources stack that hold data, and are retained when they leave it. */
    private const RETAINED_TYPES = ['AWS::SQS::Queue', 'AWS::S3::Bucket', 'AWS::DynamoDB::Table'];

    /**
     * The queues, buckets and tables the stack owns, by logical id, with their physical id: the URL of
     * a queue, the name of a bucket or table.
     *
     * @return array<string, array{type: string, id: string}>
     */
    private function ownedResources(string $stack): array
    {
        $owned = [];
        $result = Domains\Aws::call($this->aws->cloudFormation(), 'DescribeStackResources', ['StackName' => $stack]);
        foreach (Domains\Aws::maps($result['StackResources'] ?? []) as $resource) {
            $id = Domains\Aws::string($resource['LogicalResourceId'] ?? null);
            $physical = Domains\Aws::string($resource['PhysicalResourceId'] ?? null);
            $type = Domains\Aws::string($resource['ResourceType'] ?? null);
            $status = (string) Domains\Aws::string($resource['ResourceStatus'] ?? null);
            if ($id !== null && $physical !== null && $type !== null && in_array($type, self::RETAINED_TYPES, true)
                && ! in_array($status, ['CREATE_FAILED', 'DELETE_COMPLETE', 'DELETE_SKIPPED'], true)) {
                $owned[$id] = ['type' => $type, 'id' => $physical];
            }
        }
        return $owned;
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
        return Ownership::tags($config);
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
        return CustomDomains::create($config, $this->aws, $this->dns, $this->sleeper, $this->forceDns, $this->dnsProvider);
    }

    /**
     * Whether the domains stack is still there for an environment without domain() whose endpoint is IamAuth.
     * A refused read is a warning, not a silent "no": if the stack is left, its distribution may still invoke
     * the endpoint.
     *
     * @param callable(string, array<string, string|int>): void $step
     */
    private function domainStackLeft(AppConfig $config, callable $step): bool
    {
        try {
            return $this->domainStack($config) !== null;
        } catch (AwsException $e) {
            if (! self::isAccessDenied($e)) {
                throw $e;
            }
            $step('deploy.domain_stack_unreadable', ['stack' => DomainStack::stackName($config), 'function' => $config->functionName(), 'error' => self::awsError($e)]);
            return false;
        }
    }

    /**
     * Domains removed from arco.php may have left their stack behind. Profiles that never used
     * domains may lack CloudFormation permissions, which then means "no stack".
     */
    private function stackExists(AppConfig $config): bool
    {
        return $this->tolerateAccessDenied(fn () => $this->domainStack($config) !== null);
    }

    /** @return array{status: string, outputs: array<string, string>, tags: array<string, string>}|null */
    private function domainStack(AppConfig $config): ?array
    {
        return (new Stacks($this->aws->cloudFormation(), $this->sleeper))->describe(DomainStack::stackName($config));
    }

    private static function isAccessDenied(AwsException $e): bool
    {
        return in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true);
    }

    /** @param callable(): bool $check */
    private function tolerateAccessDenied(callable $check): bool
    {
        try {
            return $check();
        } catch (AwsException $e) {
            if (self::isAccessDenied($e)) {
                return false;
            }
            throw $e;
        }
    }
}
