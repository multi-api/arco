<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\Validator;
use Multiapi\Arco\Deploy\GatewayEndpoints;

/**
 * What a listing says about the environment it is shown for (spec §5.1, §5.3, §5.4, §6.1, §6.3): whether a
 * resource is hidden, already in use and from which source, the Arco name to give it, the command that picks it
 * (`apply`, an argv), what that command would replace (`conflict`), the warnings that depend on the config, and
 * the order the resources are shown in. The listers know nothing of the config; this is the only place that does.
 */
final class Annotator
{
    /**
     * The keys each use of a table needs, as TableLister gives them: what Arco creates for a table() (PK/SK strings),
     * and what Laravel's DynamoDbStore (`key`) and DynamoDbFailedJobProvider (`application` + `uuid`) read (see
     * ResourcesStack::genericTable() and ResourcesStack::table()).
     */
    public const TABLE_KEYS = [
        'generic' => [['name' => 'PK', 'type' => 'S', 'role' => 'HASH'], ['name' => 'SK', 'type' => 'S', 'role' => 'RANGE']],
        'cache' => [['name' => 'key', 'type' => 'S', 'role' => 'HASH']],
        'failedJobs' => [['name' => 'application', 'type' => 'S', 'role' => 'HASH'], ['name' => 'uuid', 'type' => 'S', 'role' => 'RANGE']],
    ];

    /** The command each table use is picked with. */
    private const TABLE_COMMANDS = ['generic' => 'table:add', 'cache' => 'cache:add', 'failedJobs' => 'failed-jobs:add'];

    /** Buckets the CDK and SAM CLI bootstrap for themselves. */
    private const BOOTSTRAP_BUCKETS = ['cdk-', 'aws-sam-cli-'];

    /**
     * Sets the config-dependent fields of every item and returns each type's items in display order, keys kept.
     * Sources come from the resolved config itself (AppConfig::$sources: `arco.php` or `cloud`, the config layer).
     *
     * @param string $codeBucket the bucket Arco uploads code to (CodeBucket::name()), hidden like Arco's own resources
     * @param array<string, list<ResourceItem>> $byType the listers' items, keyed by type
     * @return array<string, list<ResourceItem>>
     */
    public function annotate(AppConfig $config, string $codeBucket, array $byType): array
    {
        $all = array_merge(...array_values($byType));
        $nonDefaultVpc = false;
        foreach ($all as $item) {
            $vpc = self::vpcOf($item);
            $nonDefaultVpc = $nonDefaultVpc || ($vpc !== null && ! $vpc['default']);
        }
        $zones = self::zonesInUse($config, $all);
        $usedRouteTables = self::usedRouteTables($config, $all);

        foreach ($all as $item) {
            $item->inUse = self::inUse($config, $item, $zones);
            $item->hidden = $item->hidden || self::hidden($config, $codeBucket, $item, $nonDefaultVpc);
            if (in_array($item->type, [ResourceType::Bucket, ResourceType::Queue, ResourceType::Table], true)) {
                $item->suggestedName = self::suggestedName($item->name ?? $item->id, $config->app, $config->environment, $config->account);
                if ($item->type === ResourceType::Table && (Validator::genericTableNameError($item->suggestedName)['key'] ?? null) === 'validation.generic_table_reserved') {
                    $item->suggestedName .= '-table'; // cache and failed-jobs are Arco's own tables: table:add refuses them
                }
            }
            $item->apply = self::arcoEndpoint($item) ? null : self::apply($config->environment, $item);
            $item->conflict = self::conflict($config, $item);
            $item->warnings = [...$item->warnings, ...self::warnings($config, $item, $usedRouteTables)];
        }

        $sorted = [];
        foreach ($byType as $type => $items) {
            // A VPC's items stay together: each group goes where its best item would.
            $groupTier = [];
            foreach ($items as $item) {
                $vpc = self::vpcOf($item);
                if ($vpc !== null) {
                    $groupTier[$vpc['id']] = min($groupTier[$vpc['id']] ?? 2, self::tier($config, $item));
                }
            }
            usort($items, fn (ResourceItem $a, ResourceItem $b): int => self::sortKey($config, $groupTier, $a) <=> self::sortKey($config, $groupTier, $b));
            $sorted[$type] = $items;
        }
        return $sorted;
    }

    /**
     * The Arco name an existing resource gets by default: its AWS name lowercased, every character outside
     * [a-z0-9-] made a hyphen (runs of them one), without a trailing `-<account>` (any 12 digits when the account
     * is not known) and then without a leading `<app>-<environment>-`, else `<app>-`. What would be empty keeps
     * the whole slug.
     */
    public static function suggestedName(string $awsName, string $app, string $environment, ?string $account): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($awsName)), '-');
        $slug = (string) preg_replace('/-{2,}/', '-', $slug);
        $name = (string) preg_replace('/-' . ($account === null ? '\d{12}' : preg_quote($account, '/')) . '$/', '', $slug);
        foreach ([strtolower($app . '-' . $environment . '-'), strtolower($app . '-')] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
                break;
            }
        }
        return $name === '' ? $slug : $name;
    }

    /**
     * @param array<string, string> $zones zone id => source, from zonesInUse()
     * @return array{source: string}|null
     */
    private static function inUse(AppConfig $config, ResourceItem $item, array $zones): ?array
    {
        $vpc = $config->vpc;
        $source = match ($item->type) {
            ResourceType::Subnet => $vpc !== null && in_array($item->id, $vpc->subnets, true) ? self::source($config, 'vpc') : null,
            ResourceType::SecurityGroup => $vpc !== null && in_array($item->id, $vpc->securityGroups, true) ? self::source($config, 'vpc') : null,
            ResourceType::Endpoint => $vpc !== null && in_array($item->id, $vpc->endpointIds(), true) ? self::source($config, 'vpc') : null,
            ResourceType::Certificate => $config->certificate === $item->id ? self::source($config, 'certificate') : null,
            ResourceType::Bucket => self::declaring($config, $item)['bucket'] ?? null,
            ResourceType::Queue => self::declaring($config, $item)['queue'] ?? null,
            ResourceType::Table => self::declaring($config, $item)['table'] ?? null,
            ResourceType::Zone => $zones[$item->id] ?? null,
        };
        return $source === null ? null : ['source' => $source];
    }

    /**
     * The source of the storage/queue/table/cache/failedJobsTable entry whose `existing` is this resource, by kind.
     *
     * @return array<string, string>
     */
    private static function declaring(AppConfig $config, ResourceItem $item): array
    {
        $refs = self::references($item);
        $found = [];
        foreach ($config->storage as $storage) {
            if ($storage->existing !== null && in_array($storage->existing, $refs, true)) {
                $found['bucket'] ??= self::source($config, "storage:{$storage->name}");
            }
        }
        foreach ($config->queues as $queue) {
            if ($queue->existing !== null && in_array($queue->existing, $refs, true)) {
                $found['queue'] ??= self::source($config, "queue:{$queue->name}");
            }
        }
        foreach ($config->tables as $table) {
            if ($table->existing !== null && in_array($table->existing, $refs, true)) {
                $found['table'] ??= self::source($config, "table:{$table->name}");
            }
        }
        foreach (['cache' => $config->cacheTable, 'failedJobsTable' => $config->failedJobsTable] as $key => $table) {
            if ($table !== null && $table->existing !== null && in_array($table->existing, $refs, true)) {
                $found['table'] ??= self::source($config, $key);
            }
        }
        return $found;
    }

    /**
     * The ways arco.php can name the resource in an `existing`: its id, its name, its ARN (queues and tables) and
     * its URL (queues).
     *
     * @return list<string>
     */
    private static function references(ResourceItem $item): array
    {
        $refs = [$item->id];
        foreach ([$item->name, $item->details['arn'] ?? null, $item->details['url'] ?? null] as $ref) {
            if (is_string($ref) && $ref !== '') {
                $refs[] = $ref;
            }
        }
        return $refs;
    }

    /**
     * The hosted zones serving the declared domains (Route 53 only: not with dns('cloudflare') or dns(false)): for each
     * domain, the public zone with the longest name that is the domain or a parent of it. Zone id => source.
     *
     * @param list<ResourceItem> $items
     * @return array<string, string>
     */
    private static function zonesInUse(AppConfig $config, array $items): array
    {
        if ($config->dnsCloudflare || $config->dnsManual) {
            return [];
        }
        $zones = [];
        foreach ($config->domains as $domain) {
            $best = null;
            foreach ($items as $item) {
                $name = strtolower((string) $item->name);
                if ($item->type !== ResourceType::Zone || ($item->details['private'] ?? false) === true || $name === '') {
                    continue;
                }
                $host = strtolower($domain);
                if (($host === $name || str_ends_with($host, '.' . $name)) && ($best === null || strlen($name) > strlen((string) $best->name))) {
                    $best = $item;
                }
            }
            if ($best !== null) {
                $source = self::source($config, "domain:$domain");
                // A zone serving domains of both sources is arco.php's, as ConfigItems decides for the distribution.
                $zones[$best->id] = ($zones[$best->id] ?? null) === 'arco.php' ? 'arco.php' : $source;
            }
        }
        return $zones;
    }

    private static function hidden(AppConfig $config, string $codeBucket, ResourceItem $item, bool $nonDefaultVpc): bool
    {
        if ($item->arco() === ['app' => $config->app, 'environment' => $config->environment] || (self::arcoEndpoint($item) && $item->inUse === null)) {
            return true;
        }
        if ($item->type === ResourceType::Bucket) {
            if ($item->id === $codeBucket) {
                return true;
            }
            foreach (self::BOOTSTRAP_BUCKETS as $prefix) {
                if (str_starts_with($item->id, $prefix)) {
                    return true;
                }
            }
        }
        // The default VPC only hides when the account has another one, and never what the environment uses.
        $vpc = self::vpcOf($item);
        return $vpc !== null && $vpc['default'] && $nonDefaultVpc && $item->inUse === null;
    }

    /**
     * A gateway endpoint Arco created (GatewayEndpoints' marker tag) for whichever environments use it: the last of
     * them to leave deletes it, so it is never offered as an existing endpoint id (an environment naming it would not
     * count as a user, and could lose it). vpc() without an id lets Arco find and share it.
     */
    private static function arcoEndpoint(ResourceItem $item): bool
    {
        return $item->type === ResourceType::Endpoint && ($item->tags[GatewayEndpoints::MARKER_KEY] ?? null) === GatewayEndpoints::MARKER_VALUE;
    }

    /** @return array{command: string, args: list<string>}|array<string, array{command: string, args: list<string>}>|null */
    private static function apply(string $environment, ResourceItem $item): ?array
    {
        $env = '--env=' . $environment;
        $name = (string) $item->suggestedName;
        $one = static fn (string $command, string ...$args): array => ['command' => $command, 'args' => [...array_values($args), $env]];
        return match ($item->type) {
            ResourceType::Bucket => $one('storage:add', $name, '--existing=' . $item->id),
            // queue:add refuses an existing .fifo queue without --fifo (validation.queue_existing_fifo).
            ResourceType::Queue => ($item->details['fifo'] ?? false) === true
                ? $one('queue:add', $name, '--existing=' . $item->id, '--fifo')
                : $one('queue:add', $name, '--existing=' . $item->id),
            ResourceType::Table => [
                'generic' => $one(self::TABLE_COMMANDS['generic'], $name, '--existing=' . $item->id),
                'cache' => $one(self::TABLE_COMMANDS['cache'], '--existing=' . $item->id),
                'failedJobs' => $one(self::TABLE_COMMANDS['failedJobs'], '--existing=' . $item->id),
            ],
            ResourceType::Subnet => $one('vpc:set', '--subnet=' . $item->id),
            ResourceType::SecurityGroup => $one('vpc:set', '--security-group=' . $item->id),
            ResourceType::Endpoint => $one('vpc:set', '--endpoint=' . (is_string($item->details['service'] ?? null) ? $item->details['service'] : '') . '=' . $item->id),
            ResourceType::Certificate => $one('certificate:set', $item->id),
            ResourceType::Zone => null,
        };
    }

    /** @return array{with: string, source: string}|null what picking the item would replace in the config */
    private static function conflict(AppConfig $config, ResourceItem $item): ?array
    {
        if ($item->inUse !== null || $item->apply === null) {
            return null;
        }
        switch ($item->type) {
            case ResourceType::Subnet:
            case ResourceType::SecurityGroup:
            case ResourceType::Endpoint:
                $vpc = $config->vpc;
                if ($vpc === null) {
                    return null;
                }
                $endpoints = [];
                foreach ($vpc->endpoints as $endpoint) {
                    $endpoint['id'] === null ? $endpoints[] = $endpoint['service'] : $endpoints[$endpoint['service']] = $endpoint['id'];
                }
                $line = ArcoFileEditor::vpcLine(['subnets' => $vpc->subnets, 'securityGroups' => $vpc->securityGroups, 'endpoints' => $endpoints]);
                return ['with' => substr($line, 2), 'source' => self::source($config, 'vpc')];
            case ResourceType::Certificate:
                return $config->certificate === null ? null
                    : ['with' => substr(ArcoFileEditor::settingLine('certificate', $config->certificate), 2), 'source' => self::source($config, 'certificate')];
            case ResourceType::Bucket:
                $names = array_map(static fn ($storage): string => $storage->name, $config->storage);
                return self::named($config, 'storage', $names, $item);
            case ResourceType::Queue:
                $names = array_map(static fn ($queue): string => $queue->name, $config->queues);
                return self::named($config, 'queue', $names, $item);
            case ResourceType::Table:
                $names = array_map(static fn ($table): string => $table->name, $config->tables);
                return self::named($config, 'table', $names, $item);
            default:
                return null;
        }
    }

    /**
     * @param list<string> $declared the names the kind already declares
     * @return array{with: string, source: string}|null
     */
    private static function named(AppConfig $config, string $kind, array $declared, ResourceItem $item): ?array
    {
        $name = (string) $item->suggestedName;
        if (! in_array($name, $declared, true)) {
            return null;
        }
        return ['with' => $kind . '(' . var_export($name, true) . ')', 'source' => self::source($config, "$kind:$name")];
    }

    /**
     * @param array<string, list<string>> $usedRouteTables VPC id => route tables of the subnets in use there
     * @return list<array{key: string, params: array<string, string>}>
     */
    private static function warnings(AppConfig $config, ResourceItem $item, array $usedRouteTables): array
    {
        switch ($item->type) {
            case ResourceType::Certificate:
                $domains = is_array($item->details['domains'] ?? null) ? array_values(array_filter($item->details['domains'], 'is_string')) : [];
                return CertificateChecks::notCovering(['SubjectAlternativeNames' => $domains], $config->domains);
            case ResourceType::Table:
                $keys = $item->details['keys'] ?? null;
                $warnings = [];
                foreach (self::TABLE_KEYS as $use => $expected) {
                    if ($keys !== $expected) {
                        $shown = implode(', ', array_map(static fn (array $key): string => "{$key['name']} ({$key['type']}, {$key['role']})", $expected));
                        $warnings[] = ['key' => 'resources.warning.table_schema', 'params' => ['use' => $use, 'expected' => $shown]];
                    }
                }
                return $warnings;
            case ResourceType::Endpoint:
                $vpc = self::vpcOf($item);
                $attached = is_array($item->details['routeTables'] ?? null) ? array_filter($item->details['routeTables'], 'is_string') : [];
                $missing = array_values(array_diff($usedRouteTables[$vpc['id'] ?? ''] ?? [], $attached));
                return $missing === [] ? [] : [['key' => 'resources.warning.endpoint_missing_routes', 'params' => ['routeTables' => implode(', ', $missing)]]];
            default:
                return [];
        }
    }

    /**
     * The route tables the environment's subnets use, by VPC, from the listed subnets (the same tables 0.12.1's
     * deploy.endpoint_routes_missing check reads: the associated table, else the VPC's main one).
     *
     * @param list<ResourceItem> $items
     * @return array<string, list<string>>
     */
    private static function usedRouteTables(AppConfig $config, array $items): array
    {
        $tables = [];
        foreach ($items as $item) {
            $vpc = self::vpcOf($item);
            $table = $item->details['routeTable'] ?? null;
            if ($item->type !== ResourceType::Subnet || $vpc === null || ! is_string($table) || ! in_array($item->id, $config->vpc->subnets ?? [], true)) {
                continue;
            }
            if (! in_array($table, $tables[$vpc['id']] ?? [], true)) {
                $tables[$vpc['id']][] = $table;
            }
        }
        return $tables;
    }

    /**
     * Subnets, security groups and endpoints are grouped by VPC, the groups ordered by their best tier (then VPC
     * id); everything else is one group. Inside a group: by tier, then by name (else id), then id.
     *
     * @param array<string, int> $groupTier VPC id => the best tier of its items
     * @return array{int, string, int, string, string}
     */
    private static function sortKey(AppConfig $config, array $groupTier, ResourceItem $item): array
    {
        $vpc = self::vpcOf($item)['id'] ?? '';
        return [$groupTier[$vpc] ?? 0, $vpc, self::tier($config, $item), strtolower($item->name ?? $item->id), $item->id];
    }

    /** 0 in use, 1 matches the app (its stack, its arco:app tag or its name contains the app name), 2 the rest. */
    private static function tier(AppConfig $config, ResourceItem $item): int
    {
        if ($item->inUse !== null) {
            return 0;
        }
        $app = strtolower($config->app);
        $matches = ($item->tags['arco:app'] ?? null) === $config->app
            || str_contains(strtolower((string) $item->stack()), $app)
            || str_contains(strtolower((string) $item->name), $app);
        return $matches ? 1 : 2;
    }

    /** @return array{id: string, default: bool}|null the VPC of a subnet, security group or endpoint */
    private static function vpcOf(ResourceItem $item): ?array
    {
        $vpc = $item->details['vpc'] ?? null;
        if (! is_array($vpc) || ! is_string($vpc['id'] ?? null)) {
            return null;
        }
        return ['id' => $vpc['id'], 'default' => ($vpc['default'] ?? false) === true];
    }

    /** `arco.php` for what arco.php sets (app or environment level), `cloud` for what the config layer adds. */
    private static function source(AppConfig $config, string $key): string
    {
        return ($config->sources[$key] ?? null) === 'cloud' ? 'cloud' : 'arco.php';
    }
}
