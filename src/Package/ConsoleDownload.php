<?php declare(strict_types=1);

namespace Multiapi\Arco\Package;

/** The FrankenPHP binary the console bootstrap downloads from S3 at cold start, when the zip carries no PHP CLI. */
final readonly class ConsoleDownload
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $bytes,
        /** S3 key in the code bucket: runtime/<build-id>/frankenphp or runtime/custom/<sha256>/frankenphp */
        public string $key,
    ) {
    }
}
