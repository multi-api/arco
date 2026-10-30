<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Aws\Exception\AwsException;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * SQS queues, with the dead-letter queue they redrive to and a warning on the ones that are some other queue's DLQ.
 * A queue deleted between ListQueues and the calls about it is left out.
 */
final class QueueLister implements Lister
{
    /** The codes SQS gives for a queue that no longer exists (the query and the JSON protocol). */
    private const GONE = ['AWS.SimpleQueueService.NonExistentQueue', 'QueueDoesNotExist'];

    public function type(): ResourceType
    {
        return ResourceType::Queue;
    }

    public function list(AwsClients $aws): array
    {
        $sqs = $aws->sqs();
        $items = [];
        foreach ($this->urls($sqs) as $url) {
            try {
                $attributes = Aws::map(Aws::call($sqs, 'GetQueueAttributes', ['QueueUrl' => $url, 'AttributeNames' => ['All']])['Attributes'] ?? []);
                $listed = Aws::map(Aws::call($sqs, 'ListQueueTags', ['QueueUrl' => $url])['Tags'] ?? []);
            } catch (AwsException $e) {
                if (in_array($e->getAwsErrorCode(), self::GONE, true)) {
                    continue; // deleted while listing
                }
                throw $e;
            }
            $tags = [];
            foreach ($listed as $key => $value) {
                if (is_string($value)) {
                    $tags[$key] = $value;
                }
            }
            $items[] = new ResourceItem(
                ResourceType::Queue,
                $url,
                substr($url, (int) strrpos($url, '/') + 1),
                $tags,
                [
                    'url' => $url,
                    'arn' => Aws::string($attributes['QueueArn'] ?? null),
                    'fifo' => ($attributes['FifoQueue'] ?? null) === 'true',
                    'visibilityTimeout' => is_numeric($attributes['VisibilityTimeout'] ?? null) ? (int) $attributes['VisibilityTimeout'] : null,
                    'dlq' => self::dlq($attributes['RedrivePolicy'] ?? null),
                ],
            );
        }

        $targets = [];
        foreach ($items as $item) {
            if (is_string($item->details['dlq'])) {
                $targets[$item->details['dlq']] = true;
            }
        }
        foreach ($items as $item) {
            if (is_string($item->details['arn']) && isset($targets[$item->details['arn']])) {
                $item->warnings[] = ['key' => 'resources.warning.queue_is_dlq', 'params' => []];
            }
        }
        usort($items, fn (ResourceItem $a, ResourceItem $b) => $a->id <=> $b->id);
        return $items;
    }

    /** @return list<string> */
    private function urls(\Aws\Sqs\SqsClient $sqs): array
    {
        $urls = [];
        $token = null;
        do {
            $page = Aws::call($sqs, 'ListQueues', ['MaxResults' => 1000, ...($token === null ? [] : ['NextToken' => $token])]);
            $urls = [...$urls, ...Aws::strings($page['QueueUrls'] ?? [])];
            $token = Aws::string($page['NextToken'] ?? null);
        } while ($token !== null);
        return $urls;
    }

    /** The deadLetterTargetArn of a RedrivePolicy (a JSON string), if any. */
    private static function dlq(mixed $policy): ?string
    {
        $decoded = is_string($policy) ? json_decode($policy, true) : null;
        return is_array($decoded) ? Aws::string($decoded['deadLetterTargetArn'] ?? null) : null;
    }
}
