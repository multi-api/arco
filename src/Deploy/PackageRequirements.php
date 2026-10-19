<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Config\AppConfig;

/** The Composer packages each arco.php feature needs in the app, for the deploy check and for `arco init`. */
final class PackageRequirements
{
    /** WorkCommand (`arco:work`) first shipped in multiapi/arco-laravel 0.5. */
    public const ARCO_LARAVEL_05 = 'multiapi/arco-laravel:^0.5';

    /** The storage disks and the table wiring first shipped in multiapi/arco-laravel 0.6. */
    public const ARCO_LARAVEL_06 = 'multiapi/arco-laravel:^0.6';

    private const PROVIDER = 'vendor/multiapi/arco-laravel/src/ArcoServiceProvider.php';

    private const AWS_SDK = ['aws/aws-sdk-php', 'vendor/aws/aws-sdk-php/src/Sdk.php', null];

    /**
     * What each arco.php feature needs: [requirement for `composer require`, file relative to the app root,
     * string that file must contain or null]. A dev version of arco-laravel is recognised by what its
     * provider reads (ARCO_STORAGE, ARCO_FAILED_JOBS_TABLE), not by its version string.
     *
     * @return array<string, list<array{0: string, 1: string, 2: string|null}>> feature (its arco.php call) => requirements
     */
    public static function forConfig(AppConfig $config): array
    {
        $features = [];
        if ($config->queues !== []) {
            $features['queue()'] = [[self::ARCO_LARAVEL_05, 'vendor/multiapi/arco-laravel/src/Console/WorkCommand.php', null], self::AWS_SDK];
        }
        if ($config->storage !== []) {
            $features['storage()'] = [
                ['league/flysystem-aws-s3-v3:^3.0', 'vendor/league/flysystem-aws-s3-v3/AwsS3V3Adapter.php', null],
                self::AWS_SDK,
                [self::ARCO_LARAVEL_06, self::PROVIDER, 'ARCO_STORAGE'],
            ];
        }
        if ($config->cacheTable !== null) {
            $features['cache()'] = [self::AWS_SDK];
        }
        if ($config->failedJobsTable !== null) {
            $features['failedJobsTable()'] = [self::AWS_SDK, [self::ARCO_LARAVEL_06, self::PROVIDER, 'ARCO_FAILED_JOBS_TABLE']];
        }
        return $features;
    }
}
