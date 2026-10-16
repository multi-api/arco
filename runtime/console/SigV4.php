<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/** AWS Signature Version 4, the same algorithm as runtime/lib/sigv4.js. */
final class SigV4
{
    /**
     * @param array<string, string> $headers
     * @param array{accessKeyId: string, secretAccessKey: string, sessionToken: string|null} $credentials
     * @return array<string, string> headers to send, authorization included
     */
    public static function sign(string $method, string $host, string $path, string $query, array $headers, string $body, array $credentials, string $region, string $service, ?\DateTimeImmutable $now = null): array
    {
        $utc = new \DateTimeZone('UTC');
        $amzDate = ($now ?? new \DateTimeImmutable('now', $utc))->setTimezone($utc)->format('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);

        $signed = [];
        foreach ($headers as $name => $value) {
            $signed[strtolower($name)] = (string) preg_replace('/\s+/', ' ', trim($value));
        }
        $signed['host'] = $host;
        $signed['x-amz-date'] = $amzDate;
        if (($credentials['sessionToken'] ?? '') !== '' && $credentials['sessionToken'] !== null) {
            $signed['x-amz-security-token'] = $credentials['sessionToken'];
        }
        ksort($signed, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($signed as $name => $value) {
            $canonicalHeaders .= "$name:$value\n";
        }
        $signedHeaders = implode(';', array_keys($signed));
        $parameters = array_values(array_filter(explode('&', $query), fn (string $p) => $p !== ''));
        sort($parameters, SORT_STRING);
        $canonicalRequest = implode("\n", [$method, $path === '' ? '/' : $path, implode('&', $parameters), $canonicalHeaders, $signedHeaders, hash('sha256', $body)]);

        $scope = "$date/$region/$service/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);
        $key = hash_hmac('sha256', $date, 'AWS4' . $credentials['secretAccessKey'], true);
        foreach ([$region, $service, 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }
        $signed['authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $credentials['accessKeyId'], $scope, $signedHeaders, hash_hmac('sha256', $stringToSign, $key),
        );
        return $signed;
    }
}
