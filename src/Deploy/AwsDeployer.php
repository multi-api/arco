<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Arco;
use Multiapi\Arco\Aws\AwsClients;
use Aws\Exception\AwsException;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Domains\Certificates;
use Multiapi\Arco\Deploy\Domains\CustomDomains;
use Multiapi\Arco\Deploy\Domains\DomainStack;
use Multiapi\Arco\Deploy\Domains\Stacks;
use Multiapi\Arco\Package\Package;
use Multiapi\Arco\Secrets\SecretResolver;

final class AwsDeployer implements Deployer
{
    private const KEEP_PACKAGES = 20;

    /**
     * @param AwsClients|null $dns clients for the account holding the DNS zones (dns() profile or
     *                             --dns-profile); null for the deploy account
     * @param bool $forceDns replace existing DNS records that point elsewhere (--force-dns)
     */
    public function __construct(
        private readonly AwsClients $aws,
        private readonly Sleeper $sleeper = new Sleeper(),
        private readonly ?AwsClients $dns = null,
        private readonly bool $forceDns = false,
    ) {
    }

    public function deploy(AppConfig $config, Package $package, array $variables, ?callable $onStep = null): DeployResult
    {
        $step = $onStep ?? static function (string $key, array $params = []): void {};
        $function = $config->functionName();
        $account = $this->aws->accountId();
        $region = (string) $config->region;
        $web = new WebFunctions($this->aws->lambdaWeb(), $this->sleeper);
        $bucket = CodeBucket::name($account, $region);
        $codeBucket = new CodeBucket($this->aws->s3());

        $step('deploy.step_bucket', ['bucket' => $bucket]);
        $codeBucket->ensure($bucket, $region, $account);

        $key = "{$config->app}/{$config->environment}/{$package->hash}.zip";
        $step('deploy.step_upload', ['size' => (int) round($package->bytes / 1048576)]);
        $versionId = $codeBucket->upload($bucket, $key, $package->path);

        $tags = self::tags($config);
        $roleCreated = false;
        if ($config->role !== null) {
            $roleArn = $config->role;
        } else {
            $step('deploy.step_role', ['role' => ExecutionRole::roleName($config)]);
            $policy = PolicyBuilder::document($region, $account, $function, SecretResolver::references($variables), $config->permissions);
            ['arn' => $roleArn, 'created' => $roleCreated] = (new ExecutionRole($this->aws->iam()))->ensure(ExecutionRole::roleName($config), $policy, $tags);
        }

        $revision = RevisionFactory::revision($config, $bucket, $key, $versionId, $roleArn, $variables);
        $previous = null;
        if ($web->get($function) === null) {
            $step('deploy.step_function', ['function' => $function]);
            $revisionId = $web->create($function, $revision, RevisionFactory::initialEndpoint($config), $tags, $roleCreated);
        } else {
            $endpoint = $web->endpoint($function);
            $previous = $endpoint === null ? null : WebFunctions::activeRevision($endpoint);
            $step('deploy.step_revision', ['function' => $function]);
            $revisionId = $web->createRevision($function, $revision, $roleCreated);
        }
        $web->waitRevision($function, $revisionId);

        $step('deploy.step_traffic', ['revision' => $revisionId]);
        $web->waitEndpoint($function);
        $web->route($function, $revisionId, $config);
        $endpoint = $web->waitEndpoint($function, 600, $revisionId);

        $codeBucket->prune($bucket, "{$config->app}/{$config->environment}/", self::KEEP_PACKAGES, [$key]);

        return new DeployResult(WebFunctions::text($endpoint['domainName'] ?? null), $revisionId, $previous, $bucket, $key);
    }

    public function rollback(AppConfig $config, string $revisionId): void
    {
        $web = new WebFunctions($this->aws->lambdaWeb(), $this->sleeper);
        $web->waitEndpoint($config->functionName());
        $web->route($config->functionName(), $revisionId, $config);
        $web->waitEndpoint($config->functionName(), 600, $revisionId);
    }

    public function attachDomains(AppConfig $config, DeployResult $result, array $cachedPaths, ?callable $onStep = null): DeployResult
    {
        if ($config->domains === []) {
            return $result;
        }
        $step = $onStep ?? static function (string $key, array $params = []): void {};
        $domains = $this->customDomains($config)->deploy($config, $result->domainName, $cachedPaths, self::tags($config), $step);
        return $result->withDomains($domains);
    }

    public function hasDomainResources(AppConfig $config): bool
    {
        return $this->stackExists($config) || ($config->domains !== [] && $this->tolerateAccessDenied(
            fn () => (new Certificates($this->aws->acm(), $this->sleeper))->managed($config) !== [],
        ));
    }

    public function remove(AppConfig $config, ?callable $onStep = null): void
    {
        $step = $onStep ?? static function (string $key, array $params = []): void {};
        if ($config->domains !== [] || $this->stackExists($config)) {
            $this->customDomains($config)->remove($config, $step);
        }
        $step('remove.step_function', ['function' => $config->functionName()]);
        (new WebFunctions($this->aws->lambdaWeb(), $this->sleeper))->delete($config->functionName());
        if ($config->role === null) {
            (new ExecutionRole($this->aws->iam()))->delete(ExecutionRole::roleName($config));
        }
    }

    public function describe(AppConfig $config): ?AppStatus
    {
        $web = new WebFunctions($this->aws->lambdaWeb(), $this->sleeper);
        $function = $web->get($config->functionName());
        if ($function === null) {
            return null;
        }
        $endpoint = $web->endpoint($config->functionName());
        $distribution = null;
        if ($config->domains !== []) {
            try {
                $distribution = $this->customDomains($config)->describe($config);
            } catch (AwsException) {
                // The function status is still useful without CloudFormation access.
            }
        }
        return new AppStatus(
            $config->functionName(),
            WebFunctions::text($function['state'] ?? null),
            $endpoint === null ? null : WebFunctions::text($endpoint['domainName'] ?? null),
            $endpoint === null ? null : WebFunctions::activeRevision($endpoint),
            $distribution['domain'] ?? null,
            $distribution['status'] ?? null,
        );
    }

    /** @return array<string, string> */
    private static function tags(AppConfig $config): array
    {
        return ['arco:app' => $config->app, 'arco:environment' => $config->environment, 'arco:version' => Arco::VERSION];
    }

    private function customDomains(AppConfig $config): CustomDomains
    {
        return CustomDomains::create($config, $this->aws, $this->dns, $this->sleeper, $this->forceDns);
    }

    /**
     * Domains removed from arco.php may have left their stack behind. Profiles that never used
     * domains may lack CloudFormation permissions, which then means "no stack".
     */
    private function stackExists(AppConfig $config): bool
    {
        return $this->tolerateAccessDenied(fn () => (new Stacks($this->aws->cloudFormation(), $this->sleeper))->describe(DomainStack::stackName($config)) !== null);
    }

    /** @param callable(): bool $check */
    private function tolerateAccessDenied(callable $check): bool
    {
        try {
            return $check();
        } catch (AwsException $e) {
            if (in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true)) {
                return false;
            }
            throw $e;
        }
    }
}
