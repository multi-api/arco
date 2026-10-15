<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

final readonly class DnsRecord
{
    /** Hosted zone id Route 53 uses for every CloudFront alias target. */
    public const CLOUDFRONT_ZONE = 'Z2FDTNDATAQYW2';
    private const TTL = 300;

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

    /** @return array<string, mixed> a Route 53 ResourceRecordSet */
    public function recordSet(): array
    {
        if ($this->alias) {
            return [
                'Name' => $this->name,
                'Type' => $this->type,
                'AliasTarget' => ['HostedZoneId' => self::CLOUDFRONT_ZONE, 'DNSName' => $this->value, 'EvaluateTargetHealth' => false],
            ];
        }
        return ['Name' => $this->name, 'Type' => $this->type, 'TTL' => self::TTL, 'ResourceRecords' => [['Value' => $this->value]]];
    }

    /**
     * True when an existing Route 53 record set already is this record, so writing it would change nothing.
     *
     * @param array<mixed> $set
     */
    public function isSatisfiedBy(array $set): bool
    {
        $name = $set['Name'] ?? null;
        if (($set['Type'] ?? null) !== $this->type || ! is_string($name) || ! Hostname::same($name, $this->name)) {
            return false;
        }
        if ($this->alias) {
            $target = $set['AliasTarget'] ?? null;
            return is_array($target)
                && ($target['HostedZoneId'] ?? null) === self::CLOUDFRONT_ZONE
                && is_string($target['DNSName'] ?? null)
                && Hostname::same($target['DNSName'], $this->value);
        }
        $values = array_map(fn ($r) => is_array($r) && is_string($r['Value'] ?? null) ? $r['Value'] : '', (array) ($set['ResourceRecords'] ?? []));
        return count($values) === 1 && Hostname::same($values[0], $this->value);
    }
}
