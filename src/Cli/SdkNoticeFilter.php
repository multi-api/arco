<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

/**
 * The AWS SDK reports an expired `aws login` token (and the STS region fallback) as E_USER_NOTICE
 * while a credential chain moves on to the next provider. Arco already reports profiles it could not
 * use, so bin/arco hides those notices, and nothing else.
 */
final class SdkNoticeFilter
{
    public const LEVELS = E_USER_NOTICE;

    /** Error handler: true swallows the notice, false lets PHP handle it as usual. */
    public static function handle(int $level, string $message, string $file = ''): bool
    {
        return $level === E_USER_NOTICE && str_contains(str_replace('\\', '/', $file), '/aws-sdk-php/src/Credentials/');
    }
}
