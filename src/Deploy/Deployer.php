<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Closure;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Package\ConsoleArtifacts;
use Multiapi\Arco\Package\Package;

interface Deployer
{
    /**
     * Before any AWS change of a deploy: reads the `arco:app`/`arco:environment` tags of every resource
     * Arco addresses by name (execution role, web function, console function, workers, scheduler rule)
     * and refuses one that carries another app's or environment's tags, or no tags and no sign that
     * Arco made it, unless the deployer was built with --force. Resources this environment owns that
     * lack the tags get them (step deploy.tags_added).
     *
     * @param Closure(string, array<string, string|int>): void $onStep
     * @throws DeployException deploy.not_owned, deploy.untagged_resource, deploy.tags_unreadable
     */
    public function checkOwnership(AppConfig $config, Closure $onStep): void;

    /**
     * Uploads the package and puts the code in place without moving traffic: execution role, a new
     * web revision, and (with $console) the console zip and runtime binary uploaded, the console
     * function (when the console is on) and one worker per queue updated with a published version.
     * The role comes first and carries the config's permissions boundary (step deploy.boundary_set when
     * it is put on an existing role, note deploy.boundary_kept when the config no longer sets the one
     * the role has); a denied role write with a boundary throws deploy.boundary_denied before any
     * function is changed.
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

    /**
     * Sends traffic back to $revisionId and, when given, the console's live alias back to $consoleVersion
     * and each worker's live alias back to its version in $workerVersions (queue name => version), so an
     * event source mapping never feeds a version that is not live.
     *
     * @param array<string, string> $workerVersions
     */
    public function rollback(AppConfig $config, string $revisionId, ?string $consoleVersion = null, array $workerVersions = []): void;

    /**
     * Once the deploy is verified: points each queue worker's live alias at the version this deploy
     * published and creates or updates its SQS event source mapping (steps deploy.worker_ready,
     * deploy.worker_mapping; warning deploy.queue_other_consumer for another consumer of an existing
     * queue). A queue whose worker fails is a warning (deploy.worker_failed), the others go on. Then
     * deletes the mapping and function of every worker whose queue left arco.php (step
     * deploy.worker_removed). Without queues and without workers left, no change.
     *
     * @param array<string, array{version: string, previous: ?string}> $workerVersions by queue name
     * @param Closure(string, array<string, string|int>): void $onStep
     */
    public function syncWorkers(AppConfig $config, array $workerVersions, Closure $onStep): void;

    /**
     * Puts the deployed endpoint behind the configured custom domains (certificate, CloudFront, DNS).
     *
     * @param list<string> $cachedPaths path patterns CloudFront may cache
     * @param (callable(string, array<string, string|int>): void)|null $onStep
     * @param string $healthPath the preset's health path, requested through CloudFront once the endpoint is locked to it
     */
    public function attachDomains(AppConfig $config, DeployResult $result, array $cachedPaths, ?callable $onStep = null, string $healthPath = '/up'): DeployResult;

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
     * when missing, and tags each for the environment (a refused tag is the note
     * deploy.log_tags_not_added, the retention is still set). Without a logRetention() setting the default (AwsDeployer::DEFAULT_LOG_RETENTION days) is applied and announced by deploy.log_retention_default, only when it changes a group (new, or with another or no retention; always when the retention cannot be read). Steps: deploy.log_retention, deploy.log_retention_one_day, deploy.log_retention_never (AwsDeployer::logRetentionStep()) or deploy.log_retention_default.
     *
     * @param Closure(string, array<string, string|int>): void $onStep
     */
    public function applyLogRetention(AppConfig $config, Closure $onStep): void;

    /**
     * After the build and before anything else changes: creates or updates the resources stack
     * (ResourcesStack) with the queues, buckets and tables Arco creates, and deletes it once none
     * remain. They are retained: one removed from arco.php, or left when the stack is deleted, stays
     * in AWS with its data (warning steps deploy.queue_kept, deploy.storage_kept, deploy.table_kept);
     * only a resource whose own creating operation rolls back is deleted, new and unused. Nothing happens, no change,
     * without such resources or a stack left from earlier ones (a stack with this name Arco did not
     * create is then left alone).
     *
     * @param Closure(string, array<string, string|int>): void $onStep
     * @throws DeployException before any change: deploy.queue_retained, deploy.storage_retained or
     *         deploy.table_retained (with an `existing:` reference to use instead) or
     *         deploy.queue_dlq_retained when a resource to create already exists outside the stack;
     *         deploy.bucket_taken when another account (or region) holds a bucket name;
     *         deploy.lookup_denied when the profile may not look a table up; deploy.queue_replaced or
     *         deploy.resource_replaced when a name would change. After the stack fails:
     *         deploy.public_blocked or deploy.public_policy_denied for a refused public bucket policy
     */
    public function deployResources(AppConfig $config, Closure $onStep): void;

    /**
     * Once the deploy is verified: creates the environment's Resource Group `arco-<app>-<env>` (a tag
     * query on arco:app and arco:environment), or updates its query when it differs (step
     * deploy.group_ready, only then: an unchanged group prints nothing). A convenience view: a refused call (deploy.group_denied), a group with the
     * name someone else made (deploy.group_not_ours) or another failure (deploy.group_failed) is a note,
     * never thrown. `remove()` deletes the group last, only when its query is this environment's.
     *
     * @param Closure(string, array<string, string|int>): void $onStep
     */
    public function syncGroup(AppConfig $config, Closure $onStep): void;

    /**
     * Every resource of the region tagged for this app and environment (tag:GetResources), sorted by
     * type and name; `retained` says whether `remove()` keeps it.
     *
     * @return list<array{type: string, name: string, arn: string, retained: bool, relations: list<array{type: string, target: string}>}>
     * @throws \Aws\Exception\AwsException a refused or failed lookup
     */
    public function resources(AppConfig $config): array;
}
