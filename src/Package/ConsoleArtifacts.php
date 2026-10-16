<?php declare(strict_types=1);

namespace Multiapi\Arco\Package;

/** What the console function needs: its zip, and the FrankenPHP binary it downloads from S3 at cold start. */
final readonly class ConsoleArtifacts
{
    public function __construct(
        public Package $package,
        public string $binaryPath,
        public string $binarySha256,
        public int $binaryBytes,
        /** S3 key in the code bucket: runtime/<build-id>/frankenphp or runtime/custom/<sha256>/frankenphp */
        public string $binaryKey,
    ) {
    }
}
