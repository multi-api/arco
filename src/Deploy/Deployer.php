<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Package\Package;

interface Deployer
{
    /**
     * @param array<string, string> $variables final revision environment variables
     * @param (callable(string, array<string, string|int>): void)|null $onStep
     */
    public function deploy(AppConfig $config, Package $package, array $variables, ?callable $onStep = null): DeployResult;

    public function rollback(AppConfig $config, string $revisionId): void;

    /**
     * Puts the deployed endpoint behind the configured custom domains (certificate, CloudFront, DNS).
     *
     * @param list<string> $cachedPaths path patterns CloudFront may cache
     * @param (callable(string, array<string, string|int>): void)|null $onStep
     */
    public function attachDomains(AppConfig $config, DeployResult $result, array $cachedPaths, ?callable $onStep = null): DeployResult;

    /** Whether custom-domain resources (the CloudFront stack, a managed certificate) exist for the environment. */
    public function hasDomainResources(AppConfig $config): bool;

    /** @param (callable(string, array<string, string|int>): void)|null $onStep */
    public function remove(AppConfig $config, ?callable $onStep = null): void;

    public function describe(AppConfig $config): ?AppStatus;
}
