<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

/** One classic function for LambdaFunction::deployAll(): see LambdaFunction::deploy() for the fields. */
final readonly class FunctionDeploy
{
    /**
     * @param array{S3Bucket: string, S3Key: string, S3ObjectVersion?: string} $code
     * @param array<string, mixed> $settings
     * @param array<string, string> $tags
     */
    public function __construct(
        public LambdaFunction $function,
        public string $name,
        public string $handler,
        public array $code,
        public array $settings,
        public array $tags,
        public bool $roleJustCreated,
    ) {
    }
}
