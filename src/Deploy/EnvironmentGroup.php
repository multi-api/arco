<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Aws\ResourceGroups\ResourceGroupsClient;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * The AWS Resource Group `arco-<app>-<env>`: a tag query on `arco:app` and `arco:environment`, so the
 * AWS console shows an environment's resources together, like a stack. A convenience view only: the
 * tags are the ownership record, and callers turn every failure here into a note.
 */
final class EnvironmentGroup
{
    public const TYPE = 'TAG_FILTERS_1_0';
    /** Error codes of a refused Resource Groups call. */
    public const DENIED = ['AccessDenied', 'AccessDeniedException', 'ForbiddenException', 'UnauthorizedException'];

    public function __construct(private readonly ResourceGroupsClient $groups)
    {
    }

    public static function name(AppConfig $config): string
    {
        return 'arco-' . $config->functionName();
    }

    /** The group's resource query: every supported resource type carrying both tags of the environment. */
    public static function query(AppConfig $config): string
    {
        return (string) json_encode([
            'ResourceTypeFilters' => ['AWS::AllSupported'],
            'TagFilters' => [
                ['Key' => Ownership::APP, 'Values' => [$config->app]],
                ['Key' => Ownership::ENVIRONMENT, 'Values' => [$config->environment]],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Creates the group, or updates its query when it differs from the environment's. A group with the
     * name that does not carry this environment's tags (someone else's) is left alone.
     *
     * @return bool whether the group was created or its query updated (false: it was already the environment's)
     * @throws DeployException deploy.group_not_ours
     * @throws AwsException a refused or failed call
     */
    public function ensure(AppConfig $config): bool
    {
        $name = self::name($config);
        $current = $this->currentQuery($name);
        if ($current === false) {
            Aws::call($this->groups, 'CreateGroup', [
                'Name' => $name,
                'Description' => "Arco resources of app {$config->app} environment {$config->environment}",
                'ResourceQuery' => ['Type' => self::TYPE, 'Query' => self::query($config)],
                'Tags' => Ownership::tags($config),
            ]);
            return true;
        }
        if (self::matches($current, $config)) {
            return false;
        }
        $group = Aws::map(Aws::call($this->groups, 'GetGroup', ['Group' => $name])['Group'] ?? null);
        $arn = Aws::string($group['GroupArn'] ?? null) ?? throw new \UnexpectedValueException('resource-groups:GetGroup returned no GroupArn.');
        $tags = [];
        foreach (Aws::map(Aws::call($this->groups, 'GetTags', ['Arn' => $arn])['Tags'] ?? null) as $key => $value) {
            if (is_string($value)) {
                $tags[$key] = $value;
            }
        }
        if (! Ownership::tagged($tags, $config)) {
            throw DeployException::make('deploy.group_not_ours', ['group' => $name]);
        }
        Aws::call($this->groups, 'UpdateGroupQuery', ['Group' => $name, 'ResourceQuery' => ['Type' => self::TYPE, 'Query' => self::query($config)]]);
        return true;
    }

    /**
     * Deletes the group when its query is this environment's (a group someone else made with the name is
     * never deleted).
     *
     * @return bool whether a group was deleted (false: there was none)
     * @throws DeployException remove.group_not_ours
     * @throws AwsException a refused or failed call
     */
    public function delete(AppConfig $config): bool
    {
        $name = self::name($config);
        $current = $this->currentQuery($name);
        if ($current === false) {
            return false;
        }
        if (! self::matches($current, $config)) {
            throw DeployException::make('remove.group_not_ours', ['group' => $name]);
        }
        Aws::call($this->groups, 'DeleteGroup', ['Group' => $name]);
        return true;
    }

    /**
     * The group's resource query, or false when there is no group with the name.
     *
     * @return array{type: ?string, query: ?string}|false
     */
    private function currentQuery(string $name): array|false
    {
        try {
            $result = Aws::call($this->groups, 'GetGroupQuery', ['Group' => $name]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return false;
            }
            throw $e;
        }
        $query = Aws::map(Aws::map($result['GroupQuery'] ?? null)['ResourceQuery'] ?? null);
        return ['type' => Aws::string($query['Type'] ?? null), 'query' => Aws::string($query['Query'] ?? null)];
    }

    /**
     * Whether a resource query selects exactly this environment's tags (order and spacing aside).
     *
     * @param array{type: ?string, query: ?string} $current
     */
    private static function matches(array $current, AppConfig $config): bool
    {
        if ($current['type'] !== self::TYPE || $current['query'] === null) {
            return false;
        }
        $decoded = json_decode($current['query'], true);
        return is_array($decoded) && self::normalize($decoded) === self::normalize((array) json_decode(self::query($config), true));
    }

    /**
     * @param array<mixed> $query
     * @return array{types: list<string>, tags: array<string, list<string>>, other: list<string>}
     */
    private static function normalize(array $query): array
    {
        $types = Aws::strings($query['ResourceTypeFilters'] ?? []);
        sort($types);
        $tags = [];
        foreach (Aws::maps($query['TagFilters'] ?? []) as $filter) {
            $values = Aws::strings($filter['Values'] ?? []);
            sort($values);
            $tags[(string) Aws::string($filter['Key'] ?? null)] = $values;
        }
        ksort($tags);
        // Any other key (a future query field) makes it someone else's query.
        $other = array_values(array_diff(array_map('strval', array_keys($query)), ['ResourceTypeFilters', 'TagFilters']));
        return ['types' => $types, 'tags' => $tags, 'other' => $other];
    }
}
