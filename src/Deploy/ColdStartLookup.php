<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

/**
 * Where a deferred cold start can be read later (ARCO_DEFER_COLD_START): the revision's
 * `platform.initReport` is in $logGroup, in streams starting with one of $streamPrefixes, from $sinceMs on.
 */
final readonly class ColdStartLookup
{
    /** @param list<string> $streamPrefixes */
    public function __construct(
        public string $logGroup,
        public int $sinceMs,
        public array $streamPrefixes,
    ) {
    }

    /** @return array{log_group: string, since_ms: int, stream_prefixes: list<string>} */
    public function toArray(): array
    {
        return ['log_group' => $this->logGroup, 'since_ms' => $this->sinceMs, 'stream_prefixes' => $this->streamPrefixes];
    }
}
