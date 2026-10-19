<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

/**
 * The AWS endpoint override (AWS_ENDPOINT_URL), which the AWS SDK also honours: it points Arco at a
 * local emulator such as Floci. A value that is not http(s)://host[:port] is treated as unset, and so is any
 * value when AWS_IGNORE_CONFIGURED_ENDPOINT_URLS is true, which makes the SDK ignore it too.
 */
final class Endpoint
{
    public static function override(): ?string
    {
        $value = getenv('AWS_ENDPOINT_URL');
        if (self::ignored() || ! is_string($value) || ! preg_match('~^(https?)://([A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(:\d{1,5})?/*$~', $value)) {
            return null;
        }
        return rtrim($value, '/');
    }

    /**
     * A non-empty AWS_ENDPOINT_URL that override() does not accept, while the SDK is not told to ignore it: the SDK
     * may still send its calls there, while Arco builds AWS-style URLs.
     */
    public static function unrecognised(): ?string
    {
        $value = getenv('AWS_ENDPOINT_URL');
        if (! is_string($value) || $value === '' || self::ignored() || self::override() !== null) {
            return null;
        }
        return $value;
    }

    /** AWS_IGNORE_CONFIGURED_ENDPOINT_URLS as the SDK reads it: "true", "on" or "1", in any case. */
    private static function ignored(): bool
    {
        $value = getenv('AWS_IGNORE_CONFIGURED_ENDPOINT_URLS');
        return is_string($value) && in_array(strtolower($value), ['true', 'on', '1'], true);
    }

    public static function scheme(): string
    {
        $override = self::override();
        return $override !== null && str_starts_with($override, 'http://') ? 'http' : 'https';
    }

    /** The bucket's base URL: path style behind an override, virtual-hosted style on AWS. */
    public static function bucketUrl(string $bucket, string $region): string
    {
        $override = self::override();
        return $override !== null ? "$override/$bucket" : "https://$bucket.s3.$region.amazonaws.com";
    }
}
