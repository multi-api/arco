<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/**
 * The permissions boundary of the execution role: the deploy host can override arco.php, so a CI can
 * enforce one without editing the repository. Precedence: --permissions-boundary, then
 * ARCO_PERMISSIONS_BOUNDARY from the deploy host's environment (not an app variable), then arco.php.
 * An empty flag or variable counts as not given, as for --profile and AWS_PROFILE.
 */
final class PermissionsBoundary
{
    public const ENV = 'ARCO_PERMISSIONS_BOUNDARY';
    public const OPTION = 'permissions-boundary';
    public const SOURCE_FLAG = '--' . self::OPTION;
    public const SOURCE_ENV = self::ENV;

    /** arn:<partition>:iam::<12 digits or aws>:policy/<path/>name; \z, not $, so no trailing line break passes. */
    private const ARN = '~^arn:(aws|aws-us-gov|aws-cn):iam::(\d{12}|aws):policy/[\w+=,.@/-]*[\w+=,.@-]\z~';

    /**
     * The boundary a deploy uses.
     *
     * @param array<string, string> $env the deploy host's environment (getenv())
     */
    public static function effective(?string $flag, array $env, ?string $config): ?string
    {
        return self::override($flag, $env)[0] ?? $config;
    }

    /**
     * $config with the deploy host's override applied and its source recorded; $config itself when
     * there is none.
     *
     * @param array<string, string> $env the deploy host's environment (getenv())
     */
    public static function apply(AppConfig $config, ?string $flag, array $env): AppConfig
    {
        $override = self::override($flag, $env);
        return $override === null ? $config : $config->withPermissionsBoundary($override[0], $override[1]);
    }

    public static function isPolicyArn(string $arn): bool
    {
        return preg_match(self::ARN, $arn) === 1;
    }

    /**
     * @param array<string, string> $env
     * @return array{0: string, 1: string}|null value and source
     */
    private static function override(?string $flag, array $env): ?array
    {
        if ($flag !== null && $flag !== '') {
            return [$flag, self::SOURCE_FLAG];
        }
        $value = $env[self::ENV] ?? '';
        return $value !== '' ? [$value, self::SOURCE_ENV] : null;
    }
}
