<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

use Closure;
use Multiapi\Arco\Deploy\Domains\Dns;
use Multiapi\Arco\Deploy\Domains\Hostname;
use Multiapi\Arco\Deploy\Sleeper;
use Throwable;

/** Checks whether the Route 53 of one AWS profile holds the public zones of a set of domains. */
final class DnsZoneFinder
{
    /** SDK settings for the check: a dead or expired profile must not stall `arco init`. */
    public const CLIENT_OPTIONS = ['http' => ['connect_timeout' => 3, 'timeout' => 10], 'retries' => 1];

    /** @param Closure(string): AwsClients $clients Route 53 / STS clients for a profile */
    public function __construct(private readonly Closure $clients)
    {
    }

    public static function default(): self
    {
        return new self(fn (string $profile) => new AwsClients($profile, 'us-east-1', self::CLIENT_OPTIONS));
    }

    /**
     * Zone names that could hold a domain: every suffix with at least two labels, longest first.
     *
     * @return list<string>
     */
    public static function candidates(string $domain): array
    {
        $labels = explode('.', Hostname::normalize(trim($domain)));
        $candidates = [];
        for ($i = 0; $i <= count($labels) - 2; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }
        return $candidates;
    }

    /**
     * Only this profile is called: ListHostedZonesByName, then GetCallerIdentity when a zone matched.
     * Any error (expired SSO, AccessDenied, no credentials) is returned in the result, not thrown.
     *
     * @param list<string> $domains
     */
    public function find(array $domains, string $profile): ZoneSearch
    {
        try {
            $clients = ($this->clients)($profile);
            $dns = new Dns($clients->route53(), new Sleeper());
            $zones = [];
            foreach ($domains as $domain) {
                // The longest public zone; a single-label zone such as "cl." never counts.
                $zone = $dns->zoneFor($domain);
                if ($zone !== null && in_array(Hostname::normalize($zone->name), self::candidates($domain), true)) {
                    $zones[$domain] = $zone;
                }
            }
            $account = $zones === [] ? null : $clients->accountId();
        } catch (Throwable $e) {
            return new ZoneSearch($profile, [], $e);
        }
        $found = [];
        foreach ($zones as $domain => $zone) {
            $found[$domain] = new ZoneMatch($profile, (string) $account, $zone->id, Hostname::normalize($zone->name));
        }
        return new ZoneSearch($profile, $found);
    }
}
