<?php declare(strict_types=1);

namespace Multiapi\Arco;

use Composer\InstalledVersions;

final class Arco
{
    /** Used only when Composer does not know the installed version (e.g. working in the monorepo). */
    public const VERSION = 'dev';
    public const LAMBDA_RUNTIME = 'nodejs24.x';
    /** Regions where Lambda Web Functions is available (2026-10-13). */
    public const REGIONS = ['us-east-1', 'us-east-2', 'us-west-2', 'ap-northeast-1', 'eu-west-1'];
    public const MAX_PACKAGE_BYTES = 512 * 1024 * 1024;

    private static ?string $version = null;

    /** The installed multiapi/arco version as Composer reports it (e.g. "0.1.1"), without a "v" prefix. */
    public static function version(): string
    {
        if (self::$version === null) {
            $installed = null;
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('multiapi/arco')) {
                $installed = InstalledVersions::getPrettyVersion('multiapi/arco');
            }
            self::$version = is_string($installed) && $installed !== '' ? ltrim($installed, 'v') : self::VERSION;
        }
        return self::$version;
    }

    public static function packageRoot(): string
    {
        return dirname(__DIR__);
    }
}
