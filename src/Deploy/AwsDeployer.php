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
use UnexpectedValueException;

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
     * @param (Closure(EndpointPlan): EndpointPlan)|null $endpointDecider answers a gateway endpoint plan only the user can
     *        decide (EndpointPlan::DECIDE): withAssociate() or withSkip(), or throws deploy.endpoint_cancelled; null
     *        (no terminal, --yes, --json) fails the deploy with deploy.endpoint_routes_missing instead
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
        private readonly ?Closure $endpointDecider = null,
    ) {
    }

    /** @var array<string, true> names whose ownership this deployer already checked (checkOwnership()) */
    private array $verified = [];

    /** @var array<string, ?string> per web function, the connector version its live revision pins (read by checkOwnership()) */
    private array $liveConnectors = [];

    /** @var array<string, list<EndpointPlan>> per web function, the decided gateway endpoint plans of vpc() (made by checkOwnership()) */
    private array $endpointPlans = [];

    /**
     * @var array<string, array{arn: ?string, ids: list<string>}> per web function, its ARN (null while it does not exist or
     *      its tags were unreadable: no record is written) and the Arco gateway endpoints its record tag lists (read by checkOwnership())
     */
    private array $endpointRecords = [];

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
        $resources[] = $web = $guard->webFunction($names[] = $config->functionName(), self::prefix($config));
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
        // Refusals first: nothing more is read of a resource that is not this environment's.
        $guard->check($config, array_values(array_filter($resources)), $this->force);
        // prepare() needs the connector the live revision pins: the one to keep with vpc(), or a leftover
        // to remove without it. A lambda-web read; lambda-core is only asked with vpc() or once a revision pins one.
        $live = null;
        if ($web !== null && RevisionFactory::servesNetworkConnectors()) {
            $live = $this->liveConnectors[$config->functionName()] = (new WebFunctions($this->aws->lambdaWeb(), $this->sleeper))->liveConnectorArn($config->functionName());
        }
        if (RevisionFactory::usesVpc($config)) {
            // Refused even with --force: lambda-core cannot tag a connector, so it could never be taken over.
            $this->networkConnectors()->find($config);
        }
        if (RevisionFactory::usesVpc($config) || $live !== null) {
            // prepare() rewrites the operator role's policy with vpc(); prune() deletes it with a leftover connector.
            $resources[] = $guard->role($names[] = NetworkConnectors::operatorRoleName($config));
        }
        if (RevisionFactory::servesNetworkConnectors()) {
            // The record comes with the tags just read: no call. Behind an emulator there are no endpoints to use or release.
            // Tags that could not be read (--force): no ARN, so this deploy writes no record rather than drop the ids it could not see.
            $this->endpointRecords[$config->functionName()] = ['arn' => $web?->unreadable === null ? $web?->arn : null, 'ids' => self::endpointRecord($web->tags ?? [])];
            if (RevisionFactory::usesVpc($config) && $config->vpc?->endpoints !== []) {
                // Read-only, and asked (or refused) here: cancelling or failing in CI leaves AWS untouched.
                $this->endpointPlans[$config->functionName()] = $this->decideEndpoints($config);
            }
        }
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

    public function checkAdoptions(AppConfig $config, Closure $onStep): void
    {
        if (! self::adopts($config)) {
            return;
        }
        $name = ResourcesStack::name($config);
        $stacks = new Stacks($this->aws->cloudFormation(), $this->sleeper);
        $stack = $stacks->describe($name);
        if ($stack !== null && ! self::ownsStack($stack, $config)) {
            // checkStacks() refuses it (stack.not_ours), as deployResources() does.
            return;
        }
        $imports = $this->adoptions($config, $stack === null ? [] : $this->ownedResources($name));
        if ($imports !== []) {
            // The import needs the running template: refused here, before any change, when it cannot be read.
            self::deployedTemplate($stacks, $name, $stack);
        }
    }

    /**
     * The template the stack runs, kept unchanged by an import; null for a stack to create (none, or an empty
     * one deleted first: its creation rolled back, or an interrupted import left it in REVIEW_IN_PROGRESS).
     *
     * @param array{status: string}|null $stack
     * @return array<string, mixed>|null
     * @throws DeployException deploy.import_failed when the template cannot be read
     */
    private static function deployedTemplate(Stacks $stacks, string $name, ?array $stack): ?array
    {
        if ($stack === null || in_array($stack['status'], Stacks::EMPTY, true)) {
            return null;
        }
        return $stacks->template($name) ?? throw DeployException::make('deploy.import_failed', ['stack' => $name, 'reason' => 'cloudformation:GetTemplate']);
    }

    /** Whether a queue, bucket or table() table Arco creates comes from a config layer entry with `adopt: true`. */
    private static function adopts(AppConfig $config): bool
    {
        foreach ([...ResourcesStack::createdQueues($config), ...ResourcesStack::createdStorage($config), ...ResourcesStack::createdGenericTables($config)] as $resource) {
            if ($resource->adopt) {
                return true;
            }
        }
        return false;
    }

    /**
     * The resources of the config layer's `adopt` entries to import into the resources stack: not owned by it,
     * existing under their name, and tagged for this app and environment (arco:resource, when present, as
     * ResourcesStack writes it). A queue and its dead-letter queue are checked together. Read-only; an entry
     * whose resource exists but is not provably this environment's (other tags, or tags that cannot be
     * read) throws the error a deploy without `adopt` throws. A free name is created as usual.
     *
     * @param array<string, array{type: string, id: string}> $owned
     * @return list<array{ResourceType: string, LogicalResourceId: string, ResourceIdentifier: array<string, string>}>
     * @throws DeployException deploy.queue_retained, deploy.queue_dlq_retained, deploy.storage_retained,
     *         deploy.bucket_taken, deploy.generic_table_retained, deploy.lookup_denied
     */
    private function adoptions(AppConfig $config, array $owned): array
    {
        $import = [];
        $add = static function (string $type, string $id, array $identifier) use (&$import): void {
            /** @var array<string, string> $identifier */
            $import[] = ['ResourceType' => $type, 'LogicalResourceId' => $id, 'ResourceIdentifier' => $identifier];
        };
        $queues = new Queues($this->aws->sqs(), $this->aws->lambda());
        foreach (ResourcesStack::createdQueues($config) as $queue) {
            if (! $queue->adopt) {
                continue;
            }
            $id = ResourcesStack::logicalId($queue->name);
            if (! isset($owned[$id])) {
                $url = $queues->url($queue->physicalName($config));
                if ($url !== null) {
                    if (! self::provablyOurs($config, "queue:{$queue->name}", static fn () => $queues->tags($url))) {
                        throw DeployException::make('deploy.queue_retained', ['queue' => $queue->name, 'existing' => "queue('{$queue->name}', existing: '" . self::queueArn($url) . "')"]);
                    }
                    $add('AWS::SQS::Queue', $id, ['QueueUrl' => $url]);
                }
            }
            $dlq = $queue->dlqName($config);
            $dlqId = ResourcesStack::dlqLogicalId($queue->name);
            if ($dlq !== null && ! isset($owned[$dlqId])) {
                $url = $queues->url($dlq);
                if ($url !== null) {
                    if (! self::provablyOurs($config, "queue:{$queue->name}:dlq", static fn () => $queues->tags($url))) {
                        throw DeployException::make('deploy.queue_dlq_retained', ['queue' => $queue->name, 'dlq' => $dlq]);
                    }
                    $add('AWS::SQS::Queue', $dlqId, ['QueueUrl' => $url]);
                }
            }
        }
        $buckets = new Buckets($this->aws->s3());
        foreach (ResourcesStack::createdStorage($config) as $bucket) {
            $id = ResourcesStack::bucketLogicalId($bucket->name);
            if (! $bucket->adopt || isset($owned[$id])) {
                continue;
            }
            $bucketName = $bucket->bucketName($config);
            $holder = $buckets->exists($bucketName);
            if ($holder === 'taken') {
                throw DeployException::make('deploy.bucket_taken', ['bucket' => $bucketName]);
            }
            if ($holder === 'ours') {
                if (! self::provablyOurs($config, "storage:{$bucket->name}", static fn () => $buckets->tags($bucketName))) {
                    throw DeployException::make('deploy.storage_retained', ['storage' => $bucket->name, 'existing' => "storage('{$bucket->name}', existing: '{$bucketName}')"]);
                }
                $add('AWS::S3::Bucket', $id, ['BucketName' => $bucketName]);
            }
        }
        $tables = new Tables($this->aws->dynamoDb());
        foreach (ResourcesStack::createdGenericTables($config) as $table) {
            $id = ResourcesStack::genericTableLogicalId($table->name);
            if (! $table->adopt || isset($owned[$id])) {
                continue;
            }
            $tableName = $table->tableName($config);
            if ($tables->exists($tableName)) {
                if (! self::provablyOurs($config, "table:{$table->name}", static fn () => $tables->tags($tableName))) {
                    throw DeployException::make('deploy.generic_table_retained', ['kind' => 'table', 'name' => $table->name, 'table' => $tableName, 'existing' => "table('{$table->name}', existing: '{$tableName}')"]);
                }
                $add('AWS::DynamoDB::Table', $id, ['TableName' => $tableName]);
            }
        }
        return $import;
    }

    /**
     * Whether a resource's tags say it is this environment's $resource: arco:app and arco:environment equal,
     * arco:resource equal when present. Tags that cannot be read (an AWS error, an answer without what
     * the read needs) prove nothing.
     *
     * @param Closure(): array<string, string> $read
     */
    private static function provablyOurs(AppConfig $config, string $resource, Closure $read): bool
    {
        try {
            $tags = $read();
        } catch (AwsException|UnexpectedValueException) {
            return false;
        }
        return ($tags['arco:app'] ?? null) === $config->app
            && ($tags['arco:environment'] ?? null) === $config->environment
            && ($tags['arco:resource'] ?? $resource) === $resource;
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
        $codeBucket = new CodeBucket($this->aws->s3(), $this->sleeper);

        $step('deploy.step_bucket', ['bucket' => $bucket]);
        $codeBucket->ensure($bucket, $region, $account);

        $key = self::prefix($config) . "{$package->hash}.zip";
        $step('deploy.step_upload', self::sizeParams(self::fileBytes($package)));
        $versionId = $codeBucket->upload($bucket, $key, $package->path, $step, self::uploadProgress($step));

        $tags = self::tags($config);
        $liveConnector = $this->liveConnectors[$function] ?? null;
        // vpc() left arco.php (or a removal deploy failed its verification): this deploy takes everything out of
        // the VPC, whoever owns the connector. prune() removes Arco's connector once the new revision is verified.
        $outOfVpc = $liveConnector !== null && $config->vpc === null;
        $leftover = $outOfVpc && $this->ownConnector($config, $liveConnector, 'deploy.', $step);
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
                array_map(fn (GenericTableConfig $table) => $table->arn($config), $config->tables),
                // Leaving the VPC keeps the ENI statements: the automatic rollback may bring back a VPC console or worker.
                RevisionFactory::usesVpc($config) || $outOfVpc);
            ['arn' => $roleArn, 'created' => $roleCreated] = (new ExecutionRole($this->aws->iam()))->ensure(ExecutionRole::roleName($config), $policy, $tags, $region, $step, $config->permissionsBoundary);
        }

        // After the execution role, before the connector and the revision: the new revision's first cold start already reaches S3.
        ['keep' => $keepEndpoints, 'release' => $releaseEndpoints, 'record' => $endpointRecord] = $this->applyEndpoints($config, $tags, $step);
        $functionArn = $this->endpointRecords[$function]['arn'] ?? null;
        if ($endpointRecord !== null && $functionArn !== null) {
            // Right away: a deploy that fails after this point still finds the usage tags it put through the record.
            $this->writeEndpointRecord($function, $functionArn, $endpointRecord, $step);
        }

        $connectorArn = null;
        if (RevisionFactory::usesVpc($config)) {
            // Before any revision: a failed connector stops the deploy with the live revision untouched. The operator
            // role is Arco's even with role(), which only names the execution role.
            $connectorArn = $this->networkConnectors()->ensure($config, $tags, $step, $liveConnector);
        } elseif ($config->vpc !== null) {
            $step('deploy.vpc_unsupported_emulator', ['function' => $function]);
        }

        $revision = RevisionFactory::revision($config, $bucket, $key, $versionId, $roleArn, $variables, $connectorArn);
        $previous = null;
        if ($web->get($function) === null) {
            $step('deploy.step_function', ['function' => $function]);
            $created = $endpointRecord === null ? $tags : [...$tags, GatewayEndpoints::RECORD_TAG => implode(' ', $endpointRecord)];
            $revisionId = $web->create($function, $revision, RevisionFactory::initialEndpoint($config), $created, $roleCreated);
        } else {
            $endpoint = $web->endpoint($function);
            $previous = $endpoint === null ? null : WebFunctions::activeRevision($endpoint);
            $step('deploy.step_revision', ['function' => $function]);
            $revisionId = $web->createRevision($function, $revision, $roleCreated);
        }
        $web->waitRevision($function, $revisionId);

        // The ENI statements are new to the role when no live revision pinned a connector: the console and the
        // workers retry the error a not-yet-propagated permission gives, as with a role created now.
        $functionsRoleNew = $roleCreated || ($config->role === null && RevisionFactory::usesVpc($config) && $liveConnector === null);
        $consoleVersion = null;
        $previousConsole = null;
        $consoleKey = null;
        $workerVersions = [];
        if ($console !== null) {
            // The console zip also runs the queue workers: it is built (and uploaded) when queues exist even if the console is off.
            $consoleKey = self::consolePrefix($config) . "{$console->package->hash}.zip";
            // The ~180 MB console zip and the runtime binary upload under their own step, before the console's:
            // the console's Lambda update (minutes in a VPC) then gets a fresh step whose elapsed time shows.
            // Both files are one progress run; a file already in the bucket counts as sent once the other is.
            $zipBytes = self::fileBytes($console->package);
            $uploadBytes = $zipBytes + ($console->download->bytes ?? 0);
            $step('deploy.step_upload_console', self::sizeParams($uploadBytes));
            $progress = self::uploadProgress($step);
            $sentAny = false;
            $consoleObject = $codeBucket->upload($bucket, $consoleKey, $console->package->path, $step, function (int $sent) use ($progress, $uploadBytes, &$sentAny): void {
                $sentAny = true;
                $progress($sent, $uploadBytes);
            });
            if ($console->download !== null) {
                $codeBucket->uploadBinary($bucket, $console->download->key, $console->download->path, $console->download->bytes, $step, function (int $sent) use ($progress, $uploadBytes, $zipBytes, &$sentAny): void {
                    $sentAny = true;
                    $progress($zipBytes + $sent, $uploadBytes);
                });
            }
            if ($sentAny) {
                $progress($uploadBytes, $uploadBytes);
            }
            if ($config->consoleEnabled()) {
                $step('deploy.step_console', ['function' => $config->consoleFunctionName()]);
            }
            $runtimeVariables = [...$variables, ...self::binaryVariables($bucket, $console)];
            // The console and the workers are updated together (LambdaFunction::deployAll): each runs the
            // same ~180 MB zip, and Lambda takes its time over each code update.
            $requests = [];
            if ($config->consoleEnabled()) {
                $functions = $this->consoleFunction();
                $previousConsole = $functions->liveVersion($config->consoleFunctionName());
                $requests[] = $functions->request($config, $bucket, $consoleKey, $consoleObject, $roleArn, $runtimeVariables, $tags, $functionsRoleNew, $outOfVpc);
            }
            $workers = $this->workerFunctions();
            $previousWorkers = [];
            foreach ($config->queues as $queue) {
                $name = $config->workerFunctionName($queue->name);
                $step('deploy.step_worker', ['function' => $name, 'queue' => $queue->name]);
                $previousWorkers[$queue->name] = $workers->function()->liveVersion($name);
                $requests[] = $workers->request($config, $queue, $bucket, $consoleKey, $consoleObject, $roleArn, $runtimeVariables, $tags, $functionsRoleNew, $outOfVpc);
            }
            $versions = LambdaFunction::deployAll($this->sleeper, $requests);
            if ($config->consoleEnabled()) {
                $consoleVersion = $versions[$config->consoleFunctionName()];
            }
            foreach ($config->queues as $queue) {
                $workerVersions[$queue->name] = ['version' => $versions[$config->workerFunctionName($queue->name)], 'previous' => $previousWorkers[$queue->name]];
            }
        }

        return new PreparedDeploy($revisionId, $previous, $bucket, $key, $consoleVersion, $previousConsole, $consoleKey, $console?->download?->key, $workerVersions,
            $leftover, array_values(array_unique(array_filter([$liveConnector, $connectorArn], fn (?string $arn) => $arn !== null))), $outOfVpc,
            releaseEndpoints: $releaseEndpoints, keepEndpoints: $keepEndpoints);
    }

    /**
     * The gateway endpoint plans checkOwnership() makes, read-only. A plan only the user can decide goes to the decider;
     * without one the deploy stops here, before anything changed, with what to run or write in arco.php.
     *
     * @return list<EndpointPlan>
     * @throws DeployException deploy.endpoint_routes_missing, deploy.endpoint_cancelled (from the decider), and plan()'s
     */
    private function decideEndpoints(AppConfig $config): array
    {
        $plans = [];
        foreach ($this->gatewayEndpoints($config)->plan($config) as $plan) {
            if ($plan->action === EndpointPlan::DECIDE) {
                $plan = $this->endpointDecider === null ? throw DeployException::make('deploy.endpoint_routes_missing', self::routesMissing($config, $plan)) : ($this->endpointDecider)($plan);
                if ($plan->action === EndpointPlan::DECIDE) {
                    throw new \LogicException('The endpoint decider must answer with withAssociate() or withSkip().');
                }
            }
            $plans[] = $plan;
        }
        return $plans;
    }

    /**
     * deploy.endpoint_routes_missing: with a single user endpoint, the command and the arco.php line name it.
     *
     * @return array<string, string>
     */
    private static function routesMissing(AppConfig $config, EndpointPlan $plan): array
    {
        $only = count($plan->userEndpoints) === 1 ? $plan->userEndpoints[0] : null;
        return [
            'service' => $plan->service,
            'endpoints' => implode(', ', $plan->userEndpoints),
            'route_tables' => implode(', ', $plan->missing),
            'command' => $plan->awsCommand($only ?? 'vpce-...', (string) $config->region),
            'config' => ($only === null ? $plan : $plan->withAssociate($only))->configLine(),
        ];
    }

    /**
     * Carries out the plans checkOwnership() decided (create, associate, usage tags), and compares the endpoints the
     * environment relies on now with its record: the ones it no longer uses are released by prune(), after the verification.
     *
     * @param array<string, string> $tags
     * @param callable(string, array<string, string|int>): void $step
     * @return array{keep: list<string>, release: list<string>, record: list<string>|null} record: the ids the Web
     *         Function's record must list from now on (both kinds until prune() releases), null when it lists them already
     */
    private function applyEndpoints(AppConfig $config, array $tags, callable $step): array
    {
        $function = $config->functionName();
        $record = $this->endpointRecords[$function]['ids'] ?? [];
        $plans = $this->endpointPlans[$function] ?? [];
        $keep = [];
        if ($plans !== []) {
            $step('deploy.step_endpoints', ['services' => implode(', ', array_map(fn (EndpointPlan $plan) => $plan->service, $plans))]);
            $endpoints = $this->gatewayEndpoints($config);
            foreach ($plans as $plan) {
                // The user chose to go on without the endpoint (withSkip()): `missing` is what stays unserved.
                if ($plan->action === EndpointPlan::NONE && $plan->missing !== []) {
                    $step('deploy.endpoint_skipped', ['service' => $plan->service, 'route_tables' => implode(', ', $plan->missing)]);
                    continue;
                }
                $keep = [...$keep, ...$endpoints->apply($config, $plan, $tags, $step)];
            }
        }
        $keep = self::endpointIds($keep);
        $recorded = self::endpointIds([...$record, ...$keep]);
        return ['keep' => $keep, 'release' => array_values(array_diff($record, $keep)), 'record' => $recorded === $record ? null : $recorded];
    }

    /**
     * Writes the Web Function's record of the Arco gateway endpoints it uses; an empty list sets the tag to ''
     * (lambda:TagResource, the permission the other tags need), which reads as no endpoints. A failure is a warning:
     * the next deploy writes it again.
     *
     * @param list<string> $endpointIds
     * @param callable(string, array<string, string|int>): void $step
     */
    private function writeEndpointRecord(string $function, string $functionArn, array $endpointIds, callable $step): void
    {
        try {
            Domains\Aws::call($this->aws->lambdaWeb(), 'TagResource', ['resource' => $functionArn, 'tags' => [GatewayEndpoints::RECORD_TAG => implode(' ', $endpointIds)]]);
        } catch (AwsException $e) {
            $step('deploy.endpoint_record_failed', ['function' => $function, 'error' => self::awsError($e), 'permission' => 'lambda:TagResource']);
        }
    }

    /**
     * The endpoint ids the record tag lists.
     *
     * @param array<string, string> $tags the Web Function's tags
     * @return list<string> sorted
     */
    private static function endpointRecord(array $tags): array
    {
        return self::endpointIds(preg_split('/\s+/', $tags[GatewayEndpoints::RECORD_TAG] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param list<string> $ids
     * @return list<string> without duplicates, sorted
     */
    private static function endpointIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    private function gatewayEndpoints(AppConfig $config): GatewayEndpoints
    {
        return new GatewayEndpoints($this->aws->ec2(), $this->sleeper, (string) $config->region);
    }

    /** What the upload sends: the zip on disk, not Package::$bytes (the unzipped size the Lambda limits check). */
    private static function fileBytes(Package $package): int
    {
        $bytes = @filesize($package->path);
        return $bytes === false ? $package->bytes : $bytes;
    }

    /**
     * `size` (whole MB, the --json API since 0.x) and `mb` (the text the step prints).
     *
     * @return array{size: int, mb: string}
     */
    private static function sizeParams(int $bytes): array
    {
        return ['size' => (int) round($bytes / 1048576), 'mb' => UploadProgress::megabytes($bytes)];
    }

    /**
     * Turns CodeBucket's bytes sent into package.progress steps (percent, and sent/total in MB), one per
     * whole percent, like DeployPipeline::downloadProgress().
     *
     * @param callable(string, array<string, string|int>): void $step
     * @return Closure(int, int): void
     */
    private static function uploadProgress(callable $step): Closure
    {
        $last = null;
        return static function (int $sent, int $total) use ($step, &$last): void {
            if ($total <= 0) {
                return;
            }
            $percent = (int) floor(min($sent, $total) * 100 / $total);
            if ($percent === $last) {
                return;
            }
            $last = $percent;
            $step('package.progress', ['percent' => $percent, 'sent' => UploadProgress::megabytes(min($sent, $total)), 'total' => UploadProgress::megabytes($total)]);
        };
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
        if ($prepared->leftVpc) {
            // Before the next deploy drops the ENI statements from the execution role, which Lambda needs to delete them.
            $this->retireVpcVersions($config, array_map(fn (QueueConfig $queue) => $queue->name, $config->queues), $step);
        }
        if ($prepared->leftoverConnector) {
            // The new revision is verified and serves without the connector: it goes, then its operator role.
            $this->removeConnector($config, 'deploy.', $step);
        } elseif (RevisionFactory::usesVpc($config)) {
            $this->networkConnectors()->pruneVersions($config, $prepared->keepConnectorVersions, $step);
        }
        if ($prepared->releaseEndpoints !== []) {
            $this->releaseEndpoints($config, $prepared, $step);
        }
    }

    /**
     * After a verified deploy: releases the gateway endpoints the environment stopped using, then records only the ones
     * it keeps. When a release failed, the record stays as it is, so the next deploy tries again.
     *
     * @param callable(string, array<string, string|int>): void $step
     */
    private function releaseEndpoints(AppConfig $config, PreparedDeploy $prepared, callable $step): void
    {
        if (! $this->gatewayEndpoints($config)->release($config, $prepared->releaseEndpoints, $step, 'deploy.')) {
            return;
        }
        $function = $config->functionName();
        try {
            $web = (new WebFunctions($this->aws->lambdaWeb(), $this->sleeper))->get($function);
        } catch (AwsException $e) {
            $step('deploy.endpoint_record_failed', ['function' => $function, 'error' => self::awsError($e), 'permission' => 'lambda:GetWebFunction']);
            return;
        }
        if ($web !== null) {
            $this->writeEndpointRecord($function, WebFunctions::text($web['functionArn'] ?? null), $prepared->keepEndpoints, $step);
        }
    }

    /**
     * The console and worker functions with a version still in a VPC (classic VpcConfig, $LATEST included).
     *
     * @param list<string> $queues queue names
     * @return array<string, array<string, array{subnets: list<string>, groups: list<string>}>> the VPC versions by function name
     * @throws AwsException a refused or failed listing
     */
    private function vpcVersions(AppConfig $config, array $queues): array
    {
        $function = new LambdaFunction($this->aws->lambda(), $this->sleeper);
        $found = [];
        foreach ([$config->consoleFunctionName(), ...array_map(fn (string $queue) => $config->workerFunctionName($queue), $queues)] as $name) {
            $versions = $function->vpcVersions($name);
            if ($versions !== []) {
                $found[$name] = $versions;
            }
        }
        return $found;
    }

    /**
     * The subnets and security groups the environment's console and workers ran with: those of vpc(), of their
     * versions still in a VPC and of the connector (a console or worker already deleted used the same).
     *
     * @param array<string, array<string, array{subnets: list<string>, groups: list<string>}>> $versions from vpcVersions()
     * @return list<array{subnets: list<string>, groups: list<string>}> groups sorted
     */
    private function vpcNetworks(AppConfig $config, array $versions): array
    {
        $networks = [];
        $add = function (array $subnets, array $groups) use (&$networks): void {
            $subnets = array_values(array_filter($subnets, 'is_string'));
            $groups = array_values(array_filter($groups, 'is_string'));
            sort($subnets);
            sort($groups);
            if ($subnets !== []) {
                $networks[json_encode([$subnets, $groups])] = ['subnets' => $subnets, 'groups' => $groups];
            }
        };
        if ($config->vpc !== null) {
            $add($config->vpc->subnets, $config->vpc->securityGroups);
        }
        foreach ($versions as $byVersion) {
            foreach ($byVersion as $network) {
                $add($network['subnets'], $network['groups']);
            }
        }
        try {
            $connector = $this->networkConnectors()->find($config);
            $configuration = is_array($connector['Configuration'] ?? null) ? $connector['Configuration'] : [];
            $egress = $configuration['VpcEgressConfiguration'] ?? null;
            if (is_array($egress)) {
                $add(is_array($egress['SubnetIds'] ?? null) ? $egress['SubnetIds'] : [], is_array($egress['SecurityGroupIds'] ?? null) ? $egress['SecurityGroupIds'] : []);
            }
        } catch (DeployException|AwsException) {
            // A foreign or unreadable connector adds nothing: vpc() and the versions still say where they ran.
        }
        return array_values($networks);
    }

    /**
     * After a verified deploy out of the VPC: deletes the console and worker versions still in it (never the one a
     * live alias points to, nor $LATEST, which already left) and waits until Lambda has deleted their network
     * interfaces, also those of a console or worker this deploy deleted. Those versions are no rollback target any
     * more: the connector goes in the same prune. A failure or a timeout is a warning.
     *
     * @param list<string> $queues queue names
     * @param callable(string, array<string, string|int>): void $step
     */
    private function retireVpcVersions(AppConfig $config, array $queues, callable $step): void
    {
        try {
            $function = new LambdaFunction($this->aws->lambda(), $this->sleeper);
            $found = $this->vpcVersions($config, $queues);
            $networks = $this->vpcNetworks($config, $found);
            foreach ($found as $name => $versions) {
                $live = $function->liveVersion($name);
                foreach (array_keys($versions) as $version) {
                    $version = (string) $version;
                    if ($version !== '$LATEST' && $version !== $live) {
                        $function->deleteVersion($name, $version);
                    }
                }
            }
            $this->waitInterfaces($config, $networks, 'deploy.', $step);
        } catch (AwsException $e) {
            $step('deploy.vpc_interfaces_failed', ['functions' => VpcInterfaces::functions($config), 'region' => (string) $config->region, 'error' => self::awsError($e)]);
        }
    }

    /**
     * Waits until Lambda has deleted the network interfaces of the environment's console and workers. Whether they
     * are gone; one still there at the deadline is a warning ({$prefix}vpc_interfaces_pending).
     *
     * @param list<array{subnets: list<string>, groups: list<string>}> $networks from vpcNetworks()
     * @param 'deploy.'|'remove.' $prefix
     * @param callable(string, array<string, string|int>): void $step
     * @throws AwsException a refused or failed lookup
     */
    private function waitInterfaces(AppConfig $config, array $networks, string $prefix, callable $step): bool
    {
        if ($networks === []) {
            return true;
        }
        $step($prefix . 'step_vpc_interfaces', ['functions' => VpcInterfaces::functions($config)]);
        $left = (new VpcInterfaces($this->aws->ec2(), $this->aws->lambda(), $this->sleeper))->waitGone($config, $networks);
        if ($left === []) {
            return true;
        }
        $step($prefix . 'vpc_interfaces_pending', ['interfaces' => implode(' ', $left), 'region' => (string) $config->region,
            'role' => ExecutionRole::roleName($config)]);
        return false;
    }

    private function networkConnectors(): NetworkConnectors
    {
        return new NetworkConnectors($this->aws->lambdaCore(), new ExecutionRole($this->aws->iam()), $this->aws->accountId(), $this->sleeper);
    }

    /**
     * Whether the environment's connector is Arco's and, with $versionArn, whether that version is one of its own.
     * Another owner's connector with the name is a warning ({$prefix}network_foreign_kept): Arco leaves it alone.
     *
     * @param 'deploy.'|'remove.' $prefix
     * @param callable(string, array<string, string|int>): void $step
     */
    private function ownConnector(AppConfig $config, ?string $versionArn, string $prefix, callable $step): bool
    {
        try {
            $connector = $this->networkConnectors()->find($config);
        } catch (DeployException $e) {
            if ($e->key !== 'deploy.network_foreign') {
                throw $e;
            }
            $step($prefix . 'network_foreign_kept', $e->params);
            return false;
        }
        return $connector !== null && ($versionArn === null || NetworkConnectors::pins($connector, $versionArn));
    }

    /**
     * Deletes the connector (until it is gone) and its operator role. A failure is a warning with the commands
     * that finish the job by hand ({$prefix}network_cleanup_failed / remove.network_failed): nothing tries again.
     *
     * @param 'deploy.'|'remove.' $prefix
     * @param callable(string, array<string, string|int>): void $step
     */
    private function removeConnector(AppConfig $config, string $prefix, callable $step): void
    {
        $failed = fn (string $error) => $step($prefix === 'deploy.' ? 'deploy.network_cleanup_failed' : 'remove.network_failed', [
            'connector' => $config->functionName(),
            'role' => NetworkConnectors::operatorRoleName($config),
            'region' => (string) $config->region,
            'error' => $error,
        ]);
        try {
            if ($prefix === 'deploy.' && ! $this->ownConnector($config, null, $prefix, $step)) {
                return;
            }
            if ($prefix === 'remove.') {
                $step('remove.step_network', ['connector' => $config->functionName()]);
            }
            $this->networkConnectors()->delete($config, $step);
        } catch (AwsException $e) {
            $failed(trim((string) $e->getAwsErrorCode() . ' ' . (string) $e->getAwsErrorMessage()));
        } catch (DeployException $e) {
            $failed((string) ($e->params['reason'] ?? $e->params['code'] ?? $e->key));
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
        $web = $this->checkRemoval($config, $queues);
        $webExists = $web !== null;
        // Read with the ownership check, while the Web Function still carries it; released once the functions are gone.
        $endpoints = RevisionFactory::servesNetworkConnectors() ? self::endpointRecord($web->tags ?? []) : [];
        $connector = $this->connectorToRemove($config, $webExists, $step);
        // Their network interfaces must be gone before the execution role is: Lambda deletes them with it. Where they
        // ran is read now, while the functions and the connector exist; unreadable, the role is kept.
        $networks = [];
        if ($config->role === null && RevisionFactory::servesNetworkConnectors() && ($connector || RevisionFactory::usesVpc($config))) {
            try {
                $networks = $this->vpcNetworks($config, $this->vpcVersions($config, $queues));
            } catch (AwsException $e) {
                $step('remove.vpc_interfaces_failed', ['functions' => VpcInterfaces::functions($config), 'role' => ExecutionRole::roleName($config), 'region' => (string) $config->region, 'error' => self::awsError($e)]);
                $networks = null;
            }
        }
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
        // After the functions that use it; the execution role after it, like every deploy keeps them.
        if ($connector) {
            $this->removeConnector($config, 'remove.', $step);
        }
        if ($endpoints !== []) {
            $this->gatewayEndpoints($config)->release($config, $endpoints, $step, 'remove.');
        }
        if ($config->role === null && $networks !== null && $this->interfacesGoneForRemove($config, $networks, $step)) {
            (new ExecutionRole($this->aws->iam()))->delete(ExecutionRole::roleName($config));
        }
        $this->removeGroup($config, $step);
    }

    /**
     * Whether the execution role may go: the network interfaces of the console and workers remove deleted are
     * gone. Otherwise the role is kept, with a warning that names the commands for later.
     *
     * @param list<array{subnets: list<string>, groups: list<string>}> $networks from vpcNetworks()
     * @param callable(string, array<string, string|int>): void $step
     */
    private function interfacesGoneForRemove(AppConfig $config, array $networks, callable $step): bool
    {
        try {
            return $this->waitInterfaces($config, $networks, 'remove.', $step);
        } catch (AwsException $e) {
            $step('remove.vpc_interfaces_failed', ['functions' => VpcInterfaces::functions($config), 'role' => ExecutionRole::roleName($config), 'region' => (string) $config->region, 'error' => self::awsError($e)]);
            return false;
        }
    }

    /**
     * Whether remove deletes the network connector: Arco's connector exists and vpc() is set or the live revision
     * pins it. An environment that never used vpc() makes no lambda-core call; behind an emulator there is none.
     *
     * @param callable(string, array<string, string|int>): void $step
     */
    private function connectorToRemove(AppConfig $config, bool $webExists, callable $step): bool
    {
        if (! RevisionFactory::servesNetworkConnectors()) {
            return false;
        }
        if ($config->vpc !== null) {
            $remove = $this->ownConnector($config, null, 'remove.', $step);
        } else {
            $live = $webExists ? (new WebFunctions($this->aws->lambdaWeb(), $this->sleeper))->liveConnectorArn($config->functionName()) : null;
            $remove = $live !== null && $this->ownConnector($config, $live, 'remove.', $step);
        }
        if ($remove) {
            // Its operator role goes with it: refused, before anything is deleted, when another environment owns it.
            $guard = new OwnershipGuard($this->aws);
            $role = $guard->role(NetworkConnectors::operatorRoleName($config));
            $guard->checkRemoval($config, $role === null ? [] : [$role]);
        }
        return $remove;
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
     * @return NamedResource|null the web function, null when it does not exist
     * @throws DeployException remove.not_owned, remove.untagged_resource, remove.tags_unreadable
     */
    private function checkRemoval(AppConfig $config, array $queues): ?NamedResource
    {
        $guard = new OwnershipGuard($this->aws);
        $rule = $guard->schedulerRule(SchedulerRule::name($config));
        // A rule EventBridge refuses to describe is not deleted either (remove.scheduler_unreadable).
        $resources = [$rule !== null && OwnershipGuard::isUndescribed($rule) ? null : $rule];
        foreach ($queues as $queue) {
            $resources[] = $guard->lambdaFunction($config->workerFunctionName($queue), OwnershipGuard::WORKER_MARKER);
        }
        $resources[] = $guard->lambdaFunction($config->consoleFunctionName(), OwnershipGuard::CONSOLE_MARKER);
        $resources[] = $web = $guard->webFunction($config->functionName(), self::prefix($config));
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
        return $web;
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
        $vpc = null;
        if (RevisionFactory::usesVpc($config)) {
            try {
                $connector = $this->networkConnectors()->find($config);
                $vpc = $connector === null ? null : NetworkConnectors::summary($connector);
            } catch (AwsException|DeployException) {
                // Unreadable or another owner's: the rest of the status still shows.
            }
        }
        $endpoints = null;
        if (RevisionFactory::usesVpc($config)) {
            $endpoints = $config->vpc?->endpointIds() === [] ? [] : $this->endpointStatus($config);
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
            $vpc,
            $endpoints,
        );
    }

    /**
     * The gateway endpoint of each service of vpc() as info shows it (read-only: no decider, no write); null when
     * the plan cannot be read (no EC2 access, a named endpoint that is gone or wrong).
     *
     * @return list<array{service: string, id: ?string, arco: bool, environments: ?int, route_tables: list<string>, missing_route_tables: list<string>}>|null
     */
    private function endpointStatus(AppConfig $config): ?array
    {
        try {
            $plans = $this->gatewayEndpoints($config)->plan($config);
        } catch (AwsException|DeployException) {
            return null;
        }
        return array_map(function (EndpointPlan $plan): array {
            $covering = $plan->covering;
            usort($covering, fn (array $a, array $b) => $a['id'] <=> $b['id']);
            $shown = null;
            foreach ($covering as $endpoint) {
                if ($endpoint['arco']) {
                    $shown = $endpoint;
                    break;
                }
            }
            $shown ??= $covering[0] ?? null;
            $id = $shown['id'] ?? $plan->target;
            $arco = $shown !== null ? $shown['arco'] : ($id !== null && $plan->targetIsArco);
            return [
                'service' => $plan->service,
                'id' => $id,
                'arco' => $arco,
                'environments' => $arco && $shown !== null ? $shown['uses'] : null,
                'route_tables' => $plan->routeTables,
                'missing_route_tables' => $plan->missing,
            ];
        }, $plans);
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

        // A config layer entry with `adopt` imports its retained resource when it is provably this
        // environment's, and is refused like any other otherwise: checked here, still before any change.
        $imports = self::adopts($config) ? $this->adoptions($config, $owned) : [];

        // A queue, bucket or table removed earlier is retained under its name: CloudFormation would
        // fail creating it again, so the deploy stops here, before any change, and offers to reference
        // it instead. A bucket name another account holds stops it the same way.
        foreach ($created as $queue) {
            if ($queue->adopt) {
                continue;
            }
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
            if ($bucket->adopt || isset($owned[ResourcesStack::bucketLogicalId($bucket->name)])) {
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
            if (! $table->adopt && ! isset($owned[ResourcesStack::genericTableLogicalId($table->name)]) && $lookup->exists($table->tableName($config))) {
                throw DeployException::make('deploy.generic_table_retained', ['kind' => 'table', 'name' => $table->name, 'table' => $table->tableName($config), 'existing' => "table('{$table->name}', existing: '{$table->tableName($config)}')"]);
            }
        }

        $removed = array_diff_key($owned, $resources);

        $onStep('deploy.step_resources', ['stack' => $name]);
        if (! $wanted) {
            // The queues, buckets and tables stay (Retain): only the empty stack goes.
            $stacks->delete($name);
        } else {
            $adopted = [];
            if ($imports === []) {
                // What the stack runs now, to say which resources this update changes. Unknown: reported as changed.
                // An empty stack (left in review, or its creation rolled back) holds nothing and is about to be replaced.
                // [] (nothing changed) is deliberate for Stacks::EMPTY: the stack is deleted and created again.
                $changed = $stack === null || in_array($stack['status'], Stacks::EMPTY, true) ? [] : ResourcesStack::changedResources($stacks->template($name), $template);
            } else {
                // The import must keep the running template as it is: it cannot be guessed.
                $deployed = self::deployedTemplate($stacks, $name, $stack);
                $changed = $deployed === null ? [] : ResourcesStack::changedResources($deployed, $template);
                $onStep(count($imports) === 1 ? 'deploy.step_import_one' : 'deploy.step_import', ['stack' => $name, 'count' => count($imports)]);
                if ($deployed === null) {
                    // An import cannot add stack tags, and a stack without them is not provably Arco's: a new
                    // stack is created first, tagged, holding only a placeholder the update below removes.
                    $deployed = ResourcesStack::placeholderTemplate($config);
                    $stacks->deploy($name, ResourcesStack::body($deployed), self::tags($config));
                }
                $stacks->import($name, ResourcesStack::body(ResourcesStack::importTemplate($config, $deployed, array_column($imports, 'LogicalResourceId'))), $imports);
                $adopted = array_fill_keys(array_column($imports, 'LogicalResourceId'), true);
            }
            try {
                $stacks->deploy($name, ResourcesStack::body($template), self::tags($config));
            } catch (DeployException $e) {
                throw self::publicPolicyFailure($config, $e) ?? $e;
            }
            foreach ($created as $queue) {
                $id = ResourcesStack::logicalId($queue->name);
                $key = isset($adopted[$id]) ? 'deploy.queue_adopted' : (! isset($owned[$id]) ? 'deploy.queue_created' : (self::isChanged($changed, $id) ? 'deploy.queue_changed' : 'deploy.queue_updated'));
                $onStep($key, ['queue' => $queue->name, 'name' => $queue->physicalName($config)]);
                $dlq = $queue->dlqName($config);
                if ($dlq !== null && isset($adopted[ResourcesStack::dlqLogicalId($queue->name)])) {
                    $onStep('deploy.queue_dlq_adopted', ['queue' => $queue->name, 'name' => $dlq]);
                }
            }
            foreach ($storage as $bucket) {
                $id = ResourcesStack::bucketLogicalId($bucket->name);
                $key = isset($adopted[$id]) ? 'deploy.storage_adopted' : (! isset($owned[$id]) ? 'deploy.storage_created' : (self::isChanged($changed, $id) ? 'deploy.storage_changed' : 'deploy.storage_updated'));
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
                $key = isset($adopted[$id]) ? 'deploy.generic_table_adopted' : (! isset($owned[$id]) ? 'deploy.generic_table_created' : (self::isChanged($changed, $id) ? 'deploy.generic_table_changed' : 'deploy.generic_table_updated'));
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
