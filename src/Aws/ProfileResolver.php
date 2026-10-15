<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

use Multiapi\Arco\Config\AppConfig;

final class ProfileResolver
{
    /** @param array<string, string> $env */
    public static function resolve(?string $flag, array $env, AppConfig $config, LocalState $state): ?ProfileChoice
    {
        if ($flag !== null && $flag !== '') {
            return new ProfileChoice($flag, ProfileSource::Flag);
        }
        if (($env['AWS_PROFILE'] ?? '') !== '') {
            return new ProfileChoice($env['AWS_PROFILE'], ProfileSource::Environment);
        }
        if (($env['AWS_ACCESS_KEY_ID'] ?? '') !== '' || ($env['AWS_WEB_IDENTITY_TOKEN_FILE'] ?? '') !== '') {
            return new ProfileChoice(null, ProfileSource::Environment);
        }
        if ($config->profile !== null) {
            return new ProfileChoice($config->profile, ProfileSource::Config);
        }
        $remembered = $state->profile($config->environment);
        if ($remembered !== null) {
            return new ProfileChoice($remembered, ProfileSource::Remembered);
        }
        return null;
    }
}
