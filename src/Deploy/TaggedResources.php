<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Domains\Aws;
use Multiapi\Arco\Deploy\Domains\DomainStack;

/**
 * Every resource of the app's region carrying both `arco:app` and `arco:environment` tags of the
 * environment (Resource Groups Tagging API, tag:GetResources): what `arco info --resources` shows and
 * `arco remove` lists before it asks. Tags are Arco's record of what belongs to an environment, so local
 * and CI runs see the same list. Global resources listed only in us-east-1 (CloudFront) and IAM roles
 * do not appear unless the Tagging API returns them for the region.
 */
final class TaggedResources
{
    public function __construct(private readonly ResourceGroupsTaggingAPIClient $tagging)
    {
    }

    /**
     * Sorted by type, then name. `retained` is true for what `arco remove` keeps (queues, buckets,
     * tables, SSM parameters, log groups, anything Arco does not delete).
     *
     * @return list<array{type: string, name: string, arn: string, retained: bool}>
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
                $resources[] = ['type' => $type, 'name' => $name, 'arn' => $arn, 'retained' => ! self::removed($config, $type, $name, $tags)];
            }
            $token = Aws::string($page['PaginationToken'] ?? null);
            $token = $token === '' ? null : $token;
        } while ($token !== null);
        usort($resources, static fn (array $a, array $b): int => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);
        return $resources;
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
            str_starts_with($type, 'lambda:') => $name === $config->functionName() || $name === $config->consoleFunctionName()
                || str_starts_with($name, $config->functionName() . '-queue-'),
            $type === 'events:rule' => $name === SchedulerRule::name($config),
            $type === 'cloudformation:stack' => in_array($name, [ResourcesStack::name($config), $domainStack], true),
            $type === 'resource-groups:group' => $name === EnvironmentGroup::name($config),
            $type === 'iam:role' => $name === ExecutionRole::roleName($config),
            $type === 'acm:certificate' => ($tags['arco:managed'] ?? null) === 'true',
            default => ($tags['aws:cloudformation:stack-name'] ?? null) === $domainStack,
        };
    }
}
