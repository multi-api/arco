<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

final class ProfileLister
{
    /** @return array{0: string, 1: string} */
    public static function defaultPaths(): array
    {
        $home = getenv('HOME') ?: (string) getenv('USERPROFILE');
        return [
            getenv('AWS_CONFIG_FILE') ?: "$home/.aws/config",
            getenv('AWS_SHARED_CREDENTIALS_FILE') ?: "$home/.aws/credentials",
        ];
    }

    /** @return list<AwsProfile> */
    public static function list(string $configPath, string $credentialsPath): array
    {
        /** @var array<string, array<string, string>> $merged */
        $merged = [];

        foreach (self::read($configPath) as $section => $values) {
            if ($section === 'default') {
                $merged['default'] = $values;
            } elseif (str_starts_with($section, 'profile ')) {
                $merged[trim(substr($section, 8))] = $values;
            }
        }
        foreach (self::read($credentialsPath) as $section => $values) {
            $merged[$section] = [...($merged[$section] ?? []), ...$values];
        }

        $profiles = [];
        foreach ($merged as $name => $values) {
            $profiles[] = new AwsProfile((string) $name, self::type($values), self::account($values));
        }
        usort($profiles, fn (AwsProfile $a, AwsProfile $b) => strcmp($a->name, $b->name));
        return $profiles;
    }

    /** @return array<string, array<string, string>> */
    private static function read(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $parsed = parse_ini_file($path, true, INI_SCANNER_RAW);
        if ($parsed === false) {
            return [];
        }
        /** @var array<string, array<string, string>> $sections */
        $sections = array_filter($parsed, 'is_array');
        return $sections;
    }

    /**
     * @param array<string, string> $values
     * @return 'sso'|'keys'|'other'
     */
    private static function type(array $values): string
    {
        if (isset($values['sso_session']) || isset($values['sso_start_url']) || isset($values['sso_account_id'])) {
            return 'sso';
        }
        if (isset($values['aws_access_key_id'])) {
            return 'keys';
        }
        return 'other';
    }

    /** @param array<string, string> $values */
    private static function account(array $values): ?string
    {
        if (isset($values['sso_account_id'])) {
            return $values['sso_account_id'];
        }
        if (isset($values['role_arn']) && preg_match('/^arn:aws[\w-]*:iam::(\d{12}):/', $values['role_arn'], $m)) {
            return $m[1];
        }
        return null;
    }
}
