<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

final readonly class RuntimeConfig
{
    /** @param array<string, string|int|bool> $phpIni */
    public function __construct(
        public ?string $build,
        public ?string $frankenphp,
        public ?string $binary,
        public int $workersMin,
        public int $workersMax,
        public int $threads,
        public string $mode,
        public array $phpIni,
    ) {
    }
}
