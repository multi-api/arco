<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

final readonly class VerificationResult
{
    /**
     * @param list<string> $logTail
     * @param string|null $reason translation key explaining a failure the other fields do not
     * @param array<string, string|int> $reasonParams
     */
    public function __construct(
        public bool $ok,
        public ?int $httpStatus,
        public ?string $httpError,
        public ?string $initStatus,
        public ?string $initErrorType,
        public ?int $initDurationMs,
        public array $logTail,
        public ?string $reason = null,
        public array $reasonParams = [],
    ) {
    }
}
