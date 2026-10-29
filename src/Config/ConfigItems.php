<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/**
 * What the resolved config declares, item by item, with where each item comes from and its resolved options
 * (`toArray()` key names, defaults included): the `config`, `variables` and per-resource `source`/`options`
 * of `arco info --resources --json`, which Arco Cloud reads to copy an item's real options. Sources read
 * `arco.php` (app or environment level), `cloud` (the config layer) or `default` (Arco's own default). Never a
 * variable value.
 */
final class ConfigItems
{
    /**
     * Every item the config declares, deployed or not: queues, buckets, tables, cache, failed-jobs table, console,
     * scheduler, domains (one each) and the VPC, in that order; singletons are named after their kind.
     *
     * @return list<array{kind: string, name: string, source: string, options: array<string, mixed>}>
     */
    public static function list(AppConfig $config): array
    {
        $items = [];
        foreach ($config->queues as $queue) {
            $items[] = self::item('queue', $queue->name, self::source($config, "queue:{$queue->name}"), self::queueOptions($queue));
        }
        foreach ($config->storage as $storage) {
            $items[] = self::item('storage', $storage->name, self::source($config, "storage:{$storage->name}"), self::storageOptions($storage));
        }
        foreach ($config->tables as $table) {
            $items[] = self::item('table', $table->name, self::source($config, "table:{$table->name}"), self::tableOptions($table));
        }
        if ($config->cacheTable !== null) {
            $items[] = self::item('cache', 'cache', self::source($config, 'cache'), ['existing' => $config->cacheTable->existing]);
        }
        if ($config->failedJobsTable !== null) {
            $items[] = self::item('failedJobsTable', 'failedJobsTable', self::source($config, 'failedJobsTable'), ['existing' => $config->failedJobsTable->existing]);
        }
        if ($config->consoleEnabled()) {
            $items[] = self::item('console', 'console', self::consoleSource($config), self::consoleOptions($config));
        }
        if ($config->scheduler !== null) {
            $items[] = self::item('scheduler', 'scheduler', self::source($config, 'scheduler'), ['scheduler' => $config->scheduler]);
        }
        foreach ($config->domains as $domain) {
            $items[] = self::item('domain', $domain, self::source($config, "domain:$domain"), [
                'domain' => $domain,
                'dns' => self::dns($config),
                'certificate' => $config->certificate,
            ]);
        }
        if ($config->vpc !== null) {
            $items[] = self::item('vpc', 'vpc', self::source($config, 'vpc'), self::vpcOptions($config->vpc));
        }
        return $items;
    }

    /**
     * The variables' names and sources, sorted by name. Never their values.
     *
     * @return list<array{name: string, source: string}>
     */
    public static function variables(AppConfig $config): array
    {
        $names = array_map('strval', array_keys($config->variables));
        sort($names, SORT_STRING);
        return array_map(static fn (string $name): array => ['name' => $name, 'source' => self::source($config, "variable:$name")], $names);
    }

    /**
     * The source and options of the item a listed resource belongs to: by its `arco:resource` tag when it has one
     * (`queue:<name>`, `queue:<name>:dlq`, `storage:<name>`, `table:<name>`, `cache`, `failed-jobs`), by the names
     * Arco gives otherwise. What Arco always makes (web function, execution role, log groups, stacks, Resource
     * Group) is `default` without options; a resource whose item is no longer in the config is `null`/`null`.
     * The scheduler rule and CloudFront function names are the deployer's, so the caller passes them.
     *
     * @param array<string, string> $tags
     * @param string $schedulerRule the name Arco gives the scheduler rule (SchedulerRule::name())
     * @param string $cloudFrontFunction the name Arco gives the CloudFront function (DomainStack::functionName())
     * @return array{source: ?string, options: ?array<string, mixed>}
     */
    public static function forResource(AppConfig $config, string $type, string $name, array $tags, string $schedulerRule, string $cloudFrontFunction): array
    {
        $tag = $tags['arco:resource'] ?? null;
        if ($tag !== null) {
            $tagged = self::byTag($config, $tag);
            if ($tagged !== false) {
                return $tagged ?? self::none();
            }
        }
        return match ($type) {
            'lambda:function' => self::function($config, $name),
            'sqs' => self::queueByName($config, $name),
            's3' => self::storageByName($config, $name),
            'dynamodb:table' => self::tableByName($config, $name),
            'events:rule' => $name === $schedulerRule && $config->scheduler !== null
                ? self::found(self::source($config, 'scheduler'), ['scheduler' => $config->scheduler]) : self::none(),
            'lambda:network-connector' => $config->vpc !== null ? self::found(self::source($config, 'vpc'), self::vpcOptions($config->vpc)) : self::none(),
            'cloudfront:distribution', 'acm:certificate' => self::domains($config),
            'cloudfront:function' => $name === $cloudFrontFunction ? self::domains($config) : self::none(),
            'iam:role', 'logs:log-group', 'cloudformation:stack', 'resource-groups:group' => ['source' => 'default', 'options' => null],
            default => self::none(),
        };
    }

    /**
     * The item an `arco:resource` tag names: null when that item is no longer configured, false for a tag
     * this method does not know (the resource is then matched by name).
     *
     * @return array{source: ?string, options: ?array<string, mixed>}|false|null
     */
    private static function byTag(AppConfig $config, string $tag): array|false|null
    {
        if ($tag === 'cache' || $tag === 'failed-jobs') {
            $table = $tag === 'cache' ? $config->cacheTable : $config->failedJobsTable;
            return $table === null ? null : self::found(self::source($config, $tag === 'cache' ? 'cache' : 'failedJobsTable'), ['existing' => $table->existing]);
        }
        $parts = explode(':', $tag);
        if (count($parts) < 2) {
            return false;
        }
        [$kind, $item] = $parts;
        return match (true) {
            $kind === 'queue' && (count($parts) === 2 || (count($parts) === 3 && $parts[2] === 'dlq')) => self::queue($config, $item),
            $kind === 'storage' && count($parts) === 2 => self::storage($config, $item),
            $kind === 'table' && count($parts) === 2 => self::table($config, $item),
            default => false,
        };
    }

    /** @return array{source: ?string, options: ?array<string, mixed>} */
    private static function function(AppConfig $config, string $name): array
    {
        if ($name === $config->functionName()) {
            return ['source' => 'default', 'options' => null];
        }
        if ($name === $config->consoleFunctionName()) {
            return $config->consoleEnabled() ? self::found(self::consoleSource($config), self::consoleOptions($config)) : self::none();
        }
        foreach ($config->queues as $queue) {
            if ($config->workerFunctionName($queue->name) === $name) {
                return self::found(self::source($config, "queue:{$queue->name}"), self::queueOptions($queue));
            }
        }
        return self::none();
    }

    /** @return array{source: ?string, options: ?array<string, mixed>} */
    private static function queueByName(AppConfig $config, string $name): array
    {
        foreach ($config->queues as $queue) {
            if ($queue->physicalName($config) === $name || $queue->dlqName($config) === $name) {
                return self::found(self::source($config, "queue:{$queue->name}"), self::queueOptions($queue));
            }
        }
        return self::none();
    }

    /** @return array{source: ?string, options: ?array<string, mixed>} */
    private static function storageByName(AppConfig $config, string $name): array
    {
        foreach ($config->storage as $storage) {
            // A managed bucket's name carries the account: without one it cannot be matched by name.
            if (($storage->existing !== null || $config->account !== null) && $storage->bucketName($config) === $name) {
                return self::found(self::source($config, "storage:{$storage->name}"), self::storageOptions($storage));
            }
        }
        return self::none();
    }

    /** @return array{source: ?string, options: ?array<string, mixed>} */
    private static function tableByName(AppConfig $config, string $name): array
    {
        foreach ($config->tables as $table) {
            if ($table->physicalName($config) === $name) {
                return self::found(self::source($config, "table:{$table->name}"), self::tableOptions($table));
            }
        }
        foreach (['cache' => $config->cacheTable, 'failedJobsTable' => $config->failedJobsTable] as $key => $table) {
            if ($table !== null && $table->tableName($config) === $name) {
                return self::found(self::source($config, $key), ['existing' => $table->existing]);
            }
        }
        return self::none();
    }

    /** @return array{source: ?string, options: ?array<string, mixed>}|null */
    private static function queue(AppConfig $config, string $name): ?array
    {
        foreach ($config->queues as $queue) {
            if ($queue->name === $name) {
                return self::found(self::source($config, "queue:$name"), self::queueOptions($queue));
            }
        }
        return null;
    }

    /** @return array{source: ?string, options: ?array<string, mixed>}|null */
    private static function storage(AppConfig $config, string $name): ?array
    {
        foreach ($config->storage as $storage) {
            if ($storage->name === $name) {
                return self::found(self::source($config, "storage:$name"), self::storageOptions($storage));
            }
        }
        return null;
    }

    /** @return array{source: ?string, options: ?array<string, mixed>}|null */
    private static function table(AppConfig $config, string $name): ?array
    {
        foreach ($config->tables as $table) {
            if ($table->name === $name) {
                return self::found(self::source($config, "table:$name"), self::tableOptions($table));
            }
        }
        return null;
    }

    /**
     * The distribution, its certificate and CloudFront function serve every domain: `arco.php` when any domain
     * comes from it, `cloud` when every domain is the layer's, else `default`; none when no domain is
     * configured any more.
     *
     * @return array{source: ?string, options: ?array<string, mixed>}
     */
    private static function domains(AppConfig $config): array
    {
        if ($config->domains === []) {
            return self::none();
        }
        $sources = array_map(static fn (string $domain): string => self::source($config, "domain:$domain"), $config->domains);
        $source = match (true) {
            in_array('arco.php', $sources, true) => 'arco.php',
            array_unique($sources) === ['cloud'] => 'cloud',
            default => 'default',
        };
        return self::found($source, [
            'domains' => $config->domains,
            'dns' => self::dns($config),
            'certificate' => $config->certificate,
        ]);
    }

    /** The dns() setting as stored: a profile name, 'cloudflare', false (manual) or null (Route 53 in the deploy account). */
    private static function dns(AppConfig $config): string|false|null
    {
        return match (true) {
            $config->dnsCloudflare => 'cloudflare',
            $config->dnsManual => false,
            default => $config->dnsProfile,
        };
    }

    /** The console's own setting; when only the scheduler turns it on, where the scheduler comes from. */
    private static function consoleSource(AppConfig $config): string
    {
        $source = self::source($config, 'console');
        return $source === 'default' && $config->scheduler !== null ? self::source($config, 'scheduler') : $source;
    }

    /** @return array<string, mixed> */
    private static function consoleOptions(AppConfig $config): array
    {
        return ['console' => $config->console, 'consoleTimeout' => $config->consoleTimeout, 'consoleMemory' => $config->consoleMemory];
    }

    /** @return array<string, mixed> */
    private static function queueOptions(QueueConfig $queue): array
    {
        return [
            'timeout' => $queue->timeout,
            'memory' => $queue->memory,
            'maxInstances' => $queue->maxInstances,
            'batchSize' => $queue->batchSize,
            'tries' => $queue->tries,
            'dlq' => $queue->dlq,
            'fifo' => $queue->fifo,
            'existing' => $queue->existing,
            'default' => $queue->default,
        ];
    }

    /** @return array<string, mixed> */
    private static function storageOptions(StorageConfig $storage): array
    {
        return ['public' => $storage->public, 'expire' => $storage->expire, 'cors' => $storage->cors, 'existing' => $storage->existing, 'default' => $storage->default];
    }

    /** @return array<string, mixed> */
    private static function tableOptions(GenericTableConfig $table): array
    {
        return ['ttl' => $table->ttl, 'existing' => $table->existing];
    }

    /**
     * The vpc() setting as stored: endpoints as a list of services, or `service => id` for an existing one.
     *
     * @return array<string, mixed>
     */
    private static function vpcOptions(VpcConfig $vpc): array
    {
        $endpoints = [];
        foreach ($vpc->endpoints as $endpoint) {
            if ($endpoint['id'] === null) {
                $endpoints[] = $endpoint['service'];
            } else {
                $endpoints[$endpoint['service']] = $endpoint['id'];
            }
        }
        return ['subnets' => $vpc->subnets, 'securityGroups' => $vpc->securityGroups, 'endpoints' => $endpoints];
    }

    /** `app` and `environment` read `arco.php`; `cloud` and `default` stay; an unknown key is a default. */
    private static function source(AppConfig $config, string $key): string
    {
        return match ($config->sources[$key] ?? 'default') {
            'app', 'environment' => 'arco.php',
            'cloud' => 'cloud',
            default => 'default',
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return array{kind: string, name: string, source: string, options: array<string, mixed>}
     */
    private static function item(string $kind, string $name, string $source, array $options): array
    {
        return ['kind' => $kind, 'name' => $name, 'source' => $source, 'options' => $options];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{source: ?string, options: ?array<string, mixed>}
     */
    private static function found(string $source, array $options): array
    {
        return ['source' => $source, 'options' => $options];
    }

    /** @return array{source: ?string, options: ?array<string, mixed>} */
    private static function none(): array
    {
        return ['source' => null, 'options' => null];
    }
}
