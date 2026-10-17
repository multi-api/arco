<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Closure;
use Multiapi\Arco\Arco;
use Multiapi\Arco\Aws\AwsClients;
use Aws\Exception\AwsException;
use Multiapi\Arco\Config\AppConfig;
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
            $policy = PolicyBuilder::document($region, $account, $function, SecretResolver::references($variables), $config->permissions, $bucket);
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
        if ($console !== null) {
            $step('deploy.step_console', ['function' => $config->consoleFunctionName()]);
            $consoleKey = self::consolePrefix($config) . "{$console->package->hash}.zip";
            $consoleObject = $codeBucket->upload($bucket, $consoleKey, $console->package->path);
            if ($console->download !== null) {
                $codeBucket->uploadBinary($bucket, $console->download->key, $console->download->path, $console->download->bytes);
            }
            $functions = $this->consoleFunction();
            $previousConsole = $functions->liveVersion($config->consoleFunctionName());
            $consoleVersion = $functions->deploy(
                $config,
                $bucket,
                $consoleKey,
                $consoleObject,
                $roleArn,
                [...$variables, ...self::binaryVariables($bucket, $console)],
                $tags,
                $roleCreated,
            );
        }

        return new PreparedDeploy($revisionId, $previous, $bucket, $key, $consoleVersion, $previousConsole, $consoleKey, $console?->download?->key);
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

    public function rollback(AppConfig $config, string $revisionId, ?string $consoleVersion = null): void
    {
        $web = new WebFunctions($this->aws->lambdaWeb(), $this->sleeper);
        $web->waitEndpoint($config->functionName());
        $web->route($config->functionName(), $revisionId, $config);
        $web->waitEndpoint($config->functionName(), 600, $revisionId);
        if ($consoleVersion !== null) {
            $this->consoleFunction()->point($config->consoleFunctionName(), $consoleVersion);
        }
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
        $step('remove.step_console', ['function' => $config->consoleFunctionName()]);
        $this->consoleFunction()->delete($config->consoleFunctionName());
        $step('remove.step_function', ['function' => $config->functionName()]);
        (new WebFunctions($this->aws->lambdaWeb(), $this->sleeper))->delete($config->functionName());
        if ($config->role === null) {
            (new ExecutionRole($this->aws->iam()))->delete(ExecutionRole::roleName($config));
        }
    }

    public function consoleExists(AppConfig $config): bool
    {
        return $this->consoleFunction()->get($config->consoleFunctionName()) !== null;
    }

    public function removeConsole(AppConfig $config, Closure $onStep): void
    {
        // The scheduler rule goes before the function it invokes.
        $unreadable = fn (string $error) => $onStep('deploy.scheduler_unreadable', ['rule' => SchedulerRule::name($config), 'function' => $config->consoleFunctionName(), 'error' => $error]);
        if ($this->schedulerRuleExists($config, $unreadable)) {
            $this->schedulerRule()->delete($config);
            $onStep('deploy.scheduler_removed', []);
        }
        $this->consoleFunction()->delete($config->consoleFunctionName());
        $onStep('deploy.console_removed', ['function' => $config->consoleFunctionName()]);
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
        $this->schedulerRule()->put($config, $alias, self::tags($config));
        $onStep('deploy.scheduler_set', ['every' => $config->scheduler]);
    }

    public function applyLogRetention(AppConfig $config, Closure $onStep): void
    {
        if ($config->logRetention === null) {
            return;
        }
        $scope = $config->consoleEnabled() ? 'all' : 'web';
        (new LogRetention($this->aws->logs()))->apply(LogGroups::for($config, $scope), $config->logRetention);
        // {groups} is a code (web, all) the step printer translates: only the groups touched are named.
        $onStep(...self::logRetentionStep($config->logRetention, $scope));
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
        );
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
