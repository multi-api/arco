<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Package\ConsoleArtifacts;
use Multiapi\Arco\Package\Package;

interface Deployer
{
    /**
     * Uploads the package and puts the code in place without moving traffic: execution role, a new
     * web revision, and (with $console) the console zip and runtime binary uploaded and the console
     * function updated with a published version.
     *
     * @param ConsoleArtifacts|null $console null leaves the console function as it is
     * @param array<string, string> $variables final environment variables
     * @param (callable(string, array<string, string|int>): void)|null $onStep
     */
    public function prepare(AppConfig $config, Package $package, ?ConsoleArtifacts $console, array $variables, ?callable $onStep = null): PreparedDeploy;

    /**
     * Moves traffic to the prepared revision. The console's live alias is left alone until the
     * revision is verified (activateConsole()), and nothing is pruned (prune()).
     *
     * @param (callable(string, array<string, string|int>): void)|null $onStep
     */
    public function release(AppConfig $config, PreparedDeploy $prepared, ?callable $onStep = null): DeployResult;

    /** Points the console's live alias at $version, once the matching web revision is verified. */
    public function activateConsole(AppConfig $config, string $version): void;

    /**
     * Best effort, once the deploy is verified: deletes old console versions and old packages. A
     * failure (e.g. missing permissions) is reported through $onStep as a warning, never thrown.
     *
     * @param (callable(string, array<string, string|int>): void)|null $onStep
     */
    public function prune(AppConfig $config, PreparedDeploy $prepared, ?callable $onStep = null): void;

    /** Sends traffic back to $revisionId and, when given, the console's live alias back to $consoleVersion. */
    public function rollback(AppConfig $config, string $revisionId, ?string $consoleVersion = null): void;

    /**
     * Puts the deployed endpoint behind the configured custom domains (certificate, CloudFront, DNS).
     *
     * @param list<string> $cachedPaths path patterns CloudFront may cache
     * @param (callable(string, array<string, string|int>): void)|null $onStep
     */
    public function attachDomains(AppConfig $config, DeployResult $result, array $cachedPaths, ?callable $onStep = null): DeployResult;

    /** Whether custom-domain resources (the CloudFront stack, a managed certificate) exist for the environment. */
    public function hasDomainResources(AppConfig $config): bool;

    /** @param (callable(string, array<string, string|int>): void)|null $onStep */
    public function remove(AppConfig $config, ?callable $onStep = null): void;

    public function describe(AppConfig $config): ?AppStatus;
}
