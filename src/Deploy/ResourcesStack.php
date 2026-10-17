<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\QueueConfig;

/**
 * The CloudFormation template of an environment's AWS resources other than functions: the SQS
 * queues Arco creates and their dead-letter queues. Every queue is retained (DeletionPolicy and
 * UpdateReplacePolicy Retain): removing it from arco.php, or deleting the stack, never deletes its
 * messages. Queues referenced with `existing` are not in the template.
 */
final class ResourcesStack
{
    public const DLQ_RETENTION = 1209600;
    /** SQS keeps a message hidden this many times the worker timeout once a worker receives it. */
    public const VISIBILITY_FACTOR = 6;
    /** Receives a message gets before it goes to the dead-letter queue, besides the worker's tries. */
    public const EXTRA_RECEIVES = 2;

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

    /** @return list<QueueConfig> the queues Arco creates (not `existing` ones) */
    public static function createdQueues(AppConfig $config): array
    {
        return array_values(array_filter($config->queues, static fn (QueueConfig $queue): bool => $queue->existing === null));
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

        return [
            'AWSTemplateFormatVersion' => '2010-09-09',
            'Description' => "Arco resources for {$config->app}/{$config->environment}",
            'Resources' => $resources,
            'Outputs' => $outputs,
        ];
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
            'DeletionPolicy' => 'Retain',
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
