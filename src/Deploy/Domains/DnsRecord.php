<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

/** A record Arco writes: its name, type and value; how it is stored is the DnsProvider's business. */
final readonly class DnsRecord
{
    /** @param bool $alias a Route 53 alias to a CloudFront distribution ($value is its domain) */
    public function __construct(public string $name, public string $type, public string $value, public bool $alias = false)
    {
    }

    public static function cname(string $name, string $value): self
    {
        return new self(Hostname::normalize($name), 'CNAME', Hostname::normalize($value));
    }

    /** @return list<self> A and AAAA aliases of $domain to the distribution */
    public static function aliases(string $domain, string $distributionDomain): array
    {
        return [
            new self(Hostname::normalize($domain), 'A', Hostname::normalize($distributionDomain), true),
            new self(Hostname::normalize($domain), 'AAAA', Hostname::normalize($distributionDomain), true),
        ];
    }
}
