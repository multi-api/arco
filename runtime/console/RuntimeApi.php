<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/** Client of the Lambda Runtime API (2018-06-01) over curl. */
final class RuntimeApi
{
    private const BASE = '/2018-06-01/runtime';

    public function __construct(private readonly string $endpoint)
    {
    }

    /**
     * Blocks until Lambda hands over the next invocation.
     *
     * @return array{requestId: string, deadlineMs: int, body: string}
     */
    public function next(): array
    {
        [$status, $body, $headers] = $this->request('GET', self::BASE . '/invocation/next', null, [], 0);
        $id = $headers['lambda-runtime-aws-request-id'] ?? '';
        if ($status !== 200 || $id === '') {
            throw new \RuntimeException("The Runtime API answered HTTP $status for the next invocation.");
        }
        return ['requestId' => $id, 'deadlineMs' => (int) ($headers['lambda-runtime-deadline-ms'] ?? 0), 'body' => $body];
    }

    public function respond(string $requestId, string $json): void
    {
        $this->post(self::BASE . "/invocation/$requestId/response", $json, []);
    }

    public function invocationError(string $requestId, string $type, string $message): void
    {
        $this->post(self::BASE . "/invocation/$requestId/error", self::error($type, $message), ['Lambda-Runtime-Function-Error-Type' => $type]);
    }

    public function initError(string $type, string $message): void
    {
        $this->post(self::BASE . '/init/error', self::error($type, $message), ['Lambda-Runtime-Function-Error-Type' => $type]);
    }

    private static function error(string $type, string $message): string
    {
        return json_encode(['errorType' => $type, 'errorMessage' => $message], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, string> $headers */
    private function post(string $path, string $body, array $headers): void
    {
        [$status] = $this->request('POST', $path, $body, $headers, 10);
        if ($status !== 202 && $status !== 200) {
            throw new \RuntimeException("The Runtime API answered HTTP $status for $path.");
        }
    }

    /**
     * @param 'GET'|'POST' $method
     * @param array<string, string> $headers
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function request(string $method, string $path, ?string $body, array $headers, int $timeout): array
    {
        $curl = curl_init("http://{$this->endpoint}$path");
        if ($curl === false) {
            throw new \RuntimeException('Cannot reach the Runtime API.');
        }
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        if (! is_string($response)) {
            throw new \RuntimeException('Runtime API request failed: ' . curl_error($curl));
        }
        return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $response, $responseHeaders];
    }
}
