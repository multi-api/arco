<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Arco;
use Multiapi\Arco\Aws\Endpoint;
use Multiapi\Arco\Config\AppConfig;

final class RevisionFactory
{
    /**
     * Shape shared by CreateWebFunction.revisionConfig and CreateWebFunctionRevision (top level).
     *
     * @param array<string, string> $variables
     * @param string|null $networkConnectorArn the connector version the revision pins for its egress (vpc())
     * @return array<string, mixed>
     */
    public static function revision(AppConfig $config, string $bucket, string $key, ?string $versionId, string $roleArn, array $variables, ?string $networkConnectorArn = null): array
    {
        $s3Object = ['bucket' => $bucket, 'key' => $key];
        if ($versionId !== null) {
            $s3Object['versionId'] = $versionId;
        }

        $revision = ['description' => 'Deployed by Arco ' . Arco::version()];
        if ($config->kmsKey !== null) {
            $revision['kmsKeyArn'] = $config->kmsKey;
        }
        $revision['buildConfig'] = [
            'codeConfig' => ['s3Object' => $s3Object],
            'runtimeConfig' => ['runtime' => Arco::LAMBDA_RUNTIME],
        ];
        $revision['serviceConfig'] = [
            'executionRoleArn' => $roleArn,
            'timeoutSeconds' => $config->timeout,
            // Lambda rejects empty values; an unset variable reads the same way in PHP (env() → null/'' default).
            'environmentVariables' => array_filter($variables, fn (string $value) => $value !== ''),
            'telemetryConfig' => ['loggingConfig' => [
                'applicationLogLevel' => strtoupper($config->logLevel),
                'systemLogLevel' => 'INFO',
            ]],
        ];
        if ($config->concurrency !== null) {
            // Only an upper bound: Lambda also applies its own dynamic per-instance limit.
            $revision['serviceConfig']['maxConcurrencyPerEnvironment'] = $config->concurrency;
        }
        if ($networkConnectorArn !== null) {
            $revision['serviceConfig']['egressNetworkConnectorArn'] = $networkConnectorArn;
        }
        return $revision;
    }

    /** @return array<string, mixed> */
    public static function initialEndpoint(AppConfig $config): array
    {
        return [
            'endpointName' => WebFunctions::ENDPOINT,
            'endpointType' => 'HomeRegion',
            'authType' => self::authType($config),
            'autoDeploymentMode' => 'LatestRevision',
            ...self::limits($config),
        ];
    }

    /**
     * The auth type the endpoint should end up with. With domain() it is IamAuth whatever auth() says:
     * only the environment's CloudFront distribution, signing through its origin access control, may
     * invoke it. An existing endpoint switches only once the distribution signs (CloudFrontAccess).
     */
    public static function authType(AppConfig $config): string
    {
        return $config->auth === 'iam' || self::protectsEndpoint($config) ? 'IamAuth' : 'ApplicationManaged';
    }

    /**
     * Whether only the environment's CloudFront may invoke the endpoint (origin access control, policy
     * statement, IamAuth): with domain(), except behind an emulator endpoint (AWS_ENDPOINT_URL), where
     * CloudFront has no Lambda Web Function origin access control to sign with. The endpoint then keeps
     * the configured auth.
     */
    public static function protectsEndpoint(AppConfig $config): bool
    {
        // Skipped only when the emulator serves every service involved: a per-service override pointing elsewhere
        // (real AWS, say) keeps the protection. A profile's endpoint_url is not read (see local-floci.md).
        return $config->domains !== [] && ! Endpoint::servesAll(['lambda', 'lambda_web', 'cloudfront', 'cloudformation']);
    }

    /**
     * Whether the environment runs in the VPC: vpc() is set, except behind an emulator endpoint (AWS_ENDPOINT_URL),
     * which has no network connectors. Skipped only when the emulator serves every service involved, like
     * protectsEndpoint(): a per-service override pointing elsewhere keeps the VPC.
     */
    public static function usesVpc(AppConfig $config): bool
    {
        return $config->vpc !== null && self::servesNetworkConnectors();
    }

    /** False behind an emulator: it has no network connectors, so Arco neither creates nor looks for one. */
    public static function servesNetworkConnectors(): bool
    {
        return ! Endpoint::servesAll(['lambda', 'lambda_web', 'lambda_core']);
    }

    /** @return array<string, array<string, int>> */
    public static function limits(AppConfig $config): array
    {
        $limits = [];
        // Arco calls them instances; the API calls a running instance an "execution environment".
        if ($config->maxInstances !== null) {
            $limits['scalingConfig'] = ['maxEnvironments' => $config->maxInstances];
        }
        if ($config->rateLimit !== null) {
            $limits['throttleConfig'] = ['rateLimit' => $config->rateLimit];
        }
        return $limits;
    }
}
