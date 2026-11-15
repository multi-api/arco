<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

final readonly class DeployOutcome
{
    public function __construct(
        public DeployResult $result,
        public VerificationResult $verification,
        public bool $rolledBack,
        public int $packageBytes,
        /** @var list<string> deploy commands that ran (their changes are never undone) */
        public array $commandsRan = [],
        /** where to read the cold start later, when ARCO_DEFER_COLD_START skipped the lookup */
        public ?ColdStartLookup $coldStartLookup = null,
    ) {
    }
}
