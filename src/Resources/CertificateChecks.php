<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Closure;
use DateTimeInterface;
use Multiapi\Arco\Deploy\Domains\CertificateMatcher;

/** What is worth telling about an ACM certificate before it goes into arco.php or a listing. */
final class CertificateChecks
{
    public const EXPIRY_DAYS = 30;

    /**
     * Whether any of the certificate's names serves the host: the same name, or a "*.parent" wildcard that
     * stands for exactly one label.
     *
     * @param list<string> $certificateDomains
     */
    public static function covers(array $certificateDomains, string $host): bool
    {
        return CertificateMatcher::uncovered($certificateDomains, [$host]) === [];
    }

    /**
     * The warnings for a DescribeCertificate `Certificate`: not issued, expiring within 30 days (or already expired), and not
     * covering some of the domains the environment declares.
     *
     * @param array<array-key, mixed> $certificate
     * @param list<string> $domains the domains the environment declares
     * @param Closure(): int $clock the current time as a Unix timestamp
     * @return list<array{key: string, params: array<string, string>}>
     */
    public static function warnings(array $certificate, array $domains, Closure $clock): array
    {
        return [
            ...self::notIssued($certificate),
            ...self::expiring($certificate, $clock),
            ...self::notCovering($certificate, $domains),
        ];
    }

    /**
     * @param array<array-key, mixed> $certificate
     * @return list<array{key: string, params: array<string, string>}>
     */
    public static function notIssued(array $certificate): array
    {
        $status = is_string($certificate['Status'] ?? null) ? $certificate['Status'] : '';
        return $status === 'ISSUED' ? [] : [['key' => 'resources.warning.certificate_not_issued', 'params' => ['status' => $status === '' ? 'UNKNOWN' : $status]]];
    }

    /**
     * @param array<array-key, mixed> $certificate
     * @param Closure(): int $clock
     * @return list<array{key: string, params: array<string, string>}>
     */
    public static function expiring(array $certificate, Closure $clock): array
    {
        $notAfter = self::timestamp($certificate['NotAfter'] ?? null);
        if ($notAfter === null) {
            return [];
        }
        $left = $notAfter - $clock();
        if ($left >= self::EXPIRY_DAYS * 86400) {
            return [];
        }
        if ($left <= 0) {
            return [['key' => 'resources.warning.certificate_expired', 'params' => []]];
        }
        $days = intdiv($left, 86400);
        // One day has its own message (the lang parity test requires a singular sibling for every {days} days text).
        return $days === 1
            ? [['key' => 'resources.warning.certificate_expiring_one_day', 'params' => []]]
            : [['key' => 'resources.warning.certificate_expiring', 'params' => ['days' => (string) $days]]];
    }

    /**
     * @param array<array-key, mixed> $certificate
     * @param list<string> $domains
     * @return list<array{key: string, params: array<string, string>}>
     */
    public static function notCovering(array $certificate, array $domains): array
    {
        $names = self::names($certificate);
        $missing = array_values(array_filter($domains, static fn (string $domain): bool => ! self::covers($names, $domain)));
        return $missing === [] ? [] : [['key' => 'resources.warning.certificate_not_covering', 'params' => ['domains' => implode(', ', $missing)]]];
    }

    /**
     * The domain name and the alternative names of a DescribeCertificate `Certificate`.
     *
     * @param array<array-key, mixed> $certificate
     * @return list<string>
     */
    public static function names(array $certificate): array
    {
        $names = is_string($certificate['DomainName'] ?? null) ? [$certificate['DomainName']] : [];
        $alternatives = $certificate['SubjectAlternativeNames'] ?? null;
        foreach (is_array($alternatives) ? $alternatives : [] as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }

    /** NotAfter arrives as a date object from the SDK, or as a timestamp or date string in fixtures. */
    private static function timestamp(mixed $value): ?int
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->getTimestamp(),
            is_int($value) => $value,
            is_string($value) && is_numeric($value) => (int) $value,
            is_string($value) => ($time = strtotime($value)) === false ? null : $time,
            default => null,
        };
    }
}
