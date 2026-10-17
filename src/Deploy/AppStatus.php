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
        public ?string $consoleFunction = null,
        /** version the console's live alias points to; null when not deployed or unreadable (see consoleVersionError) */
        public ?string $consoleVersion = null,
        /** AWS error code when the live version could not be read (e.g. AccessDeniedException): unknown, not missing */
        public ?string $consoleVersionError = null,
        /** whether the scheduler rule is disabled (arco scheduler:pause); null without a scheduler, a rule, or EventBridge read access */
        public ?bool $schedulerPaused = null,
    ) {
    }
}
