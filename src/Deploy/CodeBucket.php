<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\ResultInterface;
use Aws\S3\Exception\S3Exception;
use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;

final class CodeBucket
{
    public function __construct(private readonly S3Client $s3)
    {
    }

    public static function name(string $account, string $region): string
    {
        return "arco-$account-$region";
    }

    public function ensure(string $bucket, string $region, string $account): void
    {
        try {
            $this->s3->headBucket(['Bucket' => $bucket]);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 403) {
                throw DeployException::make('deploy.bucket_forbidden', ['bucket' => $bucket], $e);
            }
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
            $create = ['Bucket' => $bucket];
            if ($region !== 'us-east-1') {
                $create['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
            }
            $this->s3->execute($this->s3->getCommand('CreateBucket', $create));
        }

        // Idempotent; applied on every call so a half-configured bucket heals itself.
        $this->s3->putPublicAccessBlock([
            'Bucket' => $bucket,
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true,
                'IgnorePublicAcls' => true,
                'BlockPublicPolicy' => true,
                'RestrictPublicBuckets' => true,
            ],
        ]);
        // Web Functions read the package with the Lambda service principal, not the execution role.
        $this->s3->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode(self::policy($bucket, $account), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        $this->s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
        $this->s3->putBucketLifecycleConfiguration([
            'Bucket' => $bucket,
            'LifecycleConfiguration' => ['Rules' => [[
                'ID' => 'arco-expire-noncurrent',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'NoncurrentVersionExpiration' => ['NoncurrentDays' => 30],
                'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 1],
            ]]],
        ]);
    }

    /**
     * Same policy the AWS CLI applies to its own Web Function code bucket: HTTPS only, and read access
     * for the Lambda service principal scoped to this account.
     *
     * @return array<string, mixed>
     */
    public static function policy(string $bucket, string $account): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Sid' => 'HttpsOnly',
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:*',
                    'Resource' => ["arn:aws:s3:::$bucket", "arn:aws:s3:::$bucket/*"],
                    'Condition' => ['Bool' => ['aws:SecureTransport' => 'false']],
                ],
                [
                    'Sid' => 'AllowLambdaServiceAccess',
                    'Effect' => 'Allow',
                    'Principal' => ['Service' => 'lambda.amazonaws.com'],
                    'Action' => ['s3:GetObject', 's3:GetObjectVersion'],
                    'Resource' => "arn:aws:s3:::$bucket/*",
                    'Condition' => ['StringEquals' => ['aws:SourceAccount' => $account]],
                ],
            ],
        ];
    }

    public function upload(string $bucket, string $key, string $path): ?string
    {
        try {
            $head = $this->s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
            return self::version($head['VersionId'] ?? null);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
        return $this->put($bucket, $key, $path);
    }

    /**
     * Uploads a runtime binary (runtime/<build>/frankenphp) unless an object of the same size is
     * already there: builds are immutable, so the size check only guards against a broken upload.
     * No version id is returned: the key is immutable and the console checks the bytes against
     * their SHA-256, so the bootstrap never pins an S3 version (noncurrent versions expire).
     */
    public function uploadBinary(string $bucket, string $key, string $path, int $bytes): void
    {
        try {
            $head = $this->s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
            if (is_numeric($head['ContentLength'] ?? null) && (int) $head['ContentLength'] === $bytes) {
                return;
            }
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
        $this->put($bucket, $key, $path);
    }

    private function put(string $bucket, string $key, string $path): ?string
    {
        $body = fopen($path, 'rb');
        if ($body === false) {
            throw DeployException::make('deploy.read_package_failed', ['path' => $path]);
        }
        try {
            $result = (new ObjectUploader($this->s3, $bucket, $key, $body, 'private'))->upload();
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
        return self::version($result instanceof ResultInterface ? $result->get('VersionId') : null);
    }

    /**
     * Deletes all but the $keep newest objects under $prefix, never one of $protectedKeys.
     *
     * @param list<string> $protectedKeys
     * @param string|null $ignorePrefix objects under it are left alone and not counted (pruned on their own)
     */
    public function prune(string $bucket, string $prefix, int $keep, array $protectedKeys, ?string $ignorePrefix = null): int
    {
        $objects = $this->s3->listObjectsV2(['Bucket' => $bucket, 'Prefix' => $prefix])['Contents'] ?? [];
        /** @var list<array{Key: string, LastModified: \DateTimeInterface}> $objects */
        if ($ignorePrefix !== null) {
            $objects = array_values(array_filter($objects, fn (array $object) => ! str_starts_with($object['Key'], $ignorePrefix)));
        }
        usort($objects, fn (array $a, array $b) => $b['LastModified'] <=> $a['LastModified']);

        $delete = [];
        foreach (array_slice($objects, $keep) as $object) {
            if (! in_array($object['Key'], $protectedKeys, true)) {
                $delete[] = ['Key' => $object['Key']];
            }
        }
        if ($delete !== []) {
            $this->s3->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $delete, 'Quiet' => true]]);
        }
        return count($delete);
    }

    private static function version(mixed $version): ?string
    {
        return is_string($version) && $version !== '' && $version !== 'null' ? $version : null;
    }
}
