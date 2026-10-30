<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Exception\AwsException;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\Domains\Aws;

/** DynamoDB tables with their key schema, TTL attribute and billing mode; a table deleted while listing is left out. */
final class TableLister implements Lister
{
    public function type(): ResourceType
    {
        return ResourceType::Table;
    }

    public function list(AwsClients $aws): array
    {
        $db = $aws->dynamoDb();
        $items = [];
        foreach ($this->names($db) as $name) {
            try {
                $table = Aws::map(Aws::call($db, 'DescribeTable', ['TableName' => $name])['Table'] ?? []);
                $arn = Aws::string($table['TableArn'] ?? null);
                $ttl = Aws::map(Aws::call($db, 'DescribeTimeToLive', ['TableName' => $name])['TimeToLiveDescription'] ?? []);
                $tags = $arn === null ? [] : $this->tags($db, $arn);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                    continue; // deleted while listing
                }
                throw $e;
            }
            $ttlOn = in_array($ttl['TimeToLiveStatus'] ?? null, ['ENABLED', 'ENABLING'], true);
            $items[] = new ResourceItem(
                ResourceType::Table,
                $name,
                $name,
                $tags,
                [
                    'arn' => $arn,
                    'keys' => self::keys($table),
                    'ttl' => $ttlOn ? Aws::string($ttl['AttributeName'] ?? null) : null,
                    'billing' => Aws::string(Aws::map($table['BillingModeSummary'] ?? [])['BillingMode'] ?? null) ?? 'PROVISIONED',
                ],
            );
        }
        usort($items, fn (ResourceItem $a, ResourceItem $b) => $a->id <=> $b->id);
        return $items;
    }

    /** @return list<string> */
    private function names(DynamoDbClient $db): array
    {
        $names = [];
        $start = null;
        do {
            $page = Aws::call($db, 'ListTables', $start === null ? [] : ['ExclusiveStartTableName' => $start]);
            $names = [...$names, ...Aws::strings($page['TableNames'] ?? [])];
            $start = Aws::string($page['LastEvaluatedTableName'] ?? null);
        } while ($start !== null);
        return $names;
    }

    /** @return array<string, string> */
    private function tags(DynamoDbClient $db, string $arn): array
    {
        $tags = [];
        $token = null;
        do {
            $page = Aws::call($db, 'ListTagsOfResource', ['ResourceArn' => $arn, ...($token === null ? [] : ['NextToken' => $token])]);
            $tags = [...$tags, ...Tags::map($page['Tags'] ?? [])];
            $token = Aws::string($page['NextToken'] ?? null);
        } while ($token !== null);
        return $tags;
    }

    /**
     * @param array<string, mixed> $table
     * @return list<array{name: string, type: string, role: string}>
     */
    private static function keys(array $table): array
    {
        $types = [];
        foreach (Aws::maps($table['AttributeDefinitions'] ?? []) as $definition) {
            $types[(string) Aws::string($definition['AttributeName'] ?? null)] = (string) Aws::string($definition['AttributeType'] ?? null);
        }
        $keys = [];
        foreach (Aws::maps($table['KeySchema'] ?? []) as $key) {
            $name = Aws::string($key['AttributeName'] ?? null);
            if ($name !== null) {
                $keys[] = ['name' => $name, 'type' => $types[$name] ?? '', 'role' => (string) Aws::string($key['KeyType'] ?? null)];
            }
        }
        return $keys;
    }
}
