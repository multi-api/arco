<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Closure;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Package\ConsoleArtifacts;
use Multiapi\Arco\Package\Package;

/**
 * A Deployer that records whether a call that may change AWS has started and whether a rollback
 * finished, so `deploy --json` can tell a refusal (nothing changed) from a failure. Reads and the
 * ownership check are not changes; every other call counts from its start, even if it then fails
 * before changing anything.
 */
final class ChangeTrackingDeployer implements Deployer
{
    private bool $changed = false;
    private bool $rolledBack = false;

    public function __construct(private readonly Deployer $inner)
    {
    }

    public function changed(): bool
    {
        return $this->changed;
    }

    /** Whether traffic was sent back to the previous revision. */
    public function rolledBack(): bool
    {
        return $this->rolledBack;
    }

    private function change(): void
    {
        $this->changed = true;
    }

    public function checkOwnership(AppConfig $config, Closure $onStep): void
    {
        $this->inner->checkOwnership($config, $onStep);
    }

    public function prepare(AppConfig $config, Package $package, ?ConsoleArtifacts $console, array $variables, ?callable $onStep = null): PreparedDeploy
    {
        $this->change();
        return $this->inner->prepare($config, $package, $console, $variables, $onStep);
    }

    public function release(AppConfig $config, PreparedDeploy $prepared, ?callable $onStep = null): DeployResult
    {
        $this->change();
        return $this->inner->release($config, $prepared, $onStep);
    }

    public function activateConsole(AppConfig $config, string $version): void
    {
        $this->change();
        $this->inner->activateConsole($config, $version);
    }

    public function prune(AppConfig $config, PreparedDeploy $prepared, ?callable $onStep = null): void
    {
        $this->change();
        $this->inner->prune($config, $prepared, $onStep);
    }

    public function rollback(AppConfig $config, string $revisionId, ?string $consoleVersion = null, array $workerVersions = []): void
    {
        $this->change();
        $this->inner->rollback($config, $revisionId, $consoleVersion, $workerVersions);
        $this->rolledBack = true;
    }

    public function syncWorkers(AppConfig $config, array $workerVersions, Closure $onStep): void
    {
        $this->change();
        $this->inner->syncWorkers($config, $workerVersions, $onStep);
    }

    public function attachDomains(AppConfig $config, DeployResult $result, array $cachedPaths, ?callable $onStep = null): DeployResult
    {
        $this->change();
        return $this->inner->attachDomains($config, $result, $cachedPaths, $onStep);
    }

    public function hasDomainResources(AppConfig $config): bool
    {
        return $this->inner->hasDomainResources($config);
    }

    public function remove(AppConfig $config, ?callable $onStep = null): void
    {
        $this->change();
        $this->inner->remove($config, $onStep);
    }

    public function describe(AppConfig $config): ?AppStatus
    {
        return $this->inner->describe($config);
    }

    public function consoleExists(AppConfig $config): bool
    {
        return $this->inner->consoleExists($config);
    }

    public function removeConsole(AppConfig $config, Closure $onStep): void
    {
        $this->change();
        $this->inner->removeConsole($config, $onStep);
    }

    public function removeScheduler(AppConfig $config, Closure $onStep): void
    {
        $this->change();
        $this->inner->removeScheduler($config, $onStep);
    }

    public function syncScheduler(AppConfig $config, Closure $onStep): void
    {
        $this->change();
        $this->inner->syncScheduler($config, $onStep);
    }

    public function applyLogRetention(AppConfig $config, Closure $onStep): void
    {
        $this->change();
        $this->inner->applyLogRetention($config, $onStep);
    }

    public function deployResources(AppConfig $config, Closure $onStep): void
    {
        $this->change();
        $this->inner->deployResources($config, $onStep);
    }

    public function syncGroup(AppConfig $config, Closure $onStep): void
    {
        $this->change();
        $this->inner->syncGroup($config, $onStep);
    }

    public function resources(AppConfig $config): array
    {
        return $this->inner->resources($config);
    }
}
