<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\BuildResult;
use Multiapi\Arco\Build\CachesPaths;
use Multiapi\Arco\Build\FrameworkPreset;
use Multiapi\Arco\Build\ProcessCommandRunner;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Package\Package;
use Multiapi\Arco\Package\Packager;
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
    public function deploy(AppConfig $config, FrameworkPreset $preset, string $projectDir, Deployer $deployer, Verifier $verifier, callable $onStep): DeployOutcome
    {
        [$build, $package] = $this->package($config, $preset, $projectDir, $onStep);
        try {
            return $this->deployPackage($config, $preset, $package, $deployer, $verifier, $onStep);
        } finally {
            $build->cleanup();
        }
    }

    /**
     * Deploys a package that is already built (by deploy() or by `arco package`): upload, revision,
     * traffic, verification with rollback, then custom domains. Variables come from $config.
     *
     * @param callable(string, array<string, string|int>): void $onStep
     */
    public function deployPackage(AppConfig $config, FrameworkPreset $preset, Package $package, Deployer $deployer, Verifier $verifier, callable $onStep): DeployOutcome
    {
        $variables = self::variables($config, $preset);
        $since = (int) floor(microtime(true) * 1000) - 5000;
        $result = $deployer->deploy($config, $package, $variables, $onStep);
        try {
            $onStep('deploy.verifying', ['path' => $preset->healthPath()]);
            $verification = $verifier->verify($config, $result, $preset->healthPath(), $since, $onStep);
        } catch (Throwable $e) {
            // Traffic already moved to an unverified revision: put it back before failing.
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
        return new DeployOutcome($result, $verification, $rolledBack, $package->bytes);
    }
}
