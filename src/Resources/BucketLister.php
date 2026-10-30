<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Aws\Exception\AwsException;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * S3 buckets. ListBuckets is account-wide, so a bucket of another region is hidden (and its tags are not read, to
 * avoid cross-region redirects); a bucket whose location or tags cannot be read stays in the list without them, and
 * one whose location cannot be read says so (it may be in another region).
 */
final class BucketLister implements Lister
{
    public function type(): ResourceType
    {
        return ResourceType::Bucket;
    }

    public function list(AwsClients $aws): array
    {
        $s3 = $aws->s3();
        $items = [];
        foreach ($this->buckets($s3) as $bucket) {
            $name = Aws::string($bucket['Name'] ?? null);
            if ($name === null) {
                continue;
            }
            $region = $this->region($s3, $name);
            $other = $region !== null && $region !== $aws->region;
            $warnings = match (true) {
                $other => [['key' => 'resources.warning.bucket_other_region', 'params' => ['region' => $region]]],
                $region === null => [['key' => 'resources.warning.bucket_region_unknown', 'params' => []]],
                default => [],
            };
            $items[] = new ResourceItem(
                ResourceType::Bucket,
                $name,
                $name,
                $other ? [] : $this->tags($s3, $name),
                ['region' => $region, 'created' => self::created($bucket['CreationDate'] ?? null)],
                $warnings,
                $other,
            );
        }
        usort($items, fn (ResourceItem $a, ResourceItem $b) => $a->id <=> $b->id);
        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function buckets(\Aws\S3\S3Client $s3): array
    {
        $buckets = [];
        $token = null;
        do {
            $page = Aws::call($s3, 'ListBuckets', $token === null ? [] : ['ContinuationToken' => $token]);
            $buckets = [...$buckets, ...Aws::maps($page['Buckets'] ?? [])];
            $token = Aws::string($page['ContinuationToken'] ?? null);
        } while ($token !== null);
        return $buckets;
    }

    /** The bucket's region; null when GetBucketLocation is denied. A null/empty constraint is us-east-1, "EU" is eu-west-1. */
    private function region(\Aws\S3\S3Client $s3, string $name): ?string
    {
        try {
            $constraint = Aws::string(Aws::call($s3, 'GetBucketLocation', ['Bucket' => $name])['LocationConstraint'] ?? null);
        } catch (AwsException) {
            return null;
        }
        return match ($constraint) {
            null => 'us-east-1',
            'EU' => 'eu-west-1',
            default => $constraint,
        };
    }

    /** @return array<string, string> empty for a bucket without tags (NoSuchTagSet) or when reading them is denied */
    private function tags(\Aws\S3\S3Client $s3, string $name): array
    {
        try {
            return Tags::map(Aws::call($s3, 'GetBucketTagging', ['Bucket' => $name])['TagSet'] ?? []);
        } catch (AwsException) {
            return [];
        }
    }

    private static function created(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format(\DATE_ATOM);
        }
        return Aws::string($date);
    }
}
