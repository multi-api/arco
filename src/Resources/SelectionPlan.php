<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Closure;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\TableConfig;
use Multiapi\Arco\Config\VpcConfig;

/**
 * Turns what the person ticked in the picker into the editing commands that write it (spec §6, §8), as argv like the
 * JSON's `apply`, so the picker, CI and Arco Cloud end up with the same arco.php. Also says which arco.php line each
 * command adds or replaces, for the preview (spec §7 step 5), without writing anything.
 */
final class SelectionPlan
{
    private const NETWORK = [ResourceType::Subnet, ResourceType::SecurityGroup, ResourceType::Endpoint];

    /**
     * Subnets, security groups and endpoints become one vpc:set with the full list (it sets, it does not merge):
     * what the config has, less the in-use ones that were listed and unticked (the picker pre-ticks them), plus the
     * new picks. What the listing did not return (a type not listed or failed, an id AWS no longer knows, the
     * endpoints Arco creates) is kept. Unticking everything is a vpc:remove. Every other pick runs its `apply`, with
     * the Arco name and the table use chosen; picks already in use are left as they are.
     *
     * @param list<ResourceItem> $picked every ticked item, annotated (inUse, apply, suggestedName)
     * @param array<string, string> $names item id => the Arco name chosen (buckets, queues, tables used as tables)
     * @param array<string, string> $uses table id => generic, cache or failedJobs (default generic)
     * @param list<ResourceItem>|null $listed every item the person was shown; null: every id the config has
     * @return list<array{command: string, args: list<string>}>
     * @throws UserError resources.mixed_vpcs, resources.vpc_incomplete (the message is the key; translate it)
     */
    public static function from(AppConfig $config, array $picked, array $names, array $uses, ?array $listed = null): array
    {
        $plan = [];
        $vpc = self::vpc($config, $picked, $listed);
        if ($vpc !== null) {
            $plan[] = $vpc;
        }
        foreach ($picked as $item) {
            if (in_array($item->type, self::NETWORK, true) || $item->inUse !== null) {
                continue;
            }
            $command = self::command($item, $names, $uses);
            if ($command !== null) {
                $plan[] = $command;
            }
        }
        return $plan;
    }

    /**
     * The arco.php line a command replaces (null: none) and the one it writes (null: it removes the call), as the
     * editing command would print them. vpc:set and vpc:remove write the environment's own vpc(): with
     * $environmentVpc (environmentVpc()) the lines are that level's, as vpc:remove decides (an inherited vpc() is left
     * with ->vpc(false)); without it, the resolved vpc() stands for the environment's.
     *
     * @param array{command: string, args: list<string>} $command
     * @param array{line: ?string, inherited: bool}|null $environmentVpc
     * @return array{old: ?string, new: ?string}
     */
    public static function preview(AppConfig $config, array $command, ?array $environmentVpc = null): array
    {
        $positional = [];
        $options = [];
        foreach ($command['args'] as $arg) {
            if (str_starts_with($arg, '--')) {
                [$key, $value] = explode('=', substr($arg, 2), 2) + [1 => ''];
                $options[$key][] = $value;
            } else {
                $positional[] = $arg;
            }
        }
        $existing = ['existing' => $options['existing'][0] ?? ''];
        $name = $positional[0] ?? '';
        $currentVpc = $environmentVpc !== null ? $environmentVpc['line'] : ($config->vpc === null ? null : ArcoFileEditor::vpcLine(self::vpcValue($config->vpc)));
        return match ($command['command']) {
            'vpc:set' => ['old' => $currentVpc, 'new' => ArcoFileEditor::vpcLine([
                'subnets' => $options['subnet'] ?? [],
                'securityGroups' => $options['security-group'] ?? [],
                'endpoints' => self::endpointValues($options['endpoint'] ?? []),
            ])],
            'vpc:remove' => ['old' => $currentVpc, 'new' => ($environmentVpc['inherited'] ?? false) ? ArcoFileEditor::vpcLine(false) : null],
            'certificate:set' => [
                'old' => $config->certificate === null ? null : ArcoFileEditor::settingLine('certificate', $config->certificate),
                'new' => ArcoFileEditor::settingLine('certificate', $name),
            ],
            'storage:add' => ['old' => null, 'new' => ArcoFileEditor::namedLine('storage', $name, $existing)],
            'queue:add' => ['old' => null, 'new' => ArcoFileEditor::queueLine($name, isset($options['fifo']) ? [...$existing, 'fifo' => true] : $existing)],
            'table:add' => ['old' => null, 'new' => ArcoFileEditor::namedLine('table', $name, $existing)],
            'cache:add' => ['old' => self::tableLine('cache', $config->cacheTable), 'new' => ArcoFileEditor::singletonLine('cache', $existing)],
            'failed-jobs:add' => ['old' => self::tableLine('failedJobsTable', $config->failedJobsTable), 'new' => ArcoFileEditor::singletonLine('failedJobsTable', $existing)],
            default => ['old' => null, 'new' => null],
        };
    }

    /**
     * vpc() as the environment itself sets it in arco.php (what vpc:set replaces and vpc:remove removes): its line
     * (null: it sets none; `->vpc(false)` when it opts out), and whether the app sets a vpc() it would inherit.
     *
     * @param array<string, mixed> $raw arco.php as ConfigLoader loads it; [] without arco.php
     * @return array{line: ?string, inherited: bool}
     */
    public static function environmentVpc(array $raw, string $environment): array
    {
        $setting = static function (mixed $level): mixed {
            $settings = is_array($level) ? $level['settings'] ?? null : null;
            return is_array($settings) ? $settings['vpc'] ?? null : null;
        };
        $environments = $raw['environments'] ?? null;
        $own = $setting(is_array($environments) ? $environments[$environment] ?? null : null);
        $strings = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
        $line = match (true) {
            $own === false => ArcoFileEditor::vpcLine(false),
            is_array($own) => ArcoFileEditor::vpcLine([
                'subnets' => $strings($own['subnets'] ?? null),
                'securityGroups' => $strings($own['securityGroups'] ?? null),
                'endpoints' => is_array($own['endpoints'] ?? null) ? array_filter($own['endpoints'], 'is_string') : [],
            ]),
            default => null,
        };
        return ['line' => $line, 'inherited' => is_array($setting($raw))];
    }

    /**
     * @param list<ResourceItem> $picked
     * @param list<ResourceItem>|null $listed
     * @return array{command: string, args: list<string>}|null
     */
    private static function vpc(AppConfig $config, array $picked, ?array $listed): ?array
    {
        $current = $config->vpc;
        $vpcs = [];
        foreach ($picked as $item) {
            $vpc = self::vpcId($item);
            if (in_array($item->type, self::NETWORK, true) && is_string($vpc) && ! in_array($vpc, $vpcs, true)) {
                $vpcs[] = $vpc;
            }
        }
        if (count($vpcs) > 1) {
            sort($vpcs);
            throw new UserError('resources.mixed_vpcs', 'resources.mixed_vpcs', ['vpcs' => implode(', ', $vpcs)]);
        }

        $pickedIds = array_map(static fn (ResourceItem $item): string => $item->id, $picked);
        $listedIds = $listed === null ? null : array_map(static fn (ResourceItem $item): string => $item->id, $listed);
        // An id of the config goes only when it was listed and left unticked.
        $kept = static fn (string $id): bool => in_array($id, $pickedIds, true) || ($listedIds !== null && ! in_array($id, $listedIds, true));
        $subnets = self::merge($current->subnets ?? [], $kept, $picked, ResourceType::Subnet);
        $groups = self::merge($current->securityGroups ?? [], $kept, $picked, ResourceType::SecurityGroup);
        $owned = [];
        foreach ($current->endpoints ?? [] as $endpoint) {
            if ($endpoint['id'] !== null && $kept($endpoint['id'])) {
                $owned[$endpoint['service']] = $endpoint['id'];
            }
        }
        foreach ($picked as $item) {
            $service = $item->details['service'] ?? null;
            if ($item->type === ResourceType::Endpoint && is_string($service)) {
                $owned[$service] = $item->id;
            }
        }
        // The endpoints Arco creates are not listed: they stay, unless one of the service is now picked.
        $managed = [];
        foreach ($current->endpoints ?? [] as $endpoint) {
            if ($endpoint['id'] === null && ! isset($owned[$endpoint['service']])) {
                $managed[] = $endpoint['service'];
            }
        }

        $env = '--env=' . $config->environment;
        if ($subnets === [] && $groups === [] && $owned === []) {
            return $current === null ? null : ['command' => 'vpc:remove', 'args' => [$env]];
        }
        if ($subnets === [] || $groups === []) {
            throw new UserError('resources.vpc_incomplete', 'resources.vpc_incomplete');
        }
        $endpoints = [...$managed, ...array_map(static fn (string $service, string $id): string => "$service=$id", array_keys($owned), $owned)];
        if ($current !== null && self::same($current->subnets, $subnets) && self::same($current->securityGroups, $groups)
            && self::same(self::endpointArgs(self::vpcValue($current)['endpoints']), $endpoints)) {
            return null;
        }
        return ['command' => 'vpc:set', 'args' => [
            ...array_map(static fn (string $id): string => '--subnet=' . $id, $subnets),
            ...array_map(static fn (string $id): string => '--security-group=' . $id, $groups),
            ...array_map(static fn (string $endpoint): string => '--endpoint=' . $endpoint, $endpoints),
            $env,
        ]];
    }

    /**
     * The config's ids that stay, then the picks of the type that are new.
     *
     * @param list<string> $ids
     * @param Closure(string): bool $kept
     * @param list<ResourceItem> $picked
     * @return list<string>
     */
    private static function merge(array $ids, Closure $kept, array $picked, ResourceType $type): array
    {
        $merged = [];
        foreach ($ids as $id) {
            if ($kept($id)) {
                $merged[] = $id;
            }
        }
        foreach ($picked as $item) {
            if ($item->type === $type && ! in_array($item->id, $merged, true)) {
                $merged[] = $item->id;
            }
        }
        return $merged;
    }

    /** The VPC of a subnet, security group or endpoint, as its lister gives it. */
    public static function vpcId(ResourceItem $item): ?string
    {
        $vpc = $item->details['vpc'] ?? null;
        return is_array($vpc) && is_string($vpc['id'] ?? null) ? $vpc['id'] : null;
    }

    /**
     * @param array<string, string> $names
     * @param array<string, string> $uses
     * @return array{command: string, args: list<string>}|null
     */
    private static function command(ResourceItem $item, array $names, array $uses): ?array
    {
        $apply = $item->apply;
        $named = in_array($item->type, [ResourceType::Bucket, ResourceType::Queue], true);
        if ($item->type === ResourceType::Table) {
            $use = $uses[$item->id] ?? 'generic';
            $apply = $apply[$use] ?? null;
            $named = $use === 'generic';
        }
        if (! is_array($apply) || ! is_string($apply['command'] ?? null) || ! is_array($apply['args'] ?? null)) {
            return null;
        }
        /** @var list<string> $args */
        $args = array_values($apply['args']);
        if ($named && isset($names[$item->id]) && $args !== []) {
            $args[0] = $names[$item->id]; // the suggested name the apply carries
        }
        return ['command' => $apply['command'], 'args' => $args];
    }

    /** @return array{subnets: list<string>, securityGroups: list<string>, endpoints: array<int|string, string>} */
    private static function vpcValue(VpcConfig $vpc): array
    {
        $endpoints = [];
        foreach ($vpc->endpoints as $endpoint) {
            $endpoint['id'] === null ? $endpoints[] = $endpoint['service'] : $endpoints[$endpoint['service']] = $endpoint['id'];
        }
        return ['subnets' => $vpc->subnets, 'securityGroups' => $vpc->securityGroups, 'endpoints' => $endpoints];
    }

    /**
     * vpc:set's --endpoint values as vpc() holds them: `s3` (Arco creates it) or `s3=vpce-...` (an existing one).
     *
     * @param list<string> $values
     * @return array<int|string, string>
     */
    private static function endpointValues(array $values): array
    {
        $endpoints = [];
        foreach ($values as $value) {
            if (str_contains($value, '=')) {
                [$service, $id] = explode('=', $value, 2);
                $endpoints[$service] = $id;
            } else {
                $endpoints[] = $value;
            }
        }
        return $endpoints;
    }

    /**
     * @param array<int|string, string> $endpoints
     * @return list<string>
     */
    private static function endpointArgs(array $endpoints): array
    {
        $args = [];
        foreach ($endpoints as $key => $value) {
            $args[] = is_int($key) ? $value : "$key=$value";
        }
        return $args;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function same(array $a, array $b): bool
    {
        sort($a);
        sort($b);
        return $a === $b;
    }

    /** @param 'cache'|'failedJobsTable' $method */
    private static function tableLine(string $method, ?TableConfig $table): ?string
    {
        if ($table === null) {
            return null;
        }
        return ArcoFileEditor::singletonLine($method, $table->existing === null ? true : ['existing' => $table->existing]);
    }
}
