<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Multiapi\Arco\Deploy\Domains\Aws;

/** S3 lookups around the buckets of the resources stack. */
final class Buckets
{
    public function __construct(private readonly S3Client $s3)
    {
    }

    /**
     * Who holds a bucket name. S3 names are global: 200 means this account can read the bucket
     * ("ours", e.g. one kept when its storage left arco.php); 403 means another account has the name
     * (or this profile lacks s3:ListBucket on it: HeadBucket answers both with a bare 403), and 301 or
     * 400 that a bucket with the name lives in another region (a redirect, or a request signed for the
     * wrong region): either way CloudFormation could not create it here ("taken"). 404: "free".
     * Other errors are not guessed at.
     *
     * @return 'ours'|'taken'|'free'
     */
    public function exists(string $name): string
    {
        try {
            Aws::call($this->s3, 'HeadBucket', ['Bucket' => $name]);
            return 'ours';
        } catch (AwsException $e) {
            return match ($e->getStatusCode()) {
                404 => 'free',
                301, 400, 403 => 'taken',
                default => throw $e,
            };
        }
    }

    /**
     * The tags of the bucket. Throws on any failure, a bucket without tags included (NoSuchTagSet).
     *
     * @return array<string, string>
     */
    public function tags(string $bucket): array
    {
        return Aws::tagMap(Aws::call($this->s3, 'GetBucketTagging', ['Bucket' => $bucket])['TagSet'] ?? []);
    }

    /** Whether the bucket holds any object, or null when it cannot be read: only ever shown in warnings. */
    public function hasObjects(string $name): ?bool
    {
        try {
            $count = Aws::call($this->s3, 'ListObjectsV2', ['Bucket' => $name, 'MaxKeys' => 1])['KeyCount'] ?? null;
        } catch (AwsException) {
            return null;
        }
        return is_numeric($count) ? (int) $count > 0 : null;
    }
}
