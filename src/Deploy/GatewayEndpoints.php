<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * The S3 and DynamoDB gateway endpoints of vpc(): they keep the traffic to those services inside the VPC, where a
 * function without a NAT gateway has no other way out. plan() only reads; it decides per service whether the
 * environment's route tables are served and what to do if they are not. apply() carries a plan out (create or
 * associate, and the usage tag); release() is how an environment leaves.
 */
final class GatewayEndpoints
{
    public const MARKER_KEY = 'arco:managed';
    public const MARKER_VALUE = 'gateway-endpoint';
    /** The Web Function tag that records the endpoints the environment uses (space-separated ids). */
    public const RECORD_TAG = 'arco:gateway-endpoints';
    public const MAX_TAGS = 50;
    /** Seconds to wait for a new endpoint to become available, and between two checks. */
    public const AVAILABLE_TIMEOUT = 300;
    public const POLL = 5;
    private const USAGE_PREFIX = 'arco:uses:';
    /** Endpoints in these states are gone or going: they neither cover route tables nor count as existing. */
    private const IGNORED_STATES = ['deleting', 'deleted', 'failed', 'rejected', 'expired'];

    public function __construct(
        private readonly Ec2Client $ec2,
        private readonly Sleeper $sleeper,
        private readonly string $region,
    ) {
    }

    /** `arco:uses:<app>/<env>`: the tag an environment puts on an Arco endpoint it uses. */
    public static function usageTag(AppConfig $config): string
    {
        return self::USAGE_PREFIX . $config->app . '/' . $config->environment;
    }

    /**
     * One plan per endpoint service of vpc(), in declaration order. Call it only when vpc() declares endpoints.
     *
     * @return list<EndpointPlan>
     * @throws DeployException deploy.endpoint_vpc_mixed, deploy.endpoint_not_found, deploy.endpoint_wrong_type,
     *                         deploy.endpoint_wrong_service, deploy.endpoint_wrong_vpc
     */
    public function plan(AppConfig $config): array
    {
        $vpc = $config->vpc;
        if ($vpc === null || $vpc->endpointIds() === []) {
            return [];
        }
        $subnets = $vpc->subnets;
        $vpcId = $this->vpcOf($subnets);
        $tables = $this->routeTablesOf($vpcId, $subnets);
        $plans = [];
        foreach ($vpc->endpointIds() as $service => $named) {
            $plans[] = $this->planService($service, $named, $vpcId, $tables);
        }
        return $plans;
    }

    /**
     * Carries out a plan: creates the endpoint or adds the missing route tables, then puts the environment's usage tag
     * on every Arco endpoint that serves it (an environment can be served by several: one per subset of route
     * tables). Never tags an endpoint that is not Arco's. A conflict with a concurrent deploy (the route table now
     * belongs to another endpoint, or an endpoint went away) is retried once after planning again.
     *
     * @param array<string, string> $tags Ownership::tags() of the environment, put on a created endpoint
     * @param callable(string, array<string, string|int>): void $step
     * @return list<string> the Arco endpoint ids the environment now relies on, sorted (empty when only the user's cover it)
     * @throws \LogicException for a plan that still has to be decided (DECIDE) or an ASSOCIATE without a target
     * @throws DeployException deploy.endpoint_conflict, deploy.endpoint_tag_limit, deploy.endpoint_timeout, deploy.endpoint_create_failed
     */
    public function apply(AppConfig $config, EndpointPlan $plan, array $tags, callable $step): array
    {
        for ($attempt = 0;; $attempt++) {
            if ($plan->action === EndpointPlan::DECIDE) {
                throw new \LogicException('A plan to decide must be resolved with withAssociate() or withSkip() before apply().');
            }
            if ($plan->action === EndpointPlan::ASSOCIATE && $plan->target === null) {
                throw new \LogicException('A plan to associate needs the endpoint to add the route tables to.');
            }
            try {
                return $this->applyPlan($config, $plan, $tags, $step);
            } catch (AwsException $e) {
                if (! self::retryable($e)) {
                    throw $e;
                }
                $params = ['service' => $plan->service, 'error' => self::error($e)];
                if ($attempt === 1) {
                    throw DeployException::make('deploy.endpoint_conflict', $params, $e);
                }
                $plan = $this->replan($config, $plan->service) ?? throw DeployException::make('deploy.endpoint_conflict', $params, $e);
            }
        }
    }

    /**
     * Takes the environment's usage tag off each endpoint, and deletes the Arco endpoints that nobody uses any more.
     * Route tables stay where they are. Never throws for AWS: a failure is a warning with the manual commands.
     *
     * @param list<string> $endpointIds
     * @param callable(string, array<string, string|int>): void $step
     * @param 'deploy.'|'remove.' $prefix
     * @return bool true when every endpoint was released (or was already gone)
     */
    public function release(AppConfig $config, array $endpointIds, callable $step, string $prefix): bool
    {
        $usage = self::usageTag($config);
        $released = true;
        foreach ($endpointIds as $id) {
            try {
                $error = $this->releaseOne($id, $usage, $step, $prefix);
            } catch (AwsException $e) {
                $error = self::notFound($e) ? null : self::error($e);
            }
            if ($error !== null) {
                $released = false;
                $step($prefix . 'endpoint_cleanup_failed', ['endpoint' => $id, 'tag' => $usage, 'region' => $this->region, 'error' => $error]);
            }
        }
        return $released;
    }

    /**
     * @param callable(string, array<string, string|int>): void $step
     * @return string|null why the endpoint could not be released (an `Unsuccessful` item of DeleteVpcEndpoints), null when it was
     */
    private function releaseOne(string $id, string $usage, callable $step, string $prefix): ?string
    {
        $endpoint = $this->readOne($id);
        if ($endpoint === null || ! $endpoint['arco']) {
            return null;
        }
        $this->ec2->deleteTags(['Resources' => [$id], 'Tags' => [['Key' => $usage]]]);
        // Read again after untagging: another environment may have tagged it since, or released it at the same time.
        $endpoint = $this->readOne($id);
        if ($endpoint === null) {
            return null;
        }
        if ($endpoint['uses'] > 0) {
            $step($prefix . 'endpoint_released', ['endpoint' => $id]);
            return null;
        }
        // EC2 reports a delete it refused per id in `Unsuccessful`, without throwing.
        $result = $this->ec2->deleteVpcEndpoints(['VpcEndpointIds' => [$id]]);
        foreach (Aws::maps($result['Unsuccessful'] ?? []) as $item) {
            $error = Aws::maps([$item['Error'] ?? null])[0] ?? [];
            $code = (string) Aws::string($error['Code'] ?? null);
            if (str_starts_with($code, 'InvalidVpcEndpointId.NotFound')) {
                return null;
            }
            return trim($code . ' ' . (string) Aws::string($error['Message'] ?? null));
        }
        $step($prefix . 'endpoint_deleted', ['endpoint' => $id]);
        return null;
    }

    /**
     * @param array<string, string> $tags
     * @param callable(string, array<string, string|int>): void $step
     * @return list<string>
     */
    private function applyPlan(AppConfig $config, EndpointPlan $plan, array $tags, callable $step): array
    {
        $usage = self::usageTag($config);
        $arco = [];
        foreach ($plan->covering as $endpoint) {
            if ($endpoint['arco']) {
                $arco[$endpoint['id']] = true;
            }
        }
        if ($plan->target !== null && $plan->targetIsArco) {
            $arco[$plan->target] = true;
        }
        $arco = array_keys($arco);

        // Read the tags first: a full endpoint stops the deploy before anything is changed.
        $untagged = [];
        foreach ($arco === [] ? [] : $this->read($arco) as $id => $endpoint) {
            if (isset($endpoint['tags'][$usage])) {
                continue;
            }
            if (count($endpoint['tags']) >= self::MAX_TAGS) {
                throw DeployException::make('deploy.endpoint_tag_limit', ['endpoint' => $id, 'max' => self::MAX_TAGS]);
            }
            $untagged[] = $id;
        }

        // Before the route tables change: a release that runs meanwhile finds the endpoint in use (a created one is tagged on create).
        if ($untagged !== []) {
            $this->ec2->createTags(['Resources' => $untagged, 'Tags' => [['Key' => $usage, 'Value' => '1']]]);
        }

        $created = null;
        if ($plan->action === EndpointPlan::CREATE) {
            $created = $this->create($plan, $tags + [self::MARKER_KEY => self::MARKER_VALUE, $usage => '1'], $step);
        } elseif ($plan->action === EndpointPlan::ASSOCIATE && $plan->target !== null) {
            $modified = $this->ec2->modifyVpcEndpoint(['VpcEndpointId' => $plan->target, 'AddRouteTableIds' => $plan->missing]);
            if (($modified['Return'] ?? true) === false) {
                throw DeployException::make('deploy.endpoint_modify_failed', ['endpoint' => $plan->target]);
            }
            $step('deploy.endpoint_routes_added', ['service' => $plan->service, 'endpoint' => $plan->target, 'route_tables' => implode(', ', $plan->missing)]);
        } elseif ($plan->action === EndpointPlan::NONE) {
            $reused = [];
            foreach ($plan->covering as $endpoint) {
                if (! $endpoint['arco']) {
                    $reused[$endpoint['id']] = true;
                }
            }
            if ($plan->target !== null && ! $plan->targetIsArco) {
                $reused[$plan->target] = true;
            }
            $reused = array_keys($reused);
            sort($reused);
            foreach ($reused as $endpoint) {
                $step('deploy.endpoint_reused', ['service' => $plan->service, 'endpoint' => $endpoint]);
            }
            // Arco's own endpoints too: a deploy that changes nothing still says which endpoint serves the service.
            $shared = $arco;
            sort($shared);
            foreach ($shared as $endpoint) {
                $step('deploy.endpoint_shared', ['service' => $plan->service, 'endpoint' => $endpoint]);
            }
        }
        $ids = $created === null ? $arco : [...$arco, $created];
        sort($ids);
        return $ids;
    }

    /**
     * @param array<string, string> $tags
     * @param callable(string, array<string, string|int>): void $step
     */
    private function create(EndpointPlan $plan, array $tags, callable $step): string
    {
        $result = $this->ec2->createVpcEndpoint([
            'VpcEndpointType' => 'Gateway',
            'VpcId' => $plan->vpcId,
            'ServiceName' => $this->serviceName($plan->service),
            'RouteTableIds' => $plan->missing,
            'TagSpecifications' => [[
                'ResourceType' => 'vpc-endpoint',
                'Tags' => array_map(fn (string $k, string $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), $tags),
            ]],
        ]);
        $raw = $result['VpcEndpoint'] ?? null;
        $id = is_array($raw) || $raw instanceof \ArrayAccess ? Aws::string($raw['VpcEndpointId'] ?? null) : null;
        if ($id === null) {
            throw new \RuntimeException('CreateVpcEndpoint returned no endpoint id.');
        }
        $this->waitAvailable($id);
        $step('deploy.endpoint_created', ['service' => $plan->service, 'endpoint' => $id, 'route_tables' => implode(', ', $plan->missing)]);
        return $id;
    }

    private function waitAvailable(string $id): void
    {
        $deadline = $this->sleeper->now() + self::AVAILABLE_TIMEOUT;
        while (true) {
            try {
                $page = $this->ec2->describeVpcEndpoints(['VpcEndpointIds' => [$id]]);
            } catch (AwsException $e) {
                // Right after CreateVpcEndpoint EC2 can still answer NotFound for the new id: not there yet.
                if (! self::notFound($e)) {
                    throw $e;
                }
                $page = ['VpcEndpoints' => []];
            }
            $state = strtolower((string) Aws::string((Aws::maps($page['VpcEndpoints'] ?? [])[0] ?? [])['State'] ?? null));
            if ($state === 'available') {
                return;
            }
            if (in_array($state, self::IGNORED_STATES, true)) {
                throw DeployException::make('deploy.endpoint_create_failed', ['endpoint' => $id, 'state' => $state]);
            }
            if ($this->sleeper->now() >= $deadline) {
                throw DeployException::make('deploy.endpoint_timeout', ['endpoint' => $id, 'seconds' => self::AVAILABLE_TIMEOUT]);
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    private function replan(AppConfig $config, string $service): ?EndpointPlan
    {
        foreach ($this->plan($config) as $plan) {
            if ($plan->service === $service) {
                return $plan->action === EndpointPlan::DECIDE ? null : $plan;
            }
        }
        return null;
    }

    /** @return array{arco: bool, uses: int, tags: array<string, string>}|null */
    private function readOne(string $id): ?array
    {
        foreach ($this->read([$id]) as $endpoint) {
            return $endpoint;
        }
        return null;
    }

    /**
     * The live endpoints by id with their tags; an id that is gone or going is left out.
     *
     * @param list<string> $ids
     * @return array<string, array{arco: bool, uses: int, tags: array<string, string>}>
     */
    private function read(array $ids): array
    {
        $found = [];
        $page = $this->ec2->describeVpcEndpoints(['VpcEndpointIds' => $ids]);
        foreach (Aws::maps($page['VpcEndpoints'] ?? []) as $raw) {
            $endpoint = self::parse($raw);
            if ($endpoint === null) {
                continue;
            }
            $tags = [];
            foreach (Aws::maps($raw['Tags'] ?? []) as $tag) {
                $tags[Aws::string($tag['Key'] ?? null) ?? ''] = Aws::string($tag['Value'] ?? null) ?? '';
            }
            $found[$endpoint['id']] = ['arco' => $endpoint['arco'], 'uses' => $endpoint['uses'], 'tags' => $tags];
        }
        ksort($found);
        return $found;
    }

    /**
     * What a concurrent deploy can cause: the route table already has a route for the service's prefix list
     * (EC2 answers `RouteAlreadyExists`, or `InvalidParameter` saying the route exists), or the endpoint
     * it planned on went away. Reading again fixes both.
     */
    private static function retryable(AwsException $e): bool
    {
        $code = (string) $e->getAwsErrorCode();
        return $code === 'RouteAlreadyExists'
            || str_starts_with($code, 'InvalidVpcEndpointId.NotFound')
            || ($code === 'InvalidParameter' && preg_match('/\balready\b.*\broute\b|\broute\b.*\balready\b/i', (string) $e->getAwsErrorMessage()) === 1);
    }

    private static function notFound(AwsException $e): bool
    {
        return str_starts_with((string) $e->getAwsErrorCode(), 'InvalidVpcEndpointId.NotFound');
    }

    private static function error(AwsException $e): string
    {
        return trim((string) $e->getAwsErrorCode() . ' ' . (string) $e->getAwsErrorMessage());
    }

    /** @param list<string> $subnets */
    private function vpcOf(array $subnets): string
    {
        $vpcs = [];
        $token = null;
        do {
            $page = $this->ec2->describeSubnets([
                'SubnetIds' => $subnets,
                ...($token === null ? [] : ['NextToken' => $token]),
            ]);
            foreach (Aws::maps($page['Subnets'] ?? []) as $subnet) {
                $vpc = Aws::string($subnet['VpcId'] ?? null);
                if ($vpc !== null) {
                    $vpcs[$vpc] = true;
                }
            }
            $token = self::next($page);
        } while ($token !== null);
        $vpcs = array_keys($vpcs);
        sort($vpcs);
        if (count($vpcs) > 1) {
            throw DeployException::make('deploy.endpoint_vpc_mixed', ['vpcs' => implode(', ', $vpcs)]);
        }
        return $vpcs[0] ?? '';
    }

    /**
     * The route tables the subnets use: the one associated with the subnet, else the VPC's main table.
     *
     * @param list<string> $subnets
     * @return list<string>
     */
    private function routeTablesOf(string $vpcId, array $subnets): array
    {
        $explicit = [];
        $main = null;
        $token = null;
        do {
            $page = $this->ec2->describeRouteTables([
                'Filters' => [['Name' => 'vpc-id', 'Values' => [$vpcId]]],
                ...($token === null ? [] : ['NextToken' => $token]),
            ]);
            foreach (Aws::maps($page['RouteTables'] ?? []) as $table) {
                $id = Aws::string($table['RouteTableId'] ?? null);
                if ($id === null) {
                    continue;
                }
                foreach (Aws::maps($table['Associations'] ?? []) as $association) {
                    $subnet = Aws::string($association['SubnetId'] ?? null);
                    if ($subnet !== null) {
                        $explicit[$subnet] = $id;
                    }
                    if (($association['Main'] ?? false) === true) {
                        $main = $id;
                    }
                }
            }
            $token = self::next($page);
        } while ($token !== null);

        $tables = [];
        foreach ($subnets as $subnet) {
            $table = $explicit[$subnet] ?? $main;
            if ($table !== null && !in_array($table, $tables, true)) {
                $tables[] = $table;
            }
        }
        return $tables;
    }

    /** @param list<string> $tables */
    private function planService(string $service, ?string $named, string $vpcId, array $tables): EndpointPlan
    {
        $endpoints = $this->endpoints($service, $vpcId);
        $covering = [];
        $covered = [];
        foreach ($endpoints as $endpoint) {
            $served = array_values(array_intersect($tables, $endpoint['tables']));
            if ($served !== []) {
                $covering[] = ['id' => $endpoint['id'], 'arco' => $endpoint['arco'], 'uses' => $endpoint['uses']];
                $covered = [...$covered, ...$served];
            }
        }
        $user = array_values(array_map(fn (array $e) => $e['id'], array_filter($endpoints, fn (array $e) => !$e['arco'])));
        $context = [$service, $vpcId, $tables, $user, $covering];

        if ($named !== null) {
            $endpoint = $endpoints[$named] ?? $this->named($service, $named, $vpcId);
            // A route table belongs to one gateway endpoint per service: what another endpoint serves is not missing.
            $missing = array_values(array_diff($tables, [...$endpoint['tables'], ...$covered]));
            return self::result($missing, $missing === [] ? EndpointPlan::NONE : EndpointPlan::ASSOCIATE, $named, $endpoint['arco'], ...$context);
        }

        $missing = array_values(array_diff($tables, $covered));
        if ($missing === []) {
            $arco = null;
            foreach ($covering as $endpoint) {
                if ($endpoint['arco']) {
                    $arco = $endpoint['id'];
                    break;
                }
            }
            return self::result([], EndpointPlan::NONE, $arco, $arco !== null, ...$context);
        }
        foreach ($endpoints as $endpoint) {
            if ($endpoint['arco']) {
                return self::result($missing, EndpointPlan::ASSOCIATE, $endpoint['id'], true, ...$context);
            }
        }
        return self::result($missing, $endpoints === [] ? EndpointPlan::CREATE : EndpointPlan::DECIDE, null, false, ...$context);
    }

    /**
     * @param list<string> $missing the route tables left to serve
     * @param list<string> $tables
     * @param list<string> $user
     * @param list<array{id: string, arco: bool, uses: int}> $covering
     */
    private static function result(array $missing, string $action, ?string $target, bool $arco, string $service, string $vpcId, array $tables, array $user, array $covering): EndpointPlan
    {
        return new EndpointPlan($service, $vpcId, $tables, $missing, $action, $target, $arco, $user, $covering);
    }

    /**
     * The live gateway endpoints of the service in the VPC, by id.
     *
     * @return array<string, array{id: string, arco: bool, uses: int, tables: list<string>}>
     */
    private function endpoints(string $service, string $vpcId): array
    {
        $found = [];
        $token = null;
        do {
            $page = $this->ec2->describeVpcEndpoints([
                'Filters' => [
                    ['Name' => 'vpc-id', 'Values' => [$vpcId]],
                    ['Name' => 'service-name', 'Values' => [$this->serviceName($service)]],
                    ['Name' => 'vpc-endpoint-type', 'Values' => ['Gateway']],
                ],
                ...($token === null ? [] : ['NextToken' => $token]),
            ]);
            foreach (Aws::maps($page['VpcEndpoints'] ?? []) as $raw) {
                $endpoint = self::parse($raw);
                if ($endpoint !== null) {
                    $found[$endpoint['id']] = $endpoint;
                }
            }
            $token = self::next($page);
        } while ($token !== null);
        ksort($found);
        return $found;
    }

    /**
     * The endpoint a vpc() line names that the filtered list did not return: say why it does not fit.
     *
     * @return array{id: string, arco: bool, uses: int, tables: list<string>}
     */
    private function named(string $service, string $id, string $vpcId): array
    {
        $params = ['service' => $service, 'endpoint' => $id];
        try {
            $page = $this->ec2->describeVpcEndpoints(['VpcEndpointIds' => [$id]]);
        } catch (AwsException $e) {
            if (str_contains((string) $e->getAwsErrorCode(), 'NotFound')) {
                throw DeployException::make('deploy.endpoint_not_found', $params, $e);
            }
            throw $e;
        }
        $raw = Aws::maps($page['VpcEndpoints'] ?? [])[0] ?? null;
        $endpoint = $raw === null ? null : self::parse($raw);
        if ($endpoint === null) {
            throw DeployException::make('deploy.endpoint_not_found', $params);
        }
        if (Aws::string($raw['VpcEndpointType'] ?? null) !== 'Gateway') {
            throw DeployException::make('deploy.endpoint_wrong_type', $params);
        }
        if (Aws::string($raw['ServiceName'] ?? null) !== $this->serviceName($service)) {
            throw DeployException::make('deploy.endpoint_wrong_service', $params);
        }
        throw DeployException::make('deploy.endpoint_wrong_vpc', $params);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{id: string, arco: bool, uses: int, tables: list<string>}|null null for a missing id or an endpoint that is gone
     */
    private static function parse(array $raw): ?array
    {
        $id = Aws::string($raw['VpcEndpointId'] ?? null);
        if ($id === null || in_array(strtolower((string) Aws::string($raw['State'] ?? null)), self::IGNORED_STATES, true)) {
            return null;
        }
        $arco = false;
        $uses = 0;
        foreach (Aws::maps($raw['Tags'] ?? []) as $tag) {
            $key = Aws::string($tag['Key'] ?? null) ?? '';
            if ($key === self::MARKER_KEY && Aws::string($tag['Value'] ?? null) === self::MARKER_VALUE) {
                $arco = true;
            } elseif (str_starts_with($key, self::USAGE_PREFIX)) {
                $uses++;
            }
        }
        return ['id' => $id, 'arco' => $arco, 'uses' => $uses, 'tables' => Aws::strings($raw['RouteTableIds'] ?? [])];
    }

    private function serviceName(string $service): string
    {
        return "com.amazonaws.{$this->region}.{$service}";
    }

    /** @param \ArrayAccess<string, mixed> $page */
    private static function next(\ArrayAccess $page): ?string
    {
        $token = Aws::string($page['NextToken'] ?? null);
        return $token === '' ? null : $token;
    }
}
