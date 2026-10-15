<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

final readonly class AppStatus
{
    public function __construct(
        public string $functionName,
        public string $state,
        public ?string $domainName,
        public ?string $activeRevisionId,
        public ?string $distributionDomain = null,
        public ?string $distributionStatus = null,
    ) {
    }
}
