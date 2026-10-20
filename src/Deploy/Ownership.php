<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Config\AppConfig;

/**
 * Whether a resource Arco addresses by name (web function, console function, workers, execution role,
 * scheduler rule) belongs to this app and environment. Names are `<app>-<env>` concatenations, so app
 * `shop-eu` + env `prod` and app `shop` + env `eu-prod` share every name: the `arco:app` and
 * `arco:environment` tags are what tells them apart.
 *
 * Every Arco release since 0.1.0 tags each of these resources when it creates them, so a resource
 * without the tags was either stripped of them by hand or not made by Arco. One whose description
 * (or revision description) Arco wrote is treated as owned ($arcoMade) and gets the tags on deploy.
 */
final class Ownership
{
    public const APP = 'arco:app';
    public const ENVIRONMENT = 'arco:environment';

    /**
     * Refuses a resource whose `arco:app`/`arco:environment` tags exist and differ from this app and
     * environment, and one without both tags that Arco did not visibly make. $force (`deploy --force`)
     * overrides both refusals.
     *
     * @param array<string, string> $tags
     * @param 'deploy'|'remove'|'ownership' $action the key prefix of the refusal (ownership: a command
     *                                          that uses the resource without deploying)
     * @throws DeployException {action}.not_owned (resource, app, environment: the owner's tags, `-` for
     *                         a missing one), {action}.untagged_resource
     */
    public static function check(array $tags, AppConfig $config, string $resource, bool $force, bool $arcoMade = false, string $action = 'deploy'): void
    {
        if ($force) {
            return;
        }
        $app = $tags[self::APP] ?? null;
        $environment = $tags[self::ENVIRONMENT] ?? null;
        if (($app !== null && $app !== $config->app) || ($environment !== null && $environment !== $config->environment)) {
            throw DeployException::make("$action.not_owned", ['resource' => $resource, 'app' => $app ?? '-', 'environment' => $environment ?? '-']);
        }
        if ($app === null || $environment === null) {
            if (! $arcoMade) {
                throw DeployException::make("$action.untagged_resource", ['resource' => $resource]);
            }
        }
    }

    /**
     * Whether the resource carries both tags for this app and environment (nothing to add).
     *
     * @param array<string, string> $tags
     */
    public static function tagged(array $tags, AppConfig $config): bool
    {
        return ($tags[self::APP] ?? null) === $config->app && ($tags[self::ENVIRONMENT] ?? null) === $config->environment;
    }

    /** @return array<string, string> the tags Arco puts on every resource of the environment */
    public static function tags(AppConfig $config): array
    {
        // Stable on purpose: a tag change would update every tagged resource (and the CloudFront stack) on
        // each Arco upgrade. The Arco version is in each revision's description instead.
        return [self::APP => $config->app, self::ENVIRONMENT => $config->environment];
    }
}
