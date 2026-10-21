<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

/**
 * Decides what may happen to the existing records of a custom domain before Arco writes its aliases,
 * so a live record is never taken over silently. Route 53's rules (Route53Dns): a record is ours when
 * it points at the distribution.
 */
final class RecordGuard
{
    private const ADDRESS_TYPES = ['A', 'AAAA', 'CNAME'];

    /**
     * conflicts: A/AAAA/CNAME records of $domain that do not point at $distributionDomain (all of them
     * while the distribution does not exist yet). deletes: records that must go in the same change
     * batch as the aliases: a CNAME cannot coexist with them, nor can records with a routing policy;
     * a foreign one is only deleted when $force is set. Plain A/AAAA records are replaced by the UPSERT.
     *
     * @param list<array<string, mixed>> $recordSets as returned by ListResourceRecordSets
     * @return array{conflicts: list<array<string, mixed>>, deletes: list<array<string, mixed>>}
     */
    public static function plan(array $recordSets, string $domain, ?string $distributionDomain, bool $force): array
    {
        $conflicts = [];
        $deletes = [];
        foreach (self::addressRecords($recordSets, $domain) as $set) {
            $ours = $distributionDomain !== null && self::target($set) !== null && Hostname::same((string) self::target($set), $distributionDomain);
            if (! $ours) {
                $conflicts[] = $set;
            }
            $mustGo = ($set['Type'] ?? null) === 'CNAME' || isset($set['SetIdentifier']);
            if ($mustGo && ($ours || $force)) {
                $deletes[] = $set;
            }
        }
        return ['conflicts' => $conflicts, 'deletes' => $deletes];
    }

    /**
     * A/AAAA aliases of $domain that point at $distributionDomain: the only records remove deletes.
     *
     * @param list<array<string, mixed>> $recordSets
     * @return list<array<string, mixed>>
     */
    public static function ourAliases(array $recordSets, string $domain, string $distributionDomain): array
    {
        return array_values(array_filter(
            self::addressRecords($recordSets, $domain),
            fn (array $set) => in_array($set['Type'] ?? null, ['A', 'AAAA'], true)
                && is_array($set['AliasTarget'] ?? null)
                && Hostname::same((string) self::target($set), $distributionDomain),
        ));
    }

    /** @param array<string, mixed> $set */
    public static function describe(array $set): string
    {
        return Hostname::normalize(self::string($set['Name'] ?? '')) . ' ' . self::string($set['Type'] ?? '') . ' → ' . (self::target($set) === null ? '?' : Hostname::normalize((string) self::target($set)));
    }

    /**
     * @param list<array<string, mixed>> $recordSets
     * @return list<array<string, mixed>>
     */
    private static function addressRecords(array $recordSets, string $domain): array
    {
        return array_values(array_filter(
            $recordSets,
            fn (array $set) => Hostname::same(self::string($set['Name'] ?? ''), $domain) && in_array($set['Type'] ?? null, self::ADDRESS_TYPES, true),
        ));
    }

    /** @param array<string, mixed> $set */
    private static function target(array $set): ?string
    {
        $alias = $set['AliasTarget'] ?? null;
        if (is_array($alias)) {
            return is_string($alias['DNSName'] ?? null) ? $alias['DNSName'] : null;
        }
        $records = $set['ResourceRecords'] ?? null;
        $first = is_array($records) ? ($records[0] ?? null) : null;
        return is_array($first) && is_string($first['Value'] ?? null) ? $first['Value'] : null;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
