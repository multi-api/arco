<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Aws\Lambda\LambdaClient;
use Aws\Sqs\SqsClient;
use Multiapi\Arco\Deploy\Domains\Aws;

/** SQS lookups around the queues of the resources stack. */
final class Queues
{
    private const MISSING = ['AWS.SimpleQueueService.NonExistentQueue', 'QueueDoesNotExist'];

    public function __construct(private readonly SqsClient $sqs, private readonly LambdaClient $lambda)
    {
    }

    /** The URL of the queue with this name in the account and region, or null when there is none. */
    public function url(string $name): ?string
    {
        try {
            return Aws::string(Aws::call($this->sqs, 'GetQueueUrl', ['QueueName' => $name])['QueueUrl'] ?? null);
        } catch (AwsException $e) {
            if (in_array($e->getAwsErrorCode(), self::MISSING, true)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * The tags of the queue. Throws on any failure (e.g. a profile without sqs:ListQueueTags).
     *
     * @return array<string, string>
     */
    public function tags(string $url): array
    {
        $tags = [];
        foreach (Aws::map(Aws::call($this->sqs, 'ListQueueTags', ['QueueUrl' => $url])['Tags'] ?? null) as $key => $value) {
            if (is_string($value)) {
                $tags[(string) $key] = $value;
            }
        }
        return $tags;
    }

    /** Whether the error says the queue does not exist. */
    public static function missing(AwsException $e): bool
    {
        return in_array($e->getAwsErrorCode(), self::MISSING, true);
    }

    /**
     * The approximate message counts of the queue, by attribute name (ApproximateNumberOfMessages,
     * ApproximateNumberOfMessagesNotVisible). Throws, e.g. when the queue does not exist (see missing()).
     *
     * @param list<string> $names
     * @return array<string, int>
     */
    public function counts(string $url, array $names): array
    {
        $attributes = Aws::map(Aws::call($this->sqs, 'GetQueueAttributes', ['QueueUrl' => $url, 'AttributeNames' => $names])['Attributes'] ?? null);
        $counts = [];
        foreach ($names as $name) {
            $value = $attributes[$name] ?? null;
            $counts[$name] = is_numeric($value) ? (int) $value : 0;
        }
        return $counts;
    }

    /**
     * Up to $max messages, read without consuming them: VisibilityTimeout 0 leaves them visible at once
     * (their receive count still goes up).
     *
     * @return list<array{id: string, body: string, receives: ?int, sent: ?int}> sent in milliseconds
     */
    public function peek(string $url, int $max = 10): array
    {
        $result = Aws::call($this->sqs, 'ReceiveMessage', [
            'QueueUrl' => $url,
            'MaxNumberOfMessages' => $max,
            'VisibilityTimeout' => 0,
            'WaitTimeSeconds' => 0,
            'MessageSystemAttributeNames' => ['ApproximateReceiveCount', 'SentTimestamp'],
        ]);
        $messages = [];
        foreach (Aws::maps($result['Messages'] ?? []) as $message) {
            $attributes = Aws::map($message['Attributes'] ?? null);
            $receives = $attributes['ApproximateReceiveCount'] ?? null;
            $sent = $attributes['SentTimestamp'] ?? null;
            $messages[] = [
                'id' => Aws::string($message['MessageId'] ?? null) ?? '-',
                'body' => Aws::string($message['Body'] ?? null) ?? '',
                'receives' => is_numeric($receives) ? (int) $receives : null,
                'sent' => is_numeric($sent) ? (int) $sent : null,
            ];
        }
        return $messages;
    }

    /** Starts moving the messages of a dead-letter queue back to its source queue; returns the task handle. */
    public function redrive(string $dlqArn, string $queueArn): string
    {
        $result = Aws::call($this->sqs, 'StartMessageMoveTask', ['SourceArn' => $dlqArn, 'DestinationArn' => $queueArn]);
        return Aws::string($result['TaskHandle'] ?? null) ?? '-';
    }

    /**
     * The approximate number of visible messages, or null when it cannot be read (the queue is gone,
     * or the profile may not read it): only ever shown in warnings.
     */
    public function messageCount(string $url): ?int
    {
        try {
            $attributes = Aws::map(Aws::call($this->sqs, 'GetQueueAttributes', ['QueueUrl' => $url, 'AttributeNames' => ['ApproximateNumberOfMessages']])['Attributes'] ?? null);
        } catch (AwsException) {
            return null;
        }
        $count = $attributes['ApproximateNumberOfMessages'] ?? null;
        return is_numeric($count) ? (int) $count : null;
    }

    /** @return list<string> ARNs of the functions with an event source mapping on the queue */
    public function consumers(string $arn): array
    {
        $functions = [];
        $marker = null;
        do {
            $page = Aws::call($this->lambda, 'ListEventSourceMappings', ['EventSourceArn' => $arn] + ($marker === null ? [] : ['Marker' => $marker]));
            foreach (Aws::maps($page['EventSourceMappings'] ?? []) as $mapping) {
                $function = Aws::string($mapping['FunctionArn'] ?? null);
                if ($function !== null) {
                    $functions[] = $function;
                }
            }
            $marker = Aws::string($page['NextMarker'] ?? null);
        } while ($marker !== null);
        return $functions;
    }
}
