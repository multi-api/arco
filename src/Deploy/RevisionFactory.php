<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Arco;
use Multiapi\Arco\Config\AppConfig;

final class RevisionFactory
{
    /**
     * Shape shared by CreateWebFunction.revisionConfig and CreateWebFunctionRevision (top level).
     *
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    public static function revision(AppConfig $config, string $bucket, string $key, ?string $versionId, string $roleArn, array $variables): array
    {
        $s3Object = ['bucket' => $bucket, 'key' => $key];
        if ($versionId !== null) {
            $s3Object['versionId'] = $versionId;
        }

        $revision = ['description' => 'Deployed by Arco'];
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
            'maxConcurrencyPerEnvironment' => $config->concurrency,
            // Lambda rejects empty values; an unset variable reads the same way in PHP (env() → null/'' default).
            'environmentVariables' => array_filter($variables, fn (string $value) => $value !== ''),
            'telemetryConfig' => ['loggingConfig' => [
                'applicationLogLevel' => strtoupper($config->logLevel),
                'systemLogLevel' => 'INFO',
            ]],
        ];
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

    public static function authType(AppConfig $config): string
    {
        return $config->auth === 'iam' ? 'IamAuth' : 'ApplicationManaged';
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
