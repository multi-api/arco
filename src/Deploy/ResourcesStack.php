<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\GenericTableConfig;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Config\StorageConfig;
use Multiapi\Arco\Config\TableConfig;

/**
 * The CloudFormation template of an environment's AWS resources other than functions: the SQS
 * queues Arco creates and their dead-letter queues, the S3 buckets of its storage, the DynamoDB
 * cache and failed-jobs tables, and the app's table() tables. Every queue, bucket and table is retained when it is removed from
 * arco.php or when the stack is deleted, with its messages, files or items (DeletionPolicy
 * RetainExceptOnCreate, UpdateReplacePolicy Retain). It is deleted only when the very operation that
 * created it rolls back: it is then new and unused, since the resources stack deploys before the app
 * that could use it, and keeping it would block the next deploy with "name already exists".
 * Resources referenced with `existing` are not in the template.
 */
final class ResourcesStack
{
    public const DLQ_RETENTION = 1209600;
    /** SQS keeps a message hidden this many times the worker timeout once a worker receives it. */
    public const VISIBILITY_FACTOR = 6;
    /** Receives a message gets before it goes to the dead-letter queue, besides the worker's tries. */
    public const EXTRA_RECEIVES = 2;
    /** See the class docblock: retained, except when the operation that created the resource rolls back. */
    public const DELETION_POLICY = 'RetainExceptOnCreate';
    /** The attribute Laravel's DynamoDB cache store and failed-job provider write the expiry to. */
    public const TTL_ATTRIBUTE = 'expires_at';

    public static function name(AppConfig $config): string
    {
        return substr('arco-' . $config->functionName() . '-resources', 0, 128);
    }

    /**
     * The logical id of a queue: stable for a name, since a new id would replace (and, retained,
     * collide with) the queue. Names are lowercase, so the X standing for each hyphen is unambiguous.
     */
    public static function logicalId(string $queue): string
    {
        return 'Queue' . str_replace('-', 'X', ucfirst($queue));
    }

    public static function dlqLogicalId(string $queue): string
    {
        return self::logicalId($queue) . 'Dlq';
    }

    /** The logical id of a storage's bucket. "Storage" never starts a queue id; the name rules match logicalId(). */
    public static function bucketLogicalId(string $storage): string
    {
        return 'Storage' . str_replace('-', 'X', ucfirst($storage));
    }

    /** The bucket policy of a storage's public prefixes: no storage name ends its id in "Policy" (names are lowercase). */
    public static function bucketPolicyLogicalId(string $storage): string
    {
        return self::bucketLogicalId($storage) . 'Policy';
    }

    /** @param 'cache'|'failed-jobs' $kind */
    public static function tableLogicalId(string $kind): string
    {
        return 'Table' . str_replace('-', 'X', ucfirst($kind));
    }

    /**
     * The logical id of a table() table. "AppTable" starts no other id (queues, storage and Arco's own
     * tables start with Queue, Storage and Table); the name rules match logicalId().
     */
    public static function genericTableLogicalId(string $table): string
    {
        return 'AppTable' . str_replace('-', 'X', ucfirst($table));
    }

    /** @return list<GenericTableConfig> the table() tables Arco creates (not `existing` ones) */
    public static function createdGenericTables(AppConfig $config): array
    {
        return array_values(array_filter($config->tables, static fn (GenericTableConfig $table): bool => $table->existing === null));
    }

    /** @return list<QueueConfig> the queues Arco creates (not `existing` ones) */
    public static function createdQueues(AppConfig $config): array
    {
        return array_values(array_filter($config->queues, static fn (QueueConfig $queue): bool => $queue->existing === null));
    }

    /** @return list<StorageConfig> the storage whose bucket Arco creates (not `existing` ones) */
    public static function createdStorage(AppConfig $config): array
    {
        return array_values(array_filter($config->storage, static fn (StorageConfig $storage): bool => $storage->existing === null));
    }

    /** @return list<TableConfig> the tables Arco creates (not `existing` ones): cache, then failed jobs */
    public static function createdTables(AppConfig $config): array
    {
        return array_values(array_filter([$config->cacheTable, $config->failedJobsTable], static fn (?TableConfig $table): bool => $table !== null && $table->existing === null));
    }

    /** @return array<string, mixed> */
    public static function template(AppConfig $config): array
    {
        $resources = [];
        $outputs = [];
        foreach (self::createdQueues($config) as $queue) {
            $id = self::logicalId($queue->name);
            $properties = [
                'QueueName' => $queue->physicalName($config),
                'VisibilityTimeout' => self::VISIBILITY_FACTOR * $queue->timeout,
            ];
            if ($queue->fifo) {
                $properties['FifoQueue'] = true;
            }
            $dlqName = $queue->dlqName($config);
            if ($dlqName !== null) {
                $properties['RedrivePolicy'] = [
                    'deadLetterTargetArn' => ['Fn::GetAtt' => [self::dlqLogicalId($queue->name), 'Arn']],
                    'maxReceiveCount' => $queue->tries + self::EXTRA_RECEIVES,
                ];
            }
            $properties['Tags'] = self::tags($config, "queue:{$queue->name}");
            $resources[$id] = self::queue($properties);

            if ($dlqName !== null) {
                $dlq = ['QueueName' => $dlqName, 'MessageRetentionPeriod' => self::DLQ_RETENTION];
                if ($queue->fifo) {
                    $dlq['FifoQueue'] = true;
                }
                $dlq['Tags'] = self::tags($config, "queue:{$queue->name}:dlq");
                $resources[self::dlqLogicalId($queue->name)] = self::queue($dlq);
            }

            $outputs["{$id}Url"] = ['Value' => ['Ref' => $id]];
            $outputs["{$id}Arn"] = ['Value' => ['Fn::GetAtt' => [$id, 'Arn']]];
        }

        foreach (self::createdStorage($config) as $storage) {
            $id = self::bucketLogicalId($storage->name);
            $resources[$id] = self::bucket($config, $storage);
            if ($storage->public !== []) {
                $resources[self::bucketPolicyLogicalId($storage->name)] = self::publicPolicy($config, $storage);
            }
            $outputs["{$id}Name"] = ['Value' => ['Ref' => $id]];
            $outputs["{$id}Arn"] = ['Value' => ['Fn::GetAtt' => [$id, 'Arn']]];
        }

        foreach (self::createdTables($config) as $table) {
            $id = self::tableLogicalId($table->kind);
            $resources[$id] = self::table($config, $table);
            $outputs["{$id}Name"] = ['Value' => ['Ref' => $id]];
            $outputs["{$id}Arn"] = ['Value' => ['Fn::GetAtt' => [$id, 'Arn']]];
        }

        foreach (self::createdGenericTables($config) as $table) {
            $id = self::genericTableLogicalId($table->name);
            $resources[$id] = self::genericTable($config, $table);
            $outputs["{$id}Name"] = ['Value' => ['Ref' => $id]];
            $outputs["{$id}Arn"] = ['Value' => ['Fn::GetAtt' => [$id, 'Arn']]];
        }

        return [
            'AWSTemplateFormatVersion' => '2010-09-09',
            'Description' => "Arco resources for {$config->app}/{$config->environment}",
            'Resources' => $resources,
            'Outputs' => $outputs,
        ];
    }

    /** The logical id of the no-op resource a stack created for an import holds until the update after it. */
    public const IMPORT_PLACEHOLDER = 'ArcoImportPlaceholder';

    /**
     * The template of a stack created only to import into: an IMPORT change set cannot add stack tags, so
     * the stack is created first, tagged, with a resource that makes nothing (a template needs one). The
     * update after the import removes it.
     *
     * @return array<string, mixed>
     */
    public static function placeholderTemplate(AppConfig $config): array
    {
        return [
            'AWSTemplateFormatVersion' => '2010-09-09',
            'Description' => "Arco resources for {$config->app}/{$config->environment}",
            'Resources' => [self::IMPORT_PLACEHOLDER => ['Type' => 'AWS::CloudFormation::WaitConditionHandle']],
        ];
    }

    /**
     * The template of an IMPORT change set: the one the stack runs ($deployed, null for a new stack)
     * unchanged, plus each resource of $logicalIds with literal properties only, so that nothing refers to a
     * resource not yet in the stack: a queue's name, FIFO flag and tags (no redrive policy), a bucket's name
     * and tags, a table's whole definition (it has no reference). Types are as in template(), except that the
     * imported resources are always retained (DeletionPolicy and UpdateReplacePolicy Retain). The full template
     * is applied by the update that follows.
     *
     * @param array<string, mixed>|null $deployed
     * @param list<string> $logicalIds queues, dead-letter queues, buckets or table() tables of template($config)
     * @return array<string, mixed>
     */
    public static function importTemplate(AppConfig $config, ?array $deployed, array $logicalIds): array
    {
        $template = $deployed ?? [
            'AWSTemplateFormatVersion' => '2010-09-09',
            'Description' => "Arco resources for {$config->app}/{$config->environment}",
        ];
        $resources = is_array($template['Resources'] ?? null) ? $template['Resources'] : [];
        /** @var array<string, array{Type: string, DeletionPolicy: string, UpdateReplacePolicy: string, Properties: array<string, mixed>}> $full */
        $full = self::template($config)['Resources'];
        $literal = [
            'AWS::SQS::Queue' => ['QueueName', 'FifoQueue', 'Tags'],
            'AWS::S3::Bucket' => ['BucketName', 'Tags'],
        ];
        foreach ($logicalIds as $id) {
            $resource = $full[$id] ?? throw new \InvalidArgumentException("$id is not a resource of the stack");
            if (isset($literal[$resource['Type']])) {
                $resource['Properties'] = array_intersect_key($resource['Properties'], array_flip($literal[$resource['Type']]));
            }
            // Kept whatever happens to the import: a rollback never deletes the data. The update that follows
            // sets the usual policies.
            $resource['DeletionPolicy'] = 'Retain';
            $resource['UpdateReplacePolicy'] = 'Retain';
            $resources[$id] = $resource;
        }
        $template['Resources'] = $resources;
        return $template;
    }

    /**
     * The logical ids whose definition differs between the template the stack runs and the new one:
     * changed, added or removed (a bucket's policy counts for its bucket, a queue's dead-letter queue
     * for the queue). Null when the running template is unknown.
     *
     * @param array<string, mixed>|null $deployed
     * @param array<string, mixed> $new
     * @return array<string, true>|null
     */
    public static function changedResources(?array $deployed, array $new): ?array
    {
        if ($deployed === null) {
            return null;
        }
        $before = is_array($deployed['Resources'] ?? null) ? $deployed['Resources'] : [];
        $after = is_array($new['Resources'] ?? null) ? $new['Resources'] : [];
        $changed = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $id) {
            $id = (string) $id;
            if (self::canonical($before[$id] ?? null) !== self::canonical($after[$id] ?? null)) {
                $changed[$id] = true;
                // The owner of a policy or dead-letter queue: its logical id without the suffix.
                foreach (['Policy', 'Dlq'] as $suffix) {
                    if (str_ends_with($id, $suffix)) {
                        $changed[substr($id, 0, -strlen($suffix))] = true;
                    }
                }
            }
        }
        return $changed;
    }

    /** The definition with object keys sorted, so key order never counts as a change. */
    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $node) use (&$sort): mixed {
            if (! is_array($node)) {
                return $node;
            }
            $node = array_map($sort, $node);
            if (! array_is_list($node)) {
                ksort($node);
            }
            return $node;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $template */
    public static function body(array $template): string
    {
        return json_encode($template, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private static function queue(array $properties): array
    {
        return [
            'Type' => 'AWS::SQS::Queue',
            'DeletionPolicy' => self::DELETION_POLICY,
            'UpdateReplacePolicy' => 'Retain',
            'Properties' => $properties,
        ];
    }

    /**
     * A private bucket (spec §8): every Block Public Access setting on, SSE-S3, a lifecycle rule that aborts
     * incomplete multipart uploads (plus `expire` rules), no CORS without `cors`. Public prefixes relax only
     * the two policy settings their bucket policy needs; ACLs stay blocked.
     *
     * @return array<string, mixed>
     */
    private static function bucket(AppConfig $config, StorageConfig $storage): array
    {
        $public = $storage->public !== [];
        $properties = [
            'BucketName' => $storage->bucketName($config),
            'BucketEncryption' => ['ServerSideEncryptionConfiguration' => [['ServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256']]]],
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true,
                'BlockPublicPolicy' => ! $public,
                'IgnorePublicAcls' => true,
                'RestrictPublicBuckets' => ! $public,
            ],
        ];
        $rules = [];
        foreach ($storage->expire as $prefix => $days) {
            $rules[] = ['Id' => 'arco-expire-' . (count($rules) + 1), 'Prefix' => (string) $prefix, 'ExpirationInDays' => $days, 'Status' => 'Enabled'];
        }
        // Whole bucket: an abandoned multipart upload is invisible to listings but keeps costing storage.
        $rules[] = ['Id' => 'arco-abort-incomplete-uploads', 'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 7], 'Status' => 'Enabled'];
        $properties['LifecycleConfiguration'] = ['Rules' => $rules];
        if ($storage->cors !== []) {
            $properties['CorsConfiguration'] = ['CorsRules' => [[
                'AllowedOrigins' => $storage->cors,
                'AllowedMethods' => ['GET', 'PUT', 'POST', 'HEAD'],
                'AllowedHeaders' => ['*'],
                'MaxAge' => 3000,
            ]]];
        }
        $properties['Tags'] = self::tags($config, "storage:{$storage->name}");
        return [
            'Type' => 'AWS::S3::Bucket',
            'DeletionPolicy' => self::DELETION_POLICY,
            'UpdateReplacePolicy' => 'Retain',
            'Properties' => $properties,
        ];
    }

    /**
     * Anyone may read the objects under each public prefix, and nothing else. Not retained: a bucket
     * that leaves arco.php (or its last public prefix) goes back to private.
     *
     * @return array<string, mixed>
     */
    private static function publicPolicy(AppConfig $config, StorageConfig $storage): array
    {
        $bucket = $storage->bucketName($config);
        $statements = [];
        foreach ($storage->public as $prefix) {
            $statements[] = [
                'Sid' => 'ArcoPublicRead' . (count($statements) + 1),
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                // The partition of the stack's region (aws, aws-cn, aws-us-gov).
                'Resource' => ['Fn::Join' => ['', ['arn:', ['Ref' => 'AWS::Partition'], ":s3:::{$bucket}/{$prefix}*"]]],
            ];
        }
        return [
            'Type' => 'AWS::S3::BucketPolicy',
            'DependsOn' => self::bucketLogicalId($storage->name),
            'Properties' => [
                'Bucket' => $bucket,
                'PolicyDocument' => ['Version' => '2012-10-17', 'Statement' => $statements],
            ],
        ];
    }

    /**
     * An on-demand table with the layout Laravel uses: DynamoDbStore (`key`) for the cache, and
     * DynamoDbFailedJobProvider (`application` + `uuid`) for failed jobs. Both write the expiry
     * (epoch seconds) to `expires_at`, which TTL must name exactly, or items are never deleted.
     *
     * @return array<string, mixed>
     */
    private static function table(AppConfig $config, TableConfig $table): array
    {
        $keys = $table->kind === 'cache' ? ['key' => 'HASH'] : ['application' => 'HASH', 'uuid' => 'RANGE'];
        $attributes = [];
        $schema = [];
        foreach ($keys as $name => $type) {
            $attributes[] = ['AttributeName' => $name, 'AttributeType' => 'S'];
            $schema[] = ['AttributeName' => $name, 'KeyType' => $type];
        }
        return [
            'Type' => 'AWS::DynamoDB::Table',
            'DeletionPolicy' => self::DELETION_POLICY,
            'UpdateReplacePolicy' => 'Retain',
            'Properties' => [
                'TableName' => $table->tableName($config),
                'BillingMode' => 'PAY_PER_REQUEST',
                'AttributeDefinitions' => $attributes,
                'KeySchema' => $schema,
                'TimeToLiveSpecification' => ['AttributeName' => self::TTL_ATTRIBUTE, 'Enabled' => true],
                'Tags' => self::tags($config, $table->kind),
            ],
        ];
    }

    /**
     * A single-table layout: string keys PK (partition) and SK (sort), on demand, retained like every
     * data resource of the stack. TTL only on the attribute table() names.
     *
     * @return array<string, mixed>
     */
    private static function genericTable(AppConfig $config, GenericTableConfig $table): array
    {
        $properties = [
            'TableName' => $table->tableName($config),
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [['AttributeName' => 'PK', 'AttributeType' => 'S'], ['AttributeName' => 'SK', 'AttributeType' => 'S']],
            'KeySchema' => [['AttributeName' => 'PK', 'KeyType' => 'HASH'], ['AttributeName' => 'SK', 'KeyType' => 'RANGE']],
        ];
        if ($table->ttl !== null) {
            $properties['TimeToLiveSpecification'] = ['AttributeName' => $table->ttl, 'Enabled' => true];
        }
        $properties['Tags'] = self::tags($config, "table:{$table->name}");
        return [
            'Type' => 'AWS::DynamoDB::Table',
            'DeletionPolicy' => self::DELETION_POLICY,
            'UpdateReplacePolicy' => 'Retain',
            'Properties' => $properties,
        ];
    }

    /** @return list<array{Key: string, Value: string}> */
    private static function tags(AppConfig $config, string $resource): array
    {
        return [
            ['Key' => 'arco:app', 'Value' => $config->app],
            ['Key' => 'arco:environment', 'Value' => $config->environment],
            ['Key' => 'arco:resource', 'Value' => $resource],
        ];
    }
}
