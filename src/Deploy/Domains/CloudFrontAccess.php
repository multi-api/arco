<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Aws\CloudFront\CloudFrontClient;
use Aws\LambdaWeb\Exception\LambdaWebException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Sleeper;
use Multiapi\Arco\Deploy\WebFunctions;

/**
 * With domain(), only the environment's CloudFront distribution may invoke its endpoint: the
 * distribution signs through its origin access control (DomainStack), one statement of the Web
 * Function's resource-based policy lets it invoke, and the endpoint is IamAuth. Arco writes and
 * removes only its own statement (SID); other statements are kept as they are.
 */
final class CloudFrontAccess
{
    public const SID = 'ArcoCloudFront';
    public const TENANT_SID = 'ArcoCloudFrontTenant';
    private const POLL = 15;
    private const DEPLOYED_TIMEOUT = 1800;
    /** A HomeRegion policy change can take a short time to take effect; the endpoint waits for it. */
    private const POLICY_SETTLE = 10;
    private const CONFLICT_RETRIES = 3;

    /** How long the site may answer 403 or 5xx through CloudFront after the switch before Arco reverts it. */
    private const CHECK_SECONDS = 60;
    private const CHECK_POLL = 5;
    private const REVERT_ATTEMPTS = 3;

    public function __construct(
        private readonly WebFunctions $web,
        private readonly CloudFrontClient $cloudFront,
        private readonly Sleeper $sleeper,
        private readonly ClientInterface $http,
    ) {
    }

    /**
     * Locks the endpoint to the distribution in the order that keeps it serving: the distribution
     * signs (the stack put the origin access control on the origin, and it is deployed), the policy
     * lets it invoke, and only then the endpoint requires signatures. An endpoint that is already
     * IamAuth (a new environment, or a redeploy) only gets the policy checked.
     *
     * After the switch, the health path is requested through CloudFront (its default domain, which
     * answers whatever the DNS says). If it keeps failing, the endpoint goes back to ApplicationManaged,
     * so the site keeps serving, and the deploy fails saying so.
     *
     * @param callable(string, array<string, string|int>): void $step
     */
    public function protect(AppConfig $config, string $distributionId, string $distributionDomain, string $healthPath, callable $step): void
    {
        $public = false;
        try {
            $this->lock($config, $distributionId, $distributionDomain, $healthPath, $step, $public, false);
        } catch (\Throwable $e) {
            if ($public) {
                // The deploy fails with its own error; this line says the vulnerability 0.11.4 closes is still open.
                $step('deploy.endpoint_still_public', ['function' => $config->functionName()]);
            }
            throw $e;
        }
    }

    /**
     * Grants a tenant distribution access without requiring a default distribution domain. A public
     * endpoint can only be switched when an active tenant domain can be checked through CloudFront.
     *
     * @param callable(string, array<string, string|int>): void $step
     */
    public function protectTenant(AppConfig $config, string $distributionId, ?string $tenantDomain, string $healthPath, callable $step): void
    {
        $public = false;
        try {
            $this->lock($config, $distributionId, $tenantDomain, $healthPath, $step, $public, true);
        } catch (\Throwable $e) {
            if ($public) {
                $step('deploy.endpoint_still_public', ['function' => $config->functionName()]);
            }
            throw $e;
        }
    }

    /**
     * @param callable(string, array<string, string|int>): void $step
     * @param bool $public set while the endpoint is (still) ApplicationManaged and the switch has not happened
     */
    private function lock(AppConfig $config, string $distributionId, ?string $distributionDomain, string $healthPath, callable $step, bool &$public, bool $tenant): void
    {
        $function = $config->functionName();
        $endpoint = $this->web->endpoint($function) ?? throw DeployException::make('deploy.endpoint_missing', ['function' => $function]);
        $public = ($endpoint['authType'] ?? null) !== 'IamAuth';
        $functionArn = WebFunctions::text($endpoint['functionArn'] ?? null);
        $endpointArn = WebFunctions::text($endpoint['endpointArn'] ?? null);
        // A grant naming the wrong account or distribution would lock CloudFront out once the endpoint is IamAuth.
        if (preg_match('/^arn:([^:]+):lambda:[^:]+:(\d{12}):web-function\/[^\/]+$/D', $functionArn, $arn) !== 1
            || ! str_starts_with($endpointArn, $functionArn . '/endpoint/')
            || preg_match('/^[A-Z0-9]+$/D', $distributionId) !== 1) {
            throw DeployException::make('deploy.access_arn_invalid', ['function' => $functionArn, 'endpoint' => $endpointArn, 'distribution' => $distributionId]);
        }
        $distributionArn = "arn:{$arn[1]}:cloudfront::{$arn[2]}:distribution/$distributionId";

        if ($tenant && $public) {
            $this->verifyTenant($distributionId, $distributionDomain);
        }
        $step('deploy.oac_policy', ['function' => $function, 'distribution' => $distributionId]);
        $sid = $tenant ? self::TENANT_SID : self::SID;
        $statement = $tenant ? self::tenantStatement($endpointArn, $distributionArn) : self::statement($endpointArn, $distributionArn);
        $changed = $this->grant($functionArn, $statement, $sid);
        if (($endpoint['authType'] ?? null) === 'IamAuth') {
            return;
        }

        $this->waitDeployed($distributionId);
        if ($changed) {
            $this->sleeper->sleep(self::POLICY_SETTLE);
        }
        $step('deploy.endpoint_protected', ['function' => $function]);
        $this->web->waitEndpoint($function);
        $this->web->setAuth($function, 'IamAuth');
        $public = false;
        if ($tenant) {
            $this->web->waitEndpoint($function);
            $reached = $this->checkThroughCloudFront($distributionDomain ?? '', $healthPath, $step);
            if ($reached !== null) {
                throw DeployException::make('deploy.timeout', ['what' => "tenant https://$distributionDomain$healthPath (last answer: $reached; endpoint remains IamAuth)"]);
            }
            return;
        }
        $region = (string) $config->region;
        try {
            $this->web->waitEndpoint($function);
            $reached = $this->checkThroughCloudFront($distributionDomain ?? '', $healthPath, $step);
        } catch (\Throwable $e) {
            // Whatever stopped the check, the endpoint must not stay locked unverified: the site could be down.
            $this->revert($function, $region, $e);
            $public = true;
            throw $e;
        }
        if ($reached !== null) {
            $this->revert($function, $region);
            throw DeployException::make('deploy.protection_reverted', ['function' => $function, 'url' => "https://$distributionDomain$healthPath", 'answer' => $reached]);
        }
    }

    private function verifyTenant(string $distributionId, ?string $domain): void
    {
        if ($domain === null || strlen($domain) > 253 || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain) !== 1) {
            throw DeployException::make('deploy.tenant_domains_unavailable');
        }
        $tenant = Aws::map(Aws::call($this->cloudFront, 'GetDistributionTenantByDomain', ['Domain' => $domain])['DistributionTenant'] ?? null);
        $tenantId = Aws::string($tenant['Id'] ?? null);
        if ($tenantId !== null && ($tenant['DistributionId'] ?? null) === $distributionId && ($tenant['Enabled'] ?? null) === true) {
            foreach (Aws::maps($tenant['Domains'] ?? null) as $entry) {
                if (($entry['Domain'] ?? null) === $domain && ($entry['Status'] ?? null) === 'active') {
                    $dns = Aws::maps(Aws::call($this->cloudFront, 'VerifyDnsConfiguration', ['Identifier' => $tenantId, 'Domain' => $domain])['DnsConfigurationList'] ?? null);
                    $matches = array_values(array_filter($dns, static fn (array $entry): bool => ($entry['Domain'] ?? null) === $domain));
                    if (count($matches) === 1 && ($matches[0]['Status'] ?? null) === 'valid-configuration') {
                        return;
                    }
                    break;
                }
            }
        }
        throw DeployException::make('deploy.tenant_domains_unavailable');
    }

    /**
     * Sets the endpoint back to ApplicationManaged after a failed switch, retrying a few times: until it works,
     * CloudFront may be unable to reach the site.
     *
     * @throws DeployException deploy.protection_revert_failed, with the command that does it by hand
     */
    private function revert(string $function, string $region, ?\Throwable $cause = null): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->web->waitEndpoint($function);
                $this->web->setAuth($function, 'ApplicationManaged');
                $this->web->waitEndpoint($function);
                return;
            } catch (\Throwable $e) {
                if ($attempt >= self::REVERT_ATTEMPTS) {
                    throw DeployException::make('deploy.protection_revert_failed', [
                        'function' => $function,
                        'error' => $e->getMessage(),
                        'command' => "aws lambda-web update-web-function-endpoint --region $region --function-name $function --endpoint-name " . WebFunctions::ENDPOINT . ' --auth-type ApplicationManaged',
                    ], $cause ?? $e);
                }
                $this->sleeper->sleep(self::CHECK_POLL);
            }
        }
    }

    /**
     * Requests the health path through CloudFront, unsigned, as a viewer does. A 401 or 403 (Lambda refusing
     * CloudFront's signature) or a 5xx is retried for about a minute: CloudFront and the policy can take
     * a moment. Anything else means CloudFront reaches the app. On a persistent failure the caller sets
     * the endpoint back to ApplicationManaged; the statement and the access control stay (harmless on it).
     *
     * @param callable(string, array<string, string|int>): void $step
     * @return string|null null when CloudFront reached the app; otherwise the last answer
     */
    private function checkThroughCloudFront(string $distributionDomain, string $healthPath, callable $step): ?string
    {
        $url = "https://$distributionDomain$healthPath";
        $step('deploy.protection_check', ['url' => $url]);
        $deadline = $this->sleeper->now() + self::CHECK_SECONDS;
        while (true) {
            try {
                $status = $this->http->request('GET', $url, ['http_errors' => false, 'allow_redirects' => false, 'timeout' => 30, 'version' => '1.1'])->getStatusCode();
                $answer = (string) $status;
            } catch (GuzzleException $e) {
                $status = null;
                $answer = $e->getMessage();
            }
            if ($status !== null && ! in_array($status, [401, 403], true) && $status < 500) {
                return null;
            }
            if ($this->sleeper->now() >= $deadline) {
                break;
            }
            $this->sleeper->sleep(self::CHECK_POLL);
        }
        return $answer;
    }

    /**
     * Removes Arco's statement, and only it; the whole policy when nothing else is left in it.
     *
     * @return bool whether there was a statement to remove
     */
    public function revoke(string $functionArn): bool
    {
        return $this->revokeStatement($functionArn, self::SID);
    }

    public function revokeTenant(string $functionArn): bool
    {
        return $this->revokeStatement($functionArn, self::TENANT_SID);
    }

    private function revokeStatement(string $functionArn, string $sid): bool
    {
        for ($attempt = 1; ; $attempt++) {
            $current = $this->web->policy($functionArn);
            if ($current === null) {
                return false;
            }
            $statements = self::statements($current['policy'], $functionArn);
            $others = self::others($statements, $sid);
            if (count($others) === count($statements)) {
                return false;
            }
            try {
                if ($others === []) {
                    $this->web->deletePolicy($functionArn, $current['revisionId']);
                } else {
                    $this->web->putPolicy($functionArn, ['Statement' => $others] + $current['policy'], $current['revisionId']);
                }
                return true;
            } catch (LambdaWebException $e) {
                $this->retryConflict($e, $attempt);
            }
        }
    }

    /**
     * @return array<string, mixed> the statement that lets the distribution (and only it) invoke the endpoint
     */
    public static function statement(string $endpointArn, string $distributionArn): array
    {
        return [
            'Sid' => self::SID,
            'Effect' => 'Allow',
            'Principal' => ['Service' => 'cloudfront.amazonaws.com'],
            'Action' => 'lambda:InvokeWebFunctionEndpoint',
            'Resource' => $endpointArn,
            'Condition' => ['StringEquals' => ['aws:SourceArn' => $distributionArn]],
        ];
    }

    /** @return array<string, mixed> */
    public static function tenantStatement(string $endpointArn, string $distributionArn): array
    {
        return ['Sid' => self::TENANT_SID] + self::statement($endpointArn, $distributionArn);
    }

    /**
     * Puts the statement in the policy, keeping every other statement; no call when it is already there.
     *
     * @param array<string, mixed> $statement
     * @return bool whether the policy changed
     */
    private function grant(string $functionArn, array $statement, string $sid): bool
    {
        for ($attempt = 1; ; $attempt++) {
            $current = $this->web->policy($functionArn);
            $statements = self::statements($current['policy'] ?? [], $functionArn);
            $ours = array_values(array_filter($statements, fn (array $s) => ($s['Sid'] ?? null) === $sid));
            if (count($ours) === 1 && self::canonical($ours[0]) === self::canonical($statement)) {
                return false;
            }
            $policy = ['Version' => '2012-10-17', ...($current['policy'] ?? [])];
            $policy['Statement'] = [...self::others($statements, $sid), $statement];
            try {
                // The revision read makes a concurrent change fail (ConflictException) instead of being overwritten.
                $this->web->putPolicy($functionArn, $policy, $current['revisionId'] ?? null);
                return true;
            } catch (LambdaWebException $e) {
                $this->retryConflict($e, $attempt);
            }
        }
    }

    private function waitDeployed(string $distributionId): void
    {
        for ($waited = 0; ; $waited += self::POLL) {
            $distribution = Aws::map(Aws::call($this->cloudFront, 'GetDistribution', ['Id' => $distributionId])['Distribution'] ?? null);
            if (($distribution['Status'] ?? null) === 'Deployed') {
                return;
            }
            if ($waited >= self::DEPLOYED_TIMEOUT) {
                throw DeployException::make('deploy.timeout', ['what' => "distribution $distributionId"]);
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    /** A conflict is a changed revision or a busy function: read again after a pause, a bounded number of times. */
    private function retryConflict(LambdaWebException $e, int $attempt): void
    {
        if ($e->getAwsErrorCode() !== 'ConflictException' || $attempt >= self::CONFLICT_RETRIES) {
            throw $e;
        }
        $this->sleeper->sleep(3);
    }

    /**
     * A policy's statements; a single statement may be written as an object. Anything else stops the
     * deploy: rewriting a policy Arco cannot read would drop the statements it did not write.
     *
     * @param array<string, mixed> $policy
     * @return list<array<string, mixed>>
     */
    private static function statements(array $policy, string $functionArn): array
    {
        $statements = $policy['Statement'] ?? [];
        if (is_array($statements) && ! array_is_list($statements)) {
            $statements = [$statements];
        }
        if (! is_array($statements) || array_filter($statements, fn (mixed $s) => ! is_array($s) || ($s !== [] && array_is_list($s))) !== []) {
            throw DeployException::make('deploy.policy_unreadable', ['arn' => $functionArn]);
        }
        return Aws::maps($statements);
    }

    /**
     * @param list<array<string, mixed>> $statements
     * @return list<array<string, mixed>>
     */
    private static function others(array $statements, string $sid): array
    {
        return array_values(array_filter($statements, fn (array $s) => ($s['Sid'] ?? null) !== $sid));
    }

    /** JSON with sorted keys, so a statement read back compares equal whatever order AWS keeps. */
    private static function canonical(mixed $value): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
            return array_map($sort, $value);
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
