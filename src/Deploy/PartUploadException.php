<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Aws\Multipart\UploadState;
use Aws\S3\Exception\S3MultipartUploadException;

/**
 * The SDK's multipart exception folds each part's error into its message only; this one keeps them so
 * CodeBucket can tell a network blip from a real failure.
 *
 * @internal
 */
final class PartUploadException extends S3MultipartUploadException
{
    /** @var array<int, AwsException> part number => error; empty when the initiate or complete call failed */
    public readonly array $partErrors;
    public readonly ?AwsException $error;

    /** @param AwsException|array<int, AwsException>|null $prev */
    public function __construct(UploadState $state, $prev = null)
    {
        parent::__construct($state, $prev);
        $this->partErrors = is_array($prev) ? $prev : [];
        $this->error = $prev instanceof AwsException ? $prev : null;
    }
}
