<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Config\AppConfig;

interface Verifier
{
    /** @param (callable(string, array<string, string|int>): void)|null $onStep */
    public function verify(AppConfig $config, DeployResult $result, string $healthPath, int $sinceMs, ?callable $onStep = null): VerificationResult;

    /**
     * Adds the cold start to a passed verification that lacks it, at the very end of the deploy.
     * Never throws for log access problems: the cold start then stays unknown.
     *
     * @param (callable(string, array<string, string|int>): void)|null $onStep
     */
    public function coldStart(AppConfig $config, DeployResult $result, int $sinceMs, VerificationResult $verification, ?callable $onStep = null): VerificationResult;
}
