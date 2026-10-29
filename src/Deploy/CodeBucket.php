<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Aws\ResultInterface;
use Aws\S3\Exception\S3Exception;
use Aws\S3\MultipartUploader;
use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;

final class CodeBucket
{
    /** Seconds before the one retry of a failed upload: the SDK's own quick retries have already failed. */
    public const RETRY_DELAY = 5;
    // 'private' is rejected by S3-compatible emulators (Floci); this ACL is valid on AWS too.
    private const ACL = 'bucket-owner-full-control';

    public function __construct(private readonly S3Client $s3, private readonly Sleeper $sleeper = new Sleeper())
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

    /**
     * @param (callable(string, array<string, string|int>): void)|null $onStep receives deploy.upload_retry
     * @param (callable(int, int): void)|null $onProgress receives (bytes sent, file size) while the file is sent
     */
    public function upload(string $bucket, string $key, string $path, ?callable $onStep = null, ?callable $onProgress = null): ?string
    {
        try {
            $head = $this->s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
            return self::version($head['VersionId'] ?? null);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
        return $this->put($bucket, $key, $path, $onStep, $onProgress);
    }

    /**
     * Uploads a runtime binary (runtime/<build>/frankenphp) unless an object of the same size is
     * already there: builds are immutable, so the size check only guards against a broken upload.
     * A binary already there is copied onto itself so its LastModified restarts the 24 h grace period of
     * RuntimeBinaries::prune(): another app's deploy may prune before this deploy publishes the version
     * that references it. No version id is returned: the key is immutable and the console checks the bytes against
     * their SHA-256, so the bootstrap never pins an S3 version (noncurrent versions expire).
     *
     * @param (callable(string, array<string, string|int>): void)|null $onStep receives deploy.upload_retry
     * @param (callable(int, int): void)|null $onProgress receives (bytes sent, file size) while the file is sent
     */
    public function uploadBinary(string $bucket, string $key, string $path, int $bytes, ?callable $onStep = null, ?callable $onProgress = null): void
    {
        try {
            $head = $this->s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
            if (is_numeric($head['ContentLength'] ?? null) && (int) $head['ContentLength'] === $bytes) {
                $copy = ['Bucket' => $bucket, 'Key' => $key, 'CopySource' => $bucket . '/' . str_replace('%2F', '/', rawurlencode($key)), 'MetadataDirective' => 'REPLACE'];
                if (is_string($head['ContentType'] ?? null) && $head['ContentType'] !== '') {
                    $copy['ContentType'] = $head['ContentType'];
                }
                $metadata = [];
                foreach (is_array($head['Metadata'] ?? null) ? $head['Metadata'] : [] as $name => $value) {
                    if (is_string($value)) {
                        $metadata[(string) $name] = $value;
                    }
                }
                if ($metadata !== []) {
                    $copy['Metadata'] = $metadata;
                }
                $this->s3->copyObject($copy);
                return;
            }
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
        $this->put($bucket, $key, $path, $onStep, $onProgress);
    }

    /**
     * A failure the network or S3 caused (connection reset, timeout, 5xx) is retried once, after the
     * SDK's own retries: a multipart upload resumes with the parts already uploaded. A multipart upload
     * that still fails is aborted so no parts are left behind. Progress counts the parts uploaded before
     * a retry: the retry does not send them again.
     *
     * @param (callable(string, array<string, string|int>): void)|null $onStep
     * @param (callable(int, int): void)|null $onProgress
     */
    private function put(string $bucket, string $key, string $path, ?callable $onStep, ?callable $onProgress = null): ?string
    {
        $body = fopen($path, 'rb');
        if ($body === false) {
            throw DeployException::make('deploy.read_package_failed', ['path' => $path]);
        }
        $stat = fstat($body);
        $progress = $onProgress === null ? null : new UploadProgress($stat === false ? 0 : $stat['size'], $onProgress(...));
        $options = ['exception_class' => PartUploadException::class, 'before_upload' => $progress === null ? null : $progress->attach(...)];
        try {
            try {
                $result = $this->send($bucket, $key, $body, $options);
            } catch (AwsException|PartUploadException $e) {
                $reason = self::transientReason($e);
                if ($reason === null) {
                    $this->abort($e);
                    throw $e;
                }
                if ($onStep !== null) {
                    $onStep('deploy.upload_retry', ['key' => $key, 'error' => $reason, 'seconds' => self::RETRY_DELAY]);
                }
                $this->sleeper->sleep(self::RETRY_DELAY);
                rewind($body);
                try {
                    $result = $e instanceof PartUploadException && $e->getState()->isInitiated()
                        ? (new MultipartUploader($this->s3, $body, ['state' => $e->getState(), 'acl' => self::ACL, ...$options]))->upload()
                        : $this->send($bucket, $key, $body, $options);
                } catch (PartUploadException $again) {
                    $this->abort($again);
                    throw $again;
                }
            }
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
        $progress?->finish();
        return self::version($result instanceof ResultInterface ? $result->get('VersionId') : null);
    }

    /**
     * @param resource $body
     * @param array<string, mixed> $options
     */
    private function send(string $bucket, string $key, $body, array $options): mixed
    {
        return (new ObjectUploader($this->s3, $bucket, $key, $body, self::ACL, $options))->upload();
    }

    /** What went wrong, when every error is one a retry can fix; null otherwise. */
    private static function transientReason(AwsException|PartUploadException $e): ?string
    {
        $errors = $e instanceof AwsException ? [$e] : ($e->partErrors !== [] ? $e->partErrors : array_filter([$e->error]));
        if ($errors === []) {
            return null;
        }
        $reasons = [];
        foreach ($errors as $part => $error) {
            $transient = $error->isConnectionError()
                || ($error->getStatusCode() ?? 0) >= 500
                || in_array($error->getAwsErrorCode(), ['RequestTimeout', 'SlowDown'], true);
            if (! $transient) {
                return null;
            }
            $message = $error->getAwsErrorMessage();
            $message = is_string($message) && $message !== '' ? $message : $error->getMessage();
            $reasons[] = ($e instanceof PartUploadException && $e->partErrors !== [] ? "part $part: " : '') . $message;
        }
        return implode('; ', $reasons);
    }

    /** Best effort: the bucket's lifecycle rule aborts what is left after a day anyway. */
    private function abort(\Throwable $e): void
    {
        if (! $e instanceof PartUploadException) {
            return;
        }
        $state = $e->getState();
        if (! $state->isInitiated() || $state->isCompleted()) {
            return;
        }
        /** @var array{Bucket: string, Key: string, UploadId: string} $id */
        $id = $state->getId();
        try {
            $this->s3->abortMultipartUpload($id);
        } catch (AwsException) {
        }
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
