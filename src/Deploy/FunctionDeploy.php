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
        /** the Lambda runtime a new function is created with: provided.al2023 (PHP) or nodejs24.x (process apps) */
        public string $runtime = LambdaFunction::RUNTIME,
    ) {
    }
}
