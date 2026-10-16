<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\BuildResult;
use Multiapi\Arco\Build\CachesPaths;
use Multiapi\Arco\Build\FrameworkPreset;
use Multiapi\Arco\Build\ProcessCommandRunner;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Package\ConsolePackager;
use Multiapi\Arco\Package\Package;
use Multiapi\Arco\Package\Packager;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

final class DeployPipeline
{
    public function __construct(
        private readonly Builder $builder,
        private readonly BinaryProvider $binaries,
        private readonly Packager $packager,
    ) {
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
            ...$config->variables,
            'ARCO' => '1',
            'ARCO_APP' => $config->app,
            'ARCO_ENV' => $config->environment,
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

    /** @param callable(string, array<string, string|int>): void $onStep */
    public function deploy(AppConfig $config, FrameworkPreset $preset, string $projectDir, Deployer $deployer, Verifier $verifier, callable $onStep, ?CommandPlan $plan = null): DeployOutcome
    {
        [$build, $package] = $this->package($config, $preset, $projectDir, $onStep);
        try {
            return $this->deployPackage($config, $preset, $package, $deployer, $verifier, $onStep, $plan);
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
        $variables = self::variables($config, $preset);
        // The console zip and the binary are derived from the web zip in a scratch directory.
        $work = sys_get_temp_dir() . '/arco-console-' . bin2hex(random_bytes(4));
        try {
            $console = ConsolePackager::fromWeb($package, $work);
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
            if ($verification->ok && $result->consoleVersion !== null) {
                try {
                    $deployer->activateConsole($config, $result->consoleVersion);
                } catch (ConsoleRetriesNotDisabled $e) {
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
