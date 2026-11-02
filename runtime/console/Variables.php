<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/**
 * bundleVariables(): ARCO_VARIABLES points at one JSON object in the code bucket holding the app's variables
 * (s3://<bucket>/variables/<app>/<env>/<sha256>.json), like runtime/lib/variables.js for the web runtime. They are
 * loaded before secret references are resolved, so arco-ssm: and arco-secret: values inside the bundle work as in the
 * Lambda environment. Uses the function's credentials; ARCO_S3_ENDPOINT (an emulator) is read path style.
 */
final class Variables
{
    public const POINTER = 'ARCO_VARIABLES';

    /** @var \Closure(string, array<string, string>): array{status: int, body: string} */
    private readonly \Closure $get;

    /** @param (\Closure(string, array<string, string>): array{status: int, body: string})|null $get */
    public function __construct(private readonly string $region, ?\Closure $get = null)
    {
        $this->get = $get ?? self::curlGet(...);
    }

    /**
     * The variables the bundle adds: a name the real environment already has keeps its value (Lambda's own variables,
     * ARCO_* and the credentials are never replaced).
     *
     * @param array<string, string> $env
     * @return array<string, string>
     */
    public function load(array $env): array
    {
        $pointer = $env[self::POINTER] ?? '';
        if ($pointer === '') {
            return [];
        }
        if (preg_match('#^s3://([^/]+)/(.+)$#', $pointer, $match) !== 1) {
            throw new \RuntimeException(self::POINTER . " must be s3://<bucket>/<key>, got \"$pointer\" (deploy again with arco deploy).");
        }
        [, $bucket, $key] = $match;
        $body = $this->getObject($bucket, $key, $env);

        $actual = hash('sha256', $body);
        if (preg_match('/([0-9a-f]{64})\.json$/', $key, $expected) !== 1 || $expected[1] !== $actual) {
            throw new \RuntimeException("The variable bundle s3://$bucket/$key does not match its checksum (got $actual).");
        }
        $decoded = json_decode($body, true);
        $variables = is_array($decoded) && ($decoded['schema'] ?? null) === 1 && is_array($decoded['variables'] ?? null) ? $decoded['variables'] : null;
        if ($variables === null || ($variables !== [] && array_is_list($variables))) {
            throw new \RuntimeException("s3://$bucket/$key is not a variable bundle (schema 1, string values).");
        }
        $loaded = [];
        foreach ($variables as $name => $value) {
            if (! is_string($value)) {
                throw new \RuntimeException("s3://$bucket/$key is not a variable bundle (schema 1, string values).");
            }
            if (! array_key_exists((string) $name, $env)) {
                $loaded[(string) $name] = $value;
            }
        }
        return $loaded;
    }

    /** @param array<string, string> $env */
    private function getObject(string $bucket, string $key, array $env): string
    {
        $path = '/' . implode('/', array_map(rawurlencode(...), explode('/', $key)));
        $endpoint = $env['ARCO_S3_ENDPOINT'] ?? '';
        if ($endpoint !== '') {
            $scheme = (string) parse_url($endpoint, PHP_URL_SCHEME);
            $port = parse_url($endpoint, PHP_URL_PORT);
            $host = parse_url($endpoint, PHP_URL_HOST) . ($port === null || $port === false ? '' : ":$port");
            $path = '/' . rawurlencode($bucket) . $path;
        } else {
            $scheme = 'https';
            $host = "$bucket.s3.{$this->region}.amazonaws.com";
        }
        $headers = SigV4::sign('GET', $host, $path, '', ['x-amz-content-sha256' => hash('sha256', '')], '', Secrets::credentials($env), $this->region, 's3');
        $response = ($this->get)("$scheme://$host$path", $headers);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("Cannot read s3://$bucket/$key: HTTP {$response['status']}: " . substr($response['body'], 0, 300) . ' (check s3:GetObject on variables/* in the execution role).');
        }
        return $response['body'];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public static function curlGet(string $url, array $headers): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException("Cannot read $url.");
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($curl);
        if (! is_string($response)) {
            throw new \RuntimeException("Reading $url failed: " . curl_error($curl));
        }
        return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $response];
    }
}
