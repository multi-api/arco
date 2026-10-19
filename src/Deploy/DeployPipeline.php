<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\Endpoint;
use Multiapi\Arco\Binary\BinaryException;
use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Binary\RuntimeBuild;
use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\BuildResult;
use Multiapi\Arco\Build\CachesPaths;
use Multiapi\Arco\Build\FrameworkPreset;
use Multiapi\Arco\Build\ProcessCommandRunner;
use Multiapi\Arco\Build\ScheduleInspector;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\SchedulerInterval;
use Multiapi\Arco\Package\ConsoleArtifacts;
use Multiapi\Arco\Package\ConsolePackager;
use Multiapi\Arco\Package\Package;
use Multiapi\Arco\Package\Packager;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

final class DeployPipeline
{
    /** @var Closure(string): (list<array{task: string, minutes: list<int>}>|null) the app's scheduled tasks, read in the build's app dir */
    private readonly Closure $scheduleInspector;

    /** @param (Closure(string): (list<array{task: string, minutes: list<int>}>|null))|null $scheduleInspector default: ScheduleInspector with this PHP */
    public function __construct(
        private readonly Builder $builder,
        private readonly BinaryProvider $binaries,
        private readonly Packager $packager,
        ?Closure $scheduleInspector = null,
    ) {
        $this->scheduleInspector = $scheduleInspector ?? static fn (string $appDir): ?array => (new ScheduleInspector())->tasks($appDir, PHP_BINARY);
    }

    public static function default(): self
    {
        return new self(new Builder(new ProcessCommandRunner()), BinaryProvider::default(), new Packager());
    }

    /** The runtime build id the config resolves to, or null for a custom binary. */
    public function runtimeBuild(AppConfig $config): ?string
    {
        return $this->binaries->describe($config->runtime)?->id;
    }

    /** @return array<string, string> */
    public static function variables(AppConfig $config, FrameworkPreset $preset): array
    {
        return [
            ...$preset->defaultVariables(),
            ...self::injectedVariables($config),
            ...$config->variables,
            'ARCO' => '1',
            'ARCO_APP' => $config->app,
            'ARCO_ENV' => $config->environment,
        ];
    }

    /**
     * The variables Arco adds for the queues, the storage and the tables; the user's own win over them.
     *
     * @return array<string, string>
     */
    public static function injectedVariables(AppConfig $config): array
    {
        return [...self::queueVariables($config), ...self::storageVariables($config), ...self::tableVariables($config), ...self::endpointVariables()];
    }

    /**
     * With an AWS endpoint override (an emulator such as Floci) S3 needs path-style requests.
     *
     * @return array<string, string>
     */
    public static function endpointVariables(): array
    {
        return Endpoint::override() === null ? [] : ['AWS_USE_PATH_STYLE_ENDPOINT' => 'true'];
    }

    /**
     * The variables storage adds: the default bucket behind Laravel's stock s3 disk, and every bucket
     * (ARCO_STORAGE, name => bucket and url) for the named disks arco-laravel registers.
     *
     * @return array<string, string>
     */
    public static function storageVariables(AppConfig $config): array
    {
        $default = $config->defaultStorage();
        if ($default === null) {
            return [];
        }
        $buckets = [];
        foreach ($config->storage as $storage) {
            $buckets[$storage->name] = ['bucket' => $storage->bucketName($config), 'url' => $storage->url($config)];
        }
        return [
            'FILESYSTEM_DISK' => 's3',
            'AWS_BUCKET' => $default->bucketName($config),
            'AWS_URL' => $default->url($config),
            'ARCO_STORAGE' => json_encode($buckets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * The user variables() that replace what storageVariables() would set for the default disk
     * (FILESYSTEM_DISK, AWS_BUCKET), as key => the user's value. A created bucket's name needs the
     * account: without it ($located null) any user AWS_BUCKET counts as an override.
     *
     * @return array<string, string>
     */
    public static function storageOverrides(AppConfig $config, ?AppConfig $located): array
    {
        $default = $config->defaultStorage();
        if ($default === null) {
            return [];
        }
        $expected = $default->existing !== null || $located !== null ? $default->bucketName($located ?? $config) : null;
        $overrides = [];
        if (isset($config->variables['FILESYSTEM_DISK']) && $config->variables['FILESYSTEM_DISK'] !== 's3') {
            $overrides['FILESYSTEM_DISK'] = $config->variables['FILESYSTEM_DISK'];
        }
        if (isset($config->variables['AWS_BUCKET']) && $config->variables['AWS_BUCKET'] !== $expected) {
            $overrides['AWS_BUCKET'] = $config->variables['AWS_BUCKET'];
        }
        return $overrides;
    }

    /**
     * The variables the cache and failed-jobs tables add. ARCO_CACHE_TABLE only for a table Arco
     * created: arco-laravel then matches the store's attributes to it (key, value, expires_at).
     * arco-laravel applies ARCO_CACHE_TABLE and ARCO_FAILED_JOBS_TABLE over the app's config, so they are
     * left out when the user's own variables choose another cache table or store (DYNAMODB_CACHE_TABLE,
     * CACHE_STORE) or another failed-jobs driver (QUEUE_FAILED_DRIVER): the user's value must win.
     *
     * @return array<string, string>
     */
    public static function tableVariables(AppConfig $config): array
    {
        $variables = [];
        $user = $config->variables;
        if ($config->cacheTable !== null) {
            $table = $config->cacheTable->tableName($config);
            $variables['CACHE_STORE'] = 'dynamodb';
            $variables['DYNAMODB_CACHE_TABLE'] = $table;
            $overridden = (array_key_exists('DYNAMODB_CACHE_TABLE', $user) && $user['DYNAMODB_CACHE_TABLE'] !== $table)
                || (array_key_exists('CACHE_STORE', $user) && $user['CACHE_STORE'] !== 'dynamodb');
            if ($config->cacheTable->existing === null && ! $overridden) {
                $variables['ARCO_CACHE_TABLE'] = $table;
            }
        }
        if ($config->failedJobsTable !== null) {
            $variables['QUEUE_FAILED_DRIVER'] = 'dynamodb';
            if (! array_key_exists('QUEUE_FAILED_DRIVER', $user) || $user['QUEUE_FAILED_DRIVER'] === 'dynamodb') {
                $variables['ARCO_FAILED_JOBS_TABLE'] = $config->failedJobsTable->tableName($config);
            }
        }
        return $variables;
    }

    /**
     * The variables queues add (the user's own win over them): the sqs connection, the queue URL prefix, the default queue and every queue's URL.
     *
     * @return array<string, string>
     */
    public static function queueVariables(AppConfig $config): array
    {
        if ($config->queues === []) {
            return [];
        }
        $default = $config->defaultQueue();
        $queues = $config->queues;
        usort($queues, fn ($a, $b) => ($b === $default) <=> ($a === $default));
        $urls = [];
        foreach ($queues as $queue) {
            $urls[$queue->name] = $queue->url($config);
        }
        return [
            'QUEUE_CONNECTION' => 'sqs',
            'SQS_PREFIX' => "https://sqs.$config->region.amazonaws.com/$config->account",
            ...($default === null ? [] : ['SQS_QUEUE' => $default->url($config)]),
            'ARCO_QUEUES' => json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param callable(string, array<string, string|int>): void $onStep
     * @param list<string> $exclude more patterns to leave out of the package
     * @return array{0: BuildResult, 1: Package}
     */
    public function package(AppConfig $config, FrameworkPreset $preset, string $projectDir, callable $onStep, array $exclude = []): array
    {
        $onStep('build.staging', ['app' => $config->app, 'environment' => $config->environment, 'preset' => $preset->name()]);
        $build = $this->builder->build($config, $preset, $projectDir, $onStep, $exclude);
        try {
            foreach ($build->skipped as $path) {
                $onStep('build.symlink_skipped', ['path' => $path]);
            }
            $this->checkSchedule($config, $build, $onStep);
            $runtimeBuild = $this->binaries->describe($config->runtime);
            if ($runtimeBuild !== null) {
                $onStep('binary.using', ['build' => $runtimeBuild->id, 'frankenphp' => $runtimeBuild->frankenphp, 'php' => $runtimeBuild->php]);
            }
            $binary = $this->binaries->get($config->runtime, $projectDir);
            $onStep('package.writing', []);
            $package = $this->packager->package($build, $binary, $config, $preset, $runtimeBuild?->id, fn (int $percent) => $onStep('package.progress', ['percent' => $percent]));
            $onStep('package.ready', ['size' => (int) round($package->bytes / 1048576)]);
        } catch (Throwable $e) {
            $build->cleanup();
            throw $e;
        }
        return [$build, $package];
    }

    /**
     * Warns (deploy.scheduler_too_slow) when the scheduler runs schedule:run less often than some of
     * the app's tasks need, so they would be skipped. Best effort: an unreadable schedule says nothing.
     *
     * @param callable(string, array<string, string|int>): void $onStep
     */
    private function checkSchedule(AppConfig $config, BuildResult $build, callable $onStep): void
    {
        if ($config->scheduler === null) {
            return;
        }
        try {
            $tasks = ($this->scheduleInspector)($build->appDir);
        } catch (Throwable) {
            return;
        }
        if ($tasks === null) {
            return;
        }
        $missed = SchedulerInterval::missed($config->scheduler, $tasks);
        if ($missed !== []) {
            $minutes = array_merge([], ...array_map(static fn (array $task): array => $task['minutes'], $tasks));
            $onStep('deploy.scheduler_too_slow', [
                'every' => $config->scheduler,
                'tasks' => implode(', ', $missed),
                'required' => SchedulerInterval::required($minutes),
            ]);
        }
    }

    /**
     * Stops the deploy when the package lacks a package the app's features need: without
     * multiapi/arco-laravel or aws/aws-sdk-php every job would land in the dead-letter queue; without
     * league/flysystem-aws-s3-v3 every default-disk call fails; without the AWS SDK every cache call fails;
     * without arco-laravel 0.6 the named disks are missing and failed jobs are lost. The deploy would
     * still report success, since the health check touches none of them.
     *
     * @throws DeployException deploy.needs_packages
     */
    private static function checkPackages(AppConfig $config, Package $package): void
    {
        $features = PackageRequirements::forConfig($config);
        if ($features === []) {
            return;
        }
        $zip = new \ZipArchive();
        if ($zip->open($package->path, \ZipArchive::RDONLY) !== true) {
            return; // the upload reports an unreadable package
        }
        /** @var array<string, list<string>> $missing requirement => features that need it */
        $missing = [];
        foreach ($features as $feature => $requirements) {
            foreach ($requirements as [$requirement, $file, $needle]) {
                $file = "app/$file";
                $found = $needle === null ? $zip->locateName($file) !== false : str_contains((string) $zip->getFromName($file), $needle);
                if (! $found) {
                    $missing[$requirement][] = $feature;
                }
            }
        }
        $zip->close();
        if (isset($missing[PackageRequirements::ARCO_LARAVEL_05], $missing[PackageRequirements::ARCO_LARAVEL_06])) {
            // 0.6 brings what 0.5 did: one requirement for both.
            $missing[PackageRequirements::ARCO_LARAVEL_06] = [...$missing[PackageRequirements::ARCO_LARAVEL_05], ...$missing[PackageRequirements::ARCO_LARAVEL_06]];
            unset($missing[PackageRequirements::ARCO_LARAVEL_05]);
        }
        if ($missing === []) {
            return;
        }
        $list = [];
        foreach ($missing as $requirement => $needs) {
            $list[] = "- $requirement (" . implode(', ', $needs) . ')';
        }
        throw DeployException::make('deploy.needs_packages', ['packages' => implode(' ', array_keys($missing)), 'list' => implode("\n", $list)]);
    }

    /** @param callable(string, array<string, string|int>): void $onStep */
    public function deploy(AppConfig $config, FrameworkPreset $preset, string $projectDir, Deployer $deployer, Verifier $verifier, callable $onStep, ?CommandPlan $plan = null): DeployOutcome
    {
        $removeConsole = $this->checkConsole($config, $deployer, $plan);
        [$build, $package] = $this->package($config, $preset, $projectDir, $onStep);
        try {
            return $this->deployBuilt($config, $preset, $package, $deployer, $verifier, $onStep, $plan, $removeConsole);
        } finally {
            $build->cleanup();
        }
    }

    /**
     * Deploys a package that is already built (by deploy() or by `arco package`): upload, revision and
     * console version, deploy commands (on the console version just published), traffic, verification
     * with rollback, then custom domains. Variables come from $config.
     *
     * @param callable(string, array<string, string|int>): void $onStep
     * @param CommandPlan|null $plan deploy commands; a failing one stops the deploy before traffic moves
     * @throws DeployCommandFailed at the first deploy command that fails or crashes
     */
    public function deployPackage(AppConfig $config, FrameworkPreset $preset, Package $package, Deployer $deployer, Verifier $verifier, callable $onStep, ?CommandPlan $plan = null): DeployOutcome
    {
        $removeConsole = $this->checkConsole($config, $deployer, $plan);
        return $this->deployBuilt($config, $preset, $package, $deployer, $verifier, $onStep, $plan, $removeConsole);
    }

    /**
     * Runs before anything is built or changed. An environment whose console is off (not set, or
     * console(false), and no scheduler) cannot run deploy commands. An existing console function with
     * no console setting at all is an app deployed by an Arco that always deployed it: the deploy
     * stops, before any change, so the user says whether to keep it. With console(false) it is deleted
     * once the new revision is verified.
     *
     * @return bool whether this deploy removes the existing console function
     * @throws DeployException deploy.commands_need_console, deploy.console_setting_missing
     */
    private function checkConsole(AppConfig $config, Deployer $deployer, ?CommandPlan $plan): bool
    {
        if ($config->consoleEnabled()) {
            return false;
        }
        if ($plan !== null && $plan->commands !== []) {
            throw DeployException::make('deploy.commands_need_console', ['environment' => $config->environment]);
        }
        if (! $deployer->consoleExists($config)) {
            return false;
        }
        if ($config->console === null) {
            throw DeployException::make('deploy.console_setting_missing', ['environment' => $config->environment]);
        }
        return true;
    }

    /**
     * @param callable(string, array<string, string|int>): void $onStep
     * @param bool $removeConsole delete the console function once the deploy is verified (checkConsole())
     * @throws DeployCommandFailed at the first deploy command that fails or crashes
     */
    private function deployBuilt(AppConfig $config, FrameworkPreset $preset, Package $package, Deployer $deployer, Verifier $verifier, callable $onStep, ?CommandPlan $plan, bool $removeConsole): DeployOutcome
    {
        $variables = self::variables($config, $preset);
        foreach (array_keys(self::injectedVariables($config)) as $key) {
            // ARCO_* values are Arco's own wiring: a user value for them is not an override worth a note.
            if (! str_starts_with($key, 'ARCO_') && array_key_exists($key, $config->variables)) {
                $onStep('deploy.variable_overridden', ['key' => $key]);
            }
        }
        // Before any AWS change: workers need arco:work, Laravel's SQS, S3 and DynamoDB drivers need their packages.
        self::checkPackages($config, $package);
        // The console zip and the binary are derived from the web zip in a scratch directory.
        $work = sys_get_temp_dir() . '/arco-console-' . bin2hex(random_bytes(4));
        try {
            // The queue workers run the console zip too: it is built when queues exist even if the console is off.
            $console = $config->consoleEnabled() || $config->queues !== [] ? $this->consoleArtifacts($package, $work, $plan, $onStep) : null;
            if ($console === null && $config->queues !== []) {
                // Before any AWS change: without the zip there are no workers, and the queues would never be read.
                throw DeployException::make('deploy.workers_package_too_large', ['max' => (int) round(ConsoleFunction::MAX_UNZIPPED_BYTES / 1048576)]);
            }

            // Queues exist before any code that could use them; a failure stops the deploy before traffic moves.
            $deployer->deployResources($config, $onStep(...));

            $since = (int) floor(microtime(true) * 1000) - 5000;
            $prepared = $deployer->prepare($config, $package, $console, $variables, $onStep);
            $ran = $plan === null ? [] : $this->runCommands($config, $prepared, $plan, $onStep);
            $result = $deployer->release($config, $prepared, $onStep);
            try {
                $onStep('deploy.verifying', ['path' => $preset->healthPath()]);
                $verification = $verifier->verify($config, $result, $preset->healthPath(), $since, $onStep);
            } catch (Throwable $e) {
                // Traffic already moved to an unverified revision: put it back before failing. The
                // console's live alias has not moved yet, so it needs no rollback.
                if ($result->previousRevisionId !== null) {
                    $onStep('deploy.rolling_back', ['revision' => $result->previousRevisionId]);
                    $deployer->rollback($config, $result->previousRevisionId);
                }
                throw $e;
            }

            $rolledBack = false;
            if (! $verification->ok && $result->previousRevisionId !== null) {
                $onStep('deploy.rolling_back', ['revision' => $result->previousRevisionId]);
                $deployer->rollback($config, $result->previousRevisionId);
                $rolledBack = true;
            }
            // `arco command` and tinker follow the live alias: it moves only to a version whose web revision works.
            $consoleActivated = false;
            if ($verification->ok && $result->consoleVersion !== null) {
                try {
                    $deployer->activateConsole($config, $result->consoleVersion);
                    $consoleActivated = true;
                } catch (ConsoleRetriesNotDisabled $e) {
                    $consoleActivated = true;
                    // The alias did move; only Lambda's async retries stay on.
                    $onStep('deploy.console_retries_not_disabled', [
                        'function' => $config->consoleFunctionName(),
                        'version' => $result->consoleVersion,
                        'error' => self::errorMessage($e),
                    ]);
                } catch (Throwable $e) {
                    // Traffic already serves the verified revision: a refused alias move (often a missing
                    // lambda:UpdateAlias during an IAM upgrade) is a warning, not a failed deploy.
                    $onStep('deploy.console_not_activated', [
                        'revision' => $result->revisionId,
                        'function' => $config->consoleFunctionName(),
                        'version' => $result->consoleVersion,
                        'error' => self::errorMessage($e),
                    ]);
                }
            }
            if ($verification->ok && $config->consoleEnabled()) {
                $this->syncScheduler($config, $deployer, $consoleActivated, $onStep);
            }
            // Workers follow the same rule as the console: their aliases and mappings move only to a version whose web revision works.
            if ($verification->ok) {
                try {
                    $deployer->syncWorkers($config, $result->workerVersions, $onStep(...));
                } catch (Throwable $e) {
                    // Per-queue failures are deploy.worker_failed inside syncWorkers(); this is anything else
                    // (most often removing the workers of queues taken out of arco.php).
                    $onStep('deploy.workers_sync_failed', ['error' => self::errorMessage($e)]);
                }
            }
            // console(false): the old console goes only once the new revision serves and is verified.
            if ($verification->ok && $removeConsole) {
                try {
                    $deployer->removeConsole($config, $onStep(...));
                } catch (Throwable $e) {
                    // The verified revision is already live: a refused delete (often a missing
                    // lambda:DeleteFunction) is a warning, and domains, prune and the rest still run.
                    $onStep('deploy.console_remove_failed', [
                        'function' => $config->consoleFunctionName(),
                        'error' => self::errorMessage($e),
                    ]);
                }
            }
            if ($verification->ok) {
                try {
                    $deployer->applyLogRetention($config, $onStep(...));
                } catch (Throwable $e) {
                    // The verified revision is already live: a refused log group call is a warning.
                    $onStep('deploy.log_retention_failed', ['error' => self::errorMessage($e)]);
                }
            }
            // Custom domains only once the endpoint is known to work; the origin is the endpoint domain.
            if ($verification->ok && $config->domains !== []) {
                $result = $deployer->attachDomains($config, $result, $preset instanceof CachesPaths ? $preset->cachedPaths() : [], $onStep);
            }
            if ($verification->ok) {
                try {
                    $verification = $verifier->coldStart($config, $result, $since, $verification, $onStep);
                } catch (Throwable) {
                    // The deploy is done and verified; a missing cold start is only shown as unknown.
                }
            }
            // Old console versions and packages go only once this deploy is verified and live points at it.
            if ($verification->ok) {
                $deployer->prune($config, $prepared, $onStep);
            }
            return new DeployOutcome($result, $verification, $rolledBack, $package->bytes, $ran);
        } finally {
            (new Filesystem())->remove($work);
        }
    }

    /**
     * Points the scheduler rule at the live alias (or deletes it when the scheduler is off), once the
     * deploy is verified. With a scheduler but no alias moved by this deploy (refused, or no console
     * version), the rule is left alone: the alias may not match the live revision, or not exist yet.
     * A failure is a warning: the verified revision is already live.
     *
     * @param callable(string, array<string, string|int>): void $onStep
     */
    private function syncScheduler(AppConfig $config, Deployer $deployer, bool $consoleActivated, callable $onStep): void
    {
        if ($config->scheduler !== null && ! $consoleActivated) {
            $onStep('deploy.scheduler_skipped', ['function' => $config->consoleFunctionName(), 'every' => $config->scheduler]);
            return;
        }
        try {
            $deployer->syncScheduler($config, $onStep(...));
        } catch (Throwable $e) {
            $onStep('deploy.scheduler_failed', ['rule' => SchedulerRule::name($config), 'error' => self::errorMessage($e)]);
        }
    }

    /**
     * The console zip and its runtime binary for this deploy, or null when the console is over
     * Lambda's size limit (a warning step; deploy commands then stop the deploy).
     *
     * @param callable(string, array<string, string|int>): void $onStep
     */
    private function consoleArtifacts(Package $package, string $work, ?CommandPlan $plan, callable $onStep): ?ConsoleArtifacts
    {
        // The console runs the runtime build's PHP CLI (bin/php in its zip) when the build has one;
        // otherwise (custom binary, unknown or older build, CLI unreachable) it downloads FrankenPHP
        // at cold start. $note holds the reason (and its params) for that fallback.
        $buildId = $package->manifest?->runtimeBuild;
        $build = $buildId === null ? null : $this->binaries->build($buildId);
        $note = match (true) {
            $buildId === null => ['reason' => 'custom_binary'],
            $build === null => ['reason' => 'unknown_build', 'build' => $buildId],
            default => ['reason' => 'no_php_cli'],
        };
        $phpCli = null;
        if ($build !== null) {
            try {
                $phpCli = $this->binaries->phpCli($build, self::downloadProgress($build, $onStep));
            } catch (BinaryException $e) {
                // Only reachability falls back (network, proxy, GitHub outage): a size or checksum
                // mismatch or an unwritable cache stops the deploy, still before any AWS change.
                if ($e->key !== 'binary.download_failed') {
                    throw $e;
                }
                $note = ['reason' => 'php_cli_unavailable', 'url' => (string) ($e->params['url'] ?? ''), 'message' => (string) ($e->params['message'] ?? '')];
            }
        }
        $console = ConsolePackager::fromWeb($package, $work, $phpCli);
        if ($phpCli !== null && $console->package->bytes > ConsoleFunction::MAX_UNZIPPED_BYTES) {
            // bin/php alone can push the console over Lambda's limit: without it, the console still
            // deploys and downloads FrankenPHP at cold start.
            $console = ConsolePackager::fromWeb($package, $work, null);
            $note = ['reason' => 'php_cli_too_large'];
        }
        if ($console->package->bytes > ConsoleFunction::MAX_UNZIPPED_BYTES) {
            $params = [
                'size' => (int) round($console->package->bytes / 1048576),
                'max' => (int) round(ConsoleFunction::MAX_UNZIPPED_BYTES / 1048576),
            ];
            // Before any AWS change: deploy commands need the console version of this deploy.
            if ($plan !== null && $plan->commands !== []) {
                throw DeployException::make('deploy.commands_package_too_large', $params);
            }
            $onStep('deploy.console_skipped_size', $params);
            $console = null;
        }
        if ($console?->download !== null) {
            $onStep('deploy.console_downloads_binary', $note);
        }
        return $console;
    }

    /**
     * Reports a PHP CLI download (only called when the CLI is not cached yet): one step naming it, then
     * each whole percent once as package.progress, which the step printer draws as a progress bar.
     *
     * @param callable(string, array<string, string|int>): void $onStep
     * @return callable(int, int): void
     */
    private static function downloadProgress(RuntimeBuild $build, callable $onStep): callable
    {
        $last = null;
        return static function (int $downloaded, int $total) use ($build, $onStep, &$last): void {
            if ($total <= 0) {
                return;
            }
            if ($last === null) {
                $onStep('deploy.php_cli_downloading', ['build' => $build->id, 'size' => (int) round($total / 1048576)]);
            }
            $percent = (int) floor(min($downloaded, $total) * 100 / $total);
            if ($percent !== $last) {
                $last = $percent;
                $onStep('package.progress', ['percent' => $percent]);
            }
        };
    }

    private static function errorMessage(Throwable $e): string
    {
        $message = $e instanceof AwsException ? $e->getAwsErrorMessage() : null;
        return is_string($message) && $message !== '' ? $message : $e->getMessage();
    }

    /**
     * Runs the deploy commands one by one on the console version just published (synchronous
     * invokes), before any traffic moves and before the live alias points at that version.
     *
     * @param callable(string, array<string, string|int>): void $onStep
     * @return list<string> the commands that ran
     * @throws DeployCommandFailed at the first command that fails, crashes or cannot be invoked
     */
    private function runCommands(AppConfig $config, PreparedDeploy $prepared, CommandPlan $plan, callable $onStep): array
    {
        if ($plan->commands === []) {
            return [];
        }
        $version = $prepared->consoleVersion ?? throw DeployException::make('deploy.commands_without_console');
        $ran = [];
        foreach ($plan->commands as $command) {
            $onStep('deploy.command', ['command' => $command]);
            $ran[] = $command;
            try {
                $result = $plan->console->run($config, $command, null, $version);
            } catch (Throwable $e) {
                // The invoke failed (throttling, network, HTTP timeout): the command may have partly run.
                throw new DeployCommandFailed($command, null, $ran, $e);
            }
            ($plan->onResult)($command, $result);
            if (! $result->succeeded()) {
                throw new DeployCommandFailed($command, $result, $ran);
            }
        }
        return $ran;
    }
}
