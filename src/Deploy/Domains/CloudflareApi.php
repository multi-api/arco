<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Sleeper;
use Psr\Http\Message\ResponseInterface;

/**
 * The Cloudflare API v4 calls DNS needs: bearer token, JSON, retries on 429 and 5xx with backoff. Every
 * failure is a DeployException whose params never hold the token (Guzzle's own messages are redacted,
 * and the Guzzle exception is not chained, so nothing renders its request headers).
 *
 * @internal
 */
final class CloudflareApi
{
    public const BASE_URI = 'https://api.cloudflare.com/client/v4/';
    public const TOKEN_ENV = 'CLOUDFLARE_API_TOKEN';
    /** What the token needs, named in every token error. */
    public const PERMISSIONS = 'Zone:DNS:Edit + Zone:Read';

    /** Tries per request: the first plus three retries (1 s, 2 s, 4 s apart, or Retry-After up to 30 s). */
    public const ATTEMPTS = 4;
    private const MAX_DELAY = 30;
    /** Pages getAll() reads before it refuses the listing. */
    private const MAX_PAGES = 50;
    /** Cloudflare error codes that mean the token itself was refused (answered with 400 or 403). */
    private const AUTH_CODES = [6003, 6111, 9106, 9109, 10000];

    public function __construct(private readonly string $token, private readonly ClientInterface $http, private readonly Sleeper $sleeper)
    {
    }

    /** @param (callable)|null $handler a Guzzle handler (tests pass a MockHandler) */
    public static function create(string $token, Sleeper $sleeper = new Sleeper(), ?callable $handler = null): self
    {
        $options = ['base_uri' => self::BASE_URI, 'timeout' => 30, 'connect_timeout' => 10];
        if ($handler !== null) {
            $options['handler'] = HandlerStack::create($handler);
        }
        return new self($token, new Client($options), $sleeper);
    }

    /**
     * @param array<string, string|int> $query
     * @return mixed the response's `result`
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query)['result'] ?? null;
    }

    /**
     * Every item of a list endpoint: the pages after the first are read while result_info.total_pages
     * says there are more (at most MAX_PAGES, then the listing is refused rather than cut short).
     *
     * @param array<string, string|int> $query
     * @return list<mixed>
     */
    public function getAll(string $path, array $query = []): array
    {
        $items = [];
        for ($page = 1; ; $page++) {
            $body = $this->request('GET', $path, $page === 1 ? $query : $query + ['page' => $page]);
            array_push($items, ...array_values(is_array($body['result'] ?? null) ? $body['result'] : []));
            $info = is_array($body['result_info'] ?? null) ? $body['result_info'] : [];
            $total = is_int($info['total_pages'] ?? null) ? $info['total_pages'] : 1;
            if ($page >= $total) {
                return $items;
            }
            if ($page >= self::MAX_PAGES) {
                throw DeployException::make('cloudflare.request_failed', ['call' => "GET /$path", 'detail' => "GET /$path: more than " . self::MAX_PAGES . " pages ($total)"]);
            }
        }
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body): mixed
    {
        return $this->request('POST', $path, [], $body)['result'] ?? null;
    }

    /** @param array<string, mixed> $body */
    public function patch(string $path, array $body): mixed
    {
        return $this->request('PATCH', $path, [], $body)['result'] ?? null;
    }

    public function delete(string $path): mixed
    {
        return $this->request('DELETE', $path)['result'] ?? null;
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $json
     * @return array<mixed> the decoded response body
     */
    private function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $options = [
            'http_errors' => false,
            'headers' => ['Authorization' => "Bearer $this->token", 'Accept' => 'application/json'],
            'query' => $query,
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $call = "$method /$path";
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->request($method, $path, $options);
            } catch (GuzzleException $e) {
                // Not chained: a Guzzle exception carries the request, and with it the Authorization header.
                throw DeployException::make('cloudflare.request_failed', ['call' => $call, 'detail' => $this->redact("$call: " . $e->getMessage())]);
            }
            $status = $response->getStatusCode();
            if ($status !== 429 && $status < 500) {
                break;
            }
            if ($attempt >= self::ATTEMPTS) {
                throw DeployException::make('cloudflare.gave_up', [
                    'call' => $call,
                    'status' => $status,
                    'attempts' => $attempt,
                    'detail' => $this->redact("$call: HTTP $status after $attempt attempts"),
                ]);
            }
            $this->sleeper->sleep($this->delay($response, $attempt));
        }

        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : [];
        [$codes, $messages] = self::errors($body);
        $detail = $this->redact("$call: HTTP $status" . ($messages === '' ? '' : " $messages"));
        if ($status === 401 || $status === 403 || array_intersect($codes, self::AUTH_CODES) !== []) {
            throw DeployException::make('cloudflare.token_rejected', [
                'env' => self::TOKEN_ENV,
                'permissions' => self::PERMISSIONS,
                'detail' => $detail,
            ]);
        }
        if ($status >= 400 || ($body['success'] ?? false) !== true) {
            throw DeployException::make('cloudflare.request_failed', ['call' => $call, 'detail' => $detail]);
        }
        return $body;
    }

    private function delay(ResponseInterface $response, int $attempt): int
    {
        $after = trim($response->getHeaderLine('Retry-After'));
        if ($after !== '' && ctype_digit($after)) {
            return max(1, min((int) $after, self::MAX_DELAY));
        }
        return min(1 << ($attempt - 1), self::MAX_DELAY);
    }

    /**
     * @param array<mixed> $body
     * @return array{0: list<int>, 1: string} error codes, and "code: message" joined
     */
    private static function errors(array $body): array
    {
        $codes = [];
        $messages = [];
        foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $error) {
            if (! is_array($error)) {
                continue;
            }
            $code = is_int($error['code'] ?? null) ? $error['code'] : null;
            $message = is_string($error['message'] ?? null) ? $error['message'] : '';
            if ($code !== null) {
                $codes[] = $code;
            }
            $messages[] = trim(($code === null ? '' : "$code: ") . $message);
        }
        return [$codes, implode('; ', array_filter($messages, fn (string $m) => $m !== ''))];
    }

    /** The text with the token (and any "Bearer <token>") replaced, for messages Arco did not write. */
    private function redact(string $text): string
    {
        return $this->token === '' ? $text : str_replace($this->token, '[redacted]', $text);
    }
}
