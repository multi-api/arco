<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/**
 * Resolves arco-ssm: / arco-secret: references in the environment with SSM GetParameters (10 per
 * call) and Secrets Manager BatchGetSecretValue (20 per call), like the web runtime and
 * Multiapi\Arco\Secrets\SecretResolver. Uses the function's credentials from the environment.
 */
final class Secrets
{
    /** @var \Closure(string, array<string, string>, string): array{status: int, body: string} */
    private readonly \Closure $post;

    /** @param (\Closure(string, array<string, string>, string): array{status: int, body: string})|null $post */
    public function __construct(private readonly string $region, ?\Closure $post = null)
    {
        $this->post = $post ?? self::curlPost(...);
    }

    /**
     * @param array<string, string> $env
     * @return array{resolved: array<string, string>, missing: list<string>}
     */
    public function resolve(array $env): array
    {
        $references = [];
        foreach ($env as $key => $value) {
            if (Reference::isReference($value)) {
                $references[$key] = Reference::parse($value);
            }
        }
        if ($references === []) {
            return ['resolved' => [], 'missing' => []];
        }
        $credentials = self::credentials($env);

        $names = [];
        $ids = [];
        foreach ($references as $reference) {
            if ($reference->source === 'ssm') {
                $names[$reference->name] = true;
            } else {
                $ids[$reference->name] = true;
            }
        }

        // Array keys that look like integers become ints: send every name to AWS as a string.
        $parameters = [];
        foreach (array_chunk(array_map(strval(...), array_keys($names)), 10) as $chunk) {
            $response = $this->call('ssm', 'AmazonSSM.GetParameters', ['Names' => $chunk, 'WithDecryption' => true], $credentials);
            foreach (is_array($response['Parameters'] ?? null) ? $response['Parameters'] : [] as $parameter) {
                if (is_array($parameter) && is_string($parameter['Name'] ?? null) && is_string($parameter['Value'] ?? null)) {
                    $parameters[$parameter['Name']] = $parameter['Value'];
                }
            }
        }
        $secrets = [];
        foreach (array_chunk(array_map(strval(...), array_keys($ids)), 20) as $chunk) {
            $response = $this->call('secretsmanager', 'secretsmanager.BatchGetSecretValue', ['SecretIdList' => $chunk], $credentials);
            foreach (is_array($response['SecretValues'] ?? null) ? $response['SecretValues'] : [] as $secret) {
                if (is_array($secret)) {
                    $secrets[] = $secret;
                }
            }
        }

        $resolved = [];
        $missing = [];
        foreach ($references as $key => $reference) {
            $value = $reference->source === 'ssm' ? ($parameters[$reference->name] ?? null) : self::secretValue($secrets, $reference);
            if ($value === null) {
                $missing[] = $env[$key];
            } else {
                $resolved[$key] = $value;
            }
        }
        return ['resolved' => $resolved, 'missing' => $missing];
    }

    /**
     * @param array<string, string> $env
     * @return array{accessKeyId: string, secretAccessKey: string, sessionToken: string|null}
     */
    public static function credentials(array $env): array
    {
        $key = $env['AWS_ACCESS_KEY_ID'] ?? '';
        $secret = $env['AWS_SECRET_ACCESS_KEY'] ?? '';
        if ($key === '' || $secret === '') {
            throw new \RuntimeException('No AWS credentials in the environment (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY).');
        }
        $token = $env['AWS_SESSION_TOKEN'] ?? '';
        return ['accessKeyId' => $key, 'secretAccessKey' => $secret, 'sessionToken' => $token === '' ? null : $token];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public static function curlPost(string $host, array $headers, string $body): array
    {
        $curl = curl_init("https://$host/");
        if ($curl === false) {
            throw new \RuntimeException("Cannot call $host.");
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($curl);
        if (! is_string($response)) {
            throw new \RuntimeException("Calling $host failed: " . curl_error($curl));
        }
        return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $response];
    }

    /**
     * @param array<string, mixed> $body
     * @param array{accessKeyId: string, secretAccessKey: string, sessionToken: string|null} $credentials
     * @return array<mixed>
     */
    private function call(string $service, string $target, array $body, array $credentials): array
    {
        $host = "$service.{$this->region}.amazonaws.com";
        $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = SigV4::sign('POST', $host, '/', '', ['content-type' => 'application/x-amz-json-1.1', 'x-amz-target' => $target], $payload, $credentials, $this->region, $service);
        $response = ($this->post)($host, $headers, $payload);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("$target failed with HTTP {$response['status']}: {$response['body']}");
        }
        $decoded = json_decode($response['body'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param list<array<mixed>> $secrets */
    private static function secretValue(array $secrets, Reference $reference): ?string
    {
        foreach ($secrets as $secret) {
            if (($secret['Name'] ?? null) !== $reference->name && ($secret['ARN'] ?? null) !== $reference->name) {
                continue;
            }
            $string = $secret['SecretString'] ?? null;
            if (! is_string($string)) {
                return null;
            }
            if ($reference->jsonKey === null) {
                return $string;
            }
            $decoded = json_decode($string, true);
            if (! is_array($decoded) || ! array_key_exists($reference->jsonKey, $decoded)) {
                return null;
            }
            $value = $decoded[$reference->jsonKey];
            return match (true) {
                is_string($value) => $value,
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value), is_float($value) => (string) $value,
                default => null,
            };
        }
        return null;
    }
}
