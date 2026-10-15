<?php declare(strict_types=1);

namespace Multiapi\Arco;

final class Arco
{
    public const VERSION = '0.1.0-dev';
    public const LAMBDA_RUNTIME = 'nodejs24.x';
    /** Regions where Lambda Web Functions is available (2026-10-13). */
    public const REGIONS = ['us-east-1', 'us-east-2', 'us-west-2', 'ap-northeast-1', 'eu-west-1'];
    public const MAX_PACKAGE_BYTES = 512 * 1024 * 1024;

    public static function packageRoot(): string
    {
        return dirname(__DIR__);
    }
}
