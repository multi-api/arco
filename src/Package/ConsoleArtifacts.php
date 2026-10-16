<?php declare(strict_types=1);

namespace Multiapi\Arco\Package;

/** What the console function needs: its zip, and the binary it downloads from S3 at cold start, if any. */
final readonly class ConsoleArtifacts
{
    public function __construct(
        public Package $package,
        /** null when the zip carries bin/php */
        public ?ConsoleDownload $download,
    ) {
    }
}
