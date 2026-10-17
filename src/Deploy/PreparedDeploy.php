<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

/** A deploy whose code is in place (web revision without traffic, console version published). */
final readonly class PreparedDeploy
{
    public function __construct(
        public string $revisionId,
        public ?string $previousRevisionId,
        public string $bucket,
        public string $key,
        /** console version published for this deploy; null when the console function was skipped */
        public ?string $consoleVersion,
        /** version the live alias pointed to before this deploy */
        public ?string $previousConsoleVersion,
        /** S3 key of the console zip, kept by the prune */
        public ?string $consoleKey = null,
        /** runtime binary the console version of this deploy downloads; never pruned by this deploy */
        public ?string $binaryKey = null,
        /** @var array<string, array{version: string, previous: ?string}> worker version published for each queue, and the one its live alias pointed to before */
        public array $workerVersions = [],
    ) {
    }
}
