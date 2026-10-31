<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigItems;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Deploy\Domains\Aws;
use Multiapi\Arco\Deploy\Domains\DomainStack;

/**
 * Every resource of the app's region carrying both `arco:app` and `arco:environment` tags of the
 * environment (Resource Groups Tagging API, tag:GetResources): what `arco info --resources` shows and
 * `arco remove` lists before it asks. Tags are Arco's record of what belongs to an environment, so local
 * and CI runs see the same list. Global resources listed only in us-east-1 (CloudFront) appear
 * only when the Tagging API returns them for the region. IAM roles are account-global and never come from
 * it: the execution role Arco made is read directly (iam:GetRole) and listed when it carries this
 * environment's tags.
 */
final class TaggedResources
{
    public function __construct(private readonly ResourceGroupsTaggingAPIClient $tagging, private readonly OwnershipGuard $guard)
    {
    }

    /**
     * Sorted by type, then name. `retained` is true for what `arco remove` keeps (queues, buckets,
     * tables, SSM parameters, log groups, anything Arco does not delete). `source` and `options` are those
     * of the config item the resource belongs to (ConfigItems::forResource()).
     *
     * @return list<array{type: string, name: string, arn: string, retained: bool, relations: list<array{type: string, target: string}>, source: ?string, options: ?array<string, mixed>}>
     * @throws \Aws\Exception\AwsException a refused or failed lookup
     */
    public function list(AppConfig $config): array
    {
        $filters = [];
        foreach (Ownership::tags($config) as $key => $value) {
            $filters[] = ['Key' => $key, 'Values' => [$value]];
        }
        $resources = [];
        $token = null;
        do {
            $page = Aws::call($this->tagging, 'GetResources', [
                'TagFilters' => $filters,
                ...($token === null ? [] : ['PaginationToken' => $token]),
            ]);
            foreach (Aws::maps($page['ResourceTagMappingList'] ?? []) as $mapping) {
                $arn = Aws::string($mapping['ResourceARN'] ?? null);
                $tags = [];
                foreach (Aws::maps($mapping['Tags'] ?? []) as $tag) {
                    $key = Aws::string($tag['Key'] ?? null);
                    if ($key !== null && is_string($tag['Value'] ?? null)) {
                        $tags[$key] = $tag['Value'];
                    }
                }
                // The filter is AWS's; the check is Arco's: another owner's resource is never listed.
                if ($arn === null || ! Ownership::tagged($tags, $config)) {
                    continue;
                }
                [$type, $name] = self::parse($arn);
                $resources[] = [
                    'type' => $type,
                    'name' => $name,
                    'arn' => $arn,
                    'retained' => ! self::removed($config, $type, $name, $tags),
                    'relations' => [],
                    ...ConfigItems::forResource($config, $type, $name, $tags, SchedulerRule::name($config), DomainStack::functionName($config)),
                ];
            }
            $token = Aws::string($page['PaginationToken'] ?? null);
            $token = $token === '' ? null : $token;
        } while ($token !== null);
        $role = $this->executionRole($config);
        if ($role !== null) {
            $resources[] = $role;
        }
        $resources = self::withRelations($config, $resources);
        usort($resources, static fn (array $a, array $b): int => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);
        return $resources;
    }

    /**
     * The execution role Arco manages, when it exists and is this environment's. Informational: a role
     * that is missing, unreadable or denied is simply not listed.
     *
     * @return array{type: string, name: string, arn: string, retained: bool, relations: list<array{type: string, target: string}>, source: ?string, options: ?array<string, mixed>}|null
     */
    private function executionRole(AppConfig $config): ?array
    {
        if ($config->role !== null) {
            return null;
        }
        $name = ExecutionRole::roleName($config);
        try {
            $role = $this->guard->role($name);
        } catch (AwsException) {
            return null;
        }
        if ($role === null || ! Ownership::tagged($role->tags, $config)) {
            return null;
        }
        return ['type' => 'iam:role', 'name' => $name, 'arn' => $role->fullArn ?? $name, 'retained' => false, 'relations' => [], 'source' => 'default', 'options' => null];
    }

    /**
     * Fills each resource's outgoing relations from the resolved config and the listing alone (no extra
     * AWS calls): a queue's worker function `worker_of` its queue, a dead-letter queue `dlq_of` its queue,
     * the scheduler rule `invokes` the console function, the distribution `serves` and a certificate
     * `validates` each configured domain, and the execution role `grants` access to each queue, bucket and
     * table its policy names (the ones PolicyBuilder::document() gets at deploy). A target that cannot be
     * resolved is left out, never guessed.
     *
     * @param list<array{type: string, name: string, arn: string, retained: bool, relations: list<array{type: string, target: string}>, source: ?string, options: ?array<string, mixed>}> $resources
     * @return list<array{type: string, name: string, arn: string, retained: bool, relations: list<array{type: string, target: string}>, source: ?string, options: ?array<string, mixed>}>
     */
    private static function withRelations(AppConfig $config, array $resources): array
    {
        $arns = [];
        foreach ($resources as $resource) {
            $arns[$resource['type'] . ' ' . $resource['name']] = $resource['arn'];
        }
        // The queue ARN: the listed one for a queue Arco made; for an `existing:` queue (never listed) the
        // one the config spells, when it names a region and an account. A managed queue that is not
        // listed has no target: a relation always points at something the listing or the config shows.
        $queueArn = static function (QueueConfig $queue) use ($config, $arns): ?string {
            if ($queue->existing === null) {
                return $arns['sqs ' . $queue->physicalName($config)] ?? null;
            }
            $arn = $queue->arn($config);
            return self::hasRegionAndAccount($arn) ? $arn : null;
        };
        $workers = [];
        $dlqs = [];
        foreach ($config->queues as $queue) {
            $arn = $queueArn($queue);
            if ($arn === null) {
                continue;
            }
            $workers[$config->workerFunctionName($queue->name)] = $arn;
            $dlq = $queue->dlqName($config);
            if ($dlq !== null) {
                $dlqs[$dlq] = $arn;
            }
        }
        // What the execution role's policy names: a managed resource by its listed ARN, an `existing` one by
        // the ARN the config spells (a table only with a region and an account; a bucket ARN has neither).
        // A bucket's name carries the account: without one in arco.php, it is the role's.
        $role = array_values(array_filter($resources, static fn (array $r): bool => $r['type'] === 'iam:role' && $r['name'] === ExecutionRole::roleName($config)))[0] ?? null;
        $roleAccount = $role === null ? '' : (explode(':', $role['arn'], 6)[4] ?? '');
        $granting = $config->account === null && $roleAccount !== '' ? $config->withAccount($roleAccount) : $config;
        $tableArn = static function (?string $existing, string $name, string $arn) use ($arns): ?string {
            if ($existing === null) {
                return $arns['dynamodb:table ' . $name] ?? null;
            }
            return self::hasRegionAndAccount($arn) ? $arn : null;
        };
        $grants = [];
        foreach ($config->queues as $queue) {
            $grants[] = $queueArn($queue);
        }
        foreach ($granting->storage as $storage) {
            $grants[] = $storage->existing === null ? ($arns['s3 ' . $storage->bucketName($granting)] ?? null) : $storage->arn($granting);
        }
        foreach (array_filter([$granting->cacheTable, $granting->failedJobsTable]) as $table) {
            $grants[] = $tableArn($table->existing, $table->tableName($granting), $table->arn($granting));
        }
        foreach ($granting->tables as $table) {
            $grants[] = $tableArn($table->existing, $table->physicalName($granting), $table->arn($granting));
        }
        $grants = array_values(array_unique(array_filter($grants)));
        $certificates = count(array_filter($resources, static fn (array $r): bool => $r['type'] === 'acm:certificate'));
        $console = $arns['lambda:function ' . $config->consoleFunctionName()] ?? null;
        foreach ($resources as &$resource) {
            $resource['relations'] = match ($resource['type']) {
                'lambda:function' => self::relations('worker_of', array_filter([$workers[$resource['name']] ?? null])),
                'sqs' => self::relations('dlq_of', array_filter([$dlqs[$resource['name']] ?? null])),
                'events:rule' => $resource['name'] === SchedulerRule::name($config) ? self::relations('invokes', array_filter([$console])) : [],
                'cloudfront:distribution' => self::relations('serves', $config->domains),
                'acm:certificate' => self::relations('validates', self::validatesDomains($config, $resource['arn'], $certificates)),
                'iam:role' => $resource['name'] === ExecutionRole::roleName($config) ? self::relations('grants', $grants) : [],
                default => [],
            };
        }
        return $resources;
    }

    /** An ARN spelled with both a region and an account (a name-only reference builds `arn:aws:sqs:::name`). */
    private static function hasRegionAndAccount(string $arn): bool
    {
        $parts = explode(':', $arn, 6);
        return ($parts[3] ?? '') !== '' && ($parts[4] ?? '') !== '';
    }

    /**
     * The domains a listed certificate validates. The listing carries no domains, so the certificate must be
     * identified without asking ACM: the configured `certificate()` ARN, else the only one listed. With
     * several (a stale one after the domains changed, one Arco only found) nothing is claimed.
     *
     * @param int $certificates how many certificates are listed
     * @return list<string>
     */
    private static function validatesDomains(AppConfig $config, string $arn, int $certificates): array
    {
        if ($config->certificate !== null) {
            return $arn === $config->certificate ? $config->domains : [];
        }
        return $certificates === 1 ? $config->domains : [];
    }

    /**
     * @param array<array-key, string> $targets
     * @return list<array{type: string, target: string}>
     */
    private static function relations(string $type, array $targets): array
    {
        $out = [];
        foreach ($targets as $target) {
            $out[] = ['type' => $type, 'target' => $target];
        }
        return $out;
    }

    /**
     * The type (`<service>` or `<service>:<resource type>`) and name of a resource from its ARN
     * (arn:partition:service:region:account:resource).
     *
     * @return array{0: string, 1: string}
     */
    public static function parse(string $arn): array
    {
        $parts = explode(':', $arn, 6);
        $service = $parts[2] ?? '';
        $resource = $parts[5] ?? $arn;
        // The resource type ends at the first "/" or ":" (function:name, table/name, log-group:/aws/...).
        $at = strcspn($resource, '/:');
        if ($at === strlen($resource)) {
            return [$service, $resource];
        }
        $kind = substr($resource, 0, $at);
        $name = substr($resource, $at + 1);
        $name = match ($kind) {
            // Parameter names start with a slash (the ARN drops it: parameter/app/env/name).
            'parameter' => str_starts_with($name, '/') ? $name : '/' . $name,
            // stack/<name>/<id>
            'stack' => explode('/', $name)[0],
            'log-group' => str_ends_with($name, ':*') ? substr($name, 0, -2) : $name,
            default => $name,
        };
        return ["$service:$kind", $name];
    }

    /**
     * Whether `arco remove` deletes the resource: the functions (web, console, every worker found by
     * tag), the scheduler rule, both stacks with what the domain stack made, the managed certificate and
     * the Resource Group. The resources stack's queues, buckets and tables are retained.
     *
     * @param array<string, string> $tags
     */
    private static function removed(AppConfig $config, string $type, string $name, array $tags): bool
    {
        $domainStack = DomainStack::stackName($config);
        return match (true) {
            // Its ARN carries the id, not the name; remove deletes the environment's connector.
            $type === 'lambda:network-connector' => true,
            str_starts_with($type, 'lambda:') => $name === $config->functionName() || $name === $config->consoleFunctionName()
                || str_starts_with($name, $config->functionName() . '-queue-'),
            $type === 'events:rule' => $name === SchedulerRule::name($config),
            $type === 'cloudformation:stack' => in_array($name, [ResourcesStack::name($config), $domainStack], true),
            $type === 'resource-groups:group' => $name === EnvironmentGroup::name($config),
            $type === 'iam:role' => $name === ExecutionRole::roleName($config),
            // Made by the domain stack, which remove deletes (its tags reach the distribution, but not the
            // aws:cloudformation:* ones; the distribution is the only one carrying this environment's tags).
            $type === 'cloudfront:distribution' => true,
            $type === 'cloudfront:function' => $name === DomainStack::functionName($config),
            // Only the certificate Arco requested (arco:managed): one it found and reused is kept.
            $type === 'acm:certificate' => ($tags['arco:managed'] ?? null) === 'true',
            default => ($tags['aws:cloudformation:stack-name'] ?? null) === $domainStack,
        };
    }
}
