<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

final class CertificateMatcher
{
    /** Publicly trusted certificates only; CloudFront rejects AWS Private CA certificates. */
    public const CLOUDFRONT_TYPES = ['AMAZON_ISSUED', 'IMPORTED'];
    /** Key algorithms CloudFront accepts for viewer certificates. */
    public const CLOUDFRONT_KEYS = ['RSA_2048', 'EC_prime256v1'];

    /** ACM's API enum is RSA_2048, but responses say RSA-2048. */
    public static function keyAlgorithm(?string $value): ?string
    {
        return $value === null ? null : str_replace('-', '_', $value);
    }

    public static function usableByCloudFront(?string $type, ?string $keyAlgorithm): bool
    {
        return in_array($type, self::CLOUDFRONT_TYPES, true) && in_array(self::keyAlgorithm($keyAlgorithm), self::CLOUDFRONT_KEYS, true);
    }

    /** An exact name, or "*.parent" matching exactly one label in front of parent. */
    public static function covers(string $name, string $domain): bool
    {
        $name = Hostname::normalize($name);
        $domain = Hostname::normalize($domain);
        if ($name === $domain) {
            return true;
        }
        if (! str_starts_with($name, '*.')) {
            return false;
        }
        $dot = strpos($domain, '.');
        return $dot !== false && $dot > 0 && substr($domain, $dot + 1) === substr($name, 2);
    }

    /**
     * @param list<string> $names
     * @param list<string> $domains
     * @return list<string>
     */
    public static function uncovered(array $names, array $domains): array
    {
        return array_values(array_filter(
            $domains,
            fn (string $domain) => array_filter($names, fn (string $name) => self::covers($name, $domain)) === [],
        ));
    }

    /**
     * Issued certificates CloudFront accepts that cover every domain; a pending one only when Arco requested it for this
     * environment (an interrupted deploy). Preference: issued, tagged for this environment, fewest names.
     *
     * @param list<Certificate> $certificates
     * @param list<string> $domains
     */
    public static function pick(array $certificates, array $domains, string $app, string $environment): ?Certificate
    {
        $usable = array_values(array_filter($certificates, fn (Certificate $c) => self::uncovered($c->names, $domains) === []
            && self::usableByCloudFront($c->type, $c->keyAlgorithm)
            && ($c->status === 'ISSUED' || ($c->status === 'PENDING_VALIDATION' && $c->managedFor($app, $environment)))));
        usort($usable, fn (Certificate $a, Certificate $b) => [$a->status !== 'ISSUED', ! $a->taggedFor($app, $environment), count($a->names)]
            <=> [$b->status !== 'ISSUED', ! $b->taggedFor($app, $environment), count($b->names)]);
        return $usable[0] ?? null;
    }

    /**
     * ACM returns the same certificate for repeated requests with one token within an hour, so the
     * token includes the domains: changing them must request a new certificate.
     *
     * @param list<string> $domains
     */
    public static function idempotencyToken(string $app, string $environment, array $domains): string
    {
        return md5($app . '/' . $environment . '/' . implode(',', $domains));
    }
}
