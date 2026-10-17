<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Closure;
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

    /** Whether the environment's console function exists in AWS. */
    public function consoleExists(AppConfig $config): bool;

    /**
     * Deletes the console function of an environment whose console is off (a missing one is fine).
     *
     * @param Closure(string, array<string, string|int>): void $onStep
     */
    public function removeConsole(AppConfig $config, Closure $onStep): void;

    /**
     * Deletes the scheduler rule and the console alias's invoke permission when the rule exists (step
     * deploy.scheduler_removed); when EventBridge refuses the lookup, warns (deploy.scheduler_unreadable)
     * and deletes nothing.
     *
     * @param Closure(string, array<string, string|int>): void $onStep
     */
    public function removeScheduler(AppConfig $config, Closure $onStep): void;

    /**
     * Once the console's live alias points at this deploy's version: with a scheduler, creates or
     * updates the EventBridge rule invoking that alias (step deploy.scheduler_set); without one,
     * deletes a rule left from before (step deploy.scheduler_removed, nothing when there was none).
     *
     * @param Closure(string, array<string, string|int>): void $onStep
     */
    public function syncScheduler(AppConfig $config, Closure $onStep): void;

    /**
     * Sets the retention of the web and (when the console is on) console log groups, creating them
     * when missing. Nothing happens, no AWS call, when logRetention() is not set. Steps: deploy.log_retention, deploy.log_retention_one_day or deploy.log_retention_never (AwsDeployer::logRetentionStep()).
     *
     * @param Closure(string, array<string, string|int>): void $onStep
     */
    public function applyLogRetention(AppConfig $config, Closure $onStep): void;
}
