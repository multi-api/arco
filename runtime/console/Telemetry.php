<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/**
 * The anonymous invocation count, as runtime/lib/telemetry.js keeps it for the Node runtimes. Each Lambda environment
 * counts invocations in memory, one counter per kind, and sends them to Arco's collector as one plain-text UDP
 * datagram per kind: the first right after the first invocation (cold start, with the boot bucket), then at most once
 * a minute when an invocation ends. A packet carries only runtime, kind, Arco version, region, count, cold start and
 * boot bucket. Nothing here throws into the caller: the collector is resolved once, on the first send (after the
 * response, outside the request's latency), and a failed lookup turns telemetry off for the environment's life.
 * ARCO_TELEMETRY_DISABLE=1 or `"telemetry": false` in arco-runtime.json turn it off before anything is counted.
 *
 * Self-contained on purpose: multiapi/arco-laravel's queue loop requires this file from the function package.
 * Its public API (fromRuntime, enabled, count, tick, flush) is therefore a contract across packages: an app's
 * arco-laravel and the CLI that built its package are upgraded separately, so it must stay backward compatible.
 */
final class Telemetry
{
    public const DEFAULT_HOST = 'telemetry.arcocloud.app';
    public const DEFAULT_PORT = 41700;
    public const SEND_INTERVAL_SECONDS = 60.0;
    private const PROTOCOL = 'arco1';
    private const RUNTIMES = ['laravel', 'process'];
    private const KINDS = ['web', 'queue', 'console', 'schedule'];
    // Version and region go into the packet as they are: anything unexpected turns telemetry off instead.
    private const VERSION = '/^[0-9A-Za-z.+-]{1,32}$/';
    private const REGION = '/^[a-z0-9-]{1,32}$/';

    /** @var array<string, int> */
    private array $pending = [];
    private bool $cold = true;
    private float $lastSent = 0.0;

    /**
     * @param \Closure(string): void $sender sends one packet; throwing turns telemetry off
     * @param \Closure(): float $now a monotonic clock in seconds
     */
    private function __construct(
        private bool $enabled,
        private readonly string $runtime,
        private readonly string $version,
        private readonly string $region,
        private readonly int $boot,
        private readonly \Closure $sender,
        private readonly \Closure $now,
    ) {}

    /**
     * @param array<array-key, mixed> $env the function's environment, after the variable bundle and secrets
     * @param array<array-key, mixed> $runtimeConfig arco-runtime.json
     * @param string $runtime `laravel` or `process`
     * @param float $bootMs from the process start to the runtime being ready
     * @param (\Closure(string): void)|null $send sends one packet (tests); default: UDP to the collector
     * @param (\Closure(): float)|null $now a monotonic clock in seconds (tests)
     */
    public static function fromRuntime(array $env, array $runtimeConfig, string $runtime, float $bootMs, ?\Closure $send = null, ?\Closure $now = null): self
    {
        $version = $runtimeConfig['arcoVersion'] ?? null;
        $region = $env['AWS_REGION'] ?? null;
        $enabled = ($env['ARCO_TELEMETRY_DISABLE'] ?? null) !== '1'
            && ($runtimeConfig['telemetry'] ?? null) !== false
            && is_string($version) && preg_match(self::VERSION, $version) === 1
            && is_string($region) && preg_match(self::REGION, $region) === 1
            && in_array($runtime, self::RUNTIMES, true);
        if ($send === null) {
            [$host, $port] = self::destination($env['ARCO_TELEMETRY_HOST'] ?? null);
            $send = self::udp($host, $port);
        }
        return new self(
            $enabled,
            $runtime,
            is_string($version) ? $version : '',
            is_string($region) ? $region : '',
            self::bootBucket($bootMs),
            $send,
            $now ?? static fn (): float => hrtime(true) / 1e9,
        );
    }

    /** The boot duration as a bucket: 0 under 500 ms, 1 under 1 s, 2 under 3 s, 3 from 3 s. */
    public static function bootBucket(float $ms): int
    {
        return match (true) {
            ! ($ms >= 500) => 0,
            $ms < 1000 => 1,
            $ms < 3000 => 2,
            default => 3,
        };
    }

    /**
     * One packet line; the boot bucket is only sent with a cold start. Same encoding as runtime/lib/telemetry.js.
     *
     * @param array{runtime: string, kind: string, version: string, region: string, invocations: int, cold: bool, boot: int} $fields
     */
    public static function encode(array $fields): string
    {
        $line = sprintf(
            '%s r=%s k=%s v=%s g=%s i=%d c=%d',
            self::PROTOCOL,
            $fields['runtime'],
            $fields['kind'],
            $fields['version'],
            $fields['region'],
            $fields['invocations'],
            $fields['cold'] ? 1 : 0,
        );
        return $fields['cold'] ? "$line b={$fields['boot']}" : $line;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** One invocation of $kind (web, queue, console, schedule; anything else is ignored). An increment, no I/O. */
    public function count(string $kind): void
    {
        if ($this->enabled && in_array($kind, self::KINDS, true)) {
            $this->pending[$kind] = ($this->pending[$kind] ?? 0) + 1;
        }
    }

    /** After an invocation's response: the first packet at once, then at most one send a minute. */
    public function tick(): void
    {
        try {
            if (! $this->enabled || $this->pending === []) {
                return;
            }
            if ($this->cold || ($this->now)() - $this->lastSent >= self::SEND_INTERVAL_SECONDS) {
                $this->send();
            }
        } catch (\Throwable) {
            // Telemetry never fails an invocation.
        }
    }

    /** On shutdown: everything pending, best effort. */
    public function flush(): void
    {
        try {
            $this->send();
        } catch (\Throwable) {
            // Telemetry never fails a shutdown.
        }
    }

    private function send(): void
    {
        if (! $this->enabled || $this->pending === []) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];
        try {
            foreach ($pending as $kind => $invocations) {
                $line = self::encode([
                    'runtime' => $this->runtime, 'kind' => $kind, 'version' => $this->version, 'region' => $this->region,
                    'invocations' => $invocations, 'cold' => $this->cold, 'boot' => $this->boot,
                ]);
                $this->cold = false;
                ($this->sender)($line);
            }
        } catch (\Throwable) {
            // The collector cannot be reached: off for the environment's life.
            $this->enabled = false;
            return;
        }
        $this->lastSent = ($this->now)();
    }

    /**
     * `host:port` from ARCO_TELEMETRY_HOST (maintainers and tests), else the collector.
     *
     * @return array{string, int}
     */
    private static function destination(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [self::DEFAULT_HOST, self::DEFAULT_PORT];
        }
        $colon = strrpos($value, ':');
        if ($colon === false) {
            return [$value, self::DEFAULT_PORT];
        }
        $port = substr($value, $colon + 1);
        $host = substr($value, 0, $colon);
        if (preg_match('/^[0-9]{1,5}$/', $port) === 1 && (int) $port > 0 && (int) $port < 65536) {
            return [$host, (int) $port];
        }
        return [$host, self::DEFAULT_PORT];
    }

    /**
     * Sends each packet as one datagram. The first send resolves the host (IPv4, once) and opens the socket; a failure
     * there throws, which turns telemetry off. A packet that cannot be written is a lost count, never an error.
     *
     * @return \Closure(string): void
     */
    private static function udp(string $host, int $port): \Closure
    {
        $socket = null;
        return static function (string $packet) use ($host, $port, &$socket): void {
            if (! is_resource($socket)) {
                $address = @gethostbyname($host);
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new \RuntimeException("Cannot resolve $host.");
                }
                $opened = @stream_socket_client("udp://$address:$port", $errno, $error, 0);
                if ($opened === false) {
                    throw new \RuntimeException("Cannot open udp://$address:$port: $error");
                }
                $socket = $opened;
            }
            @fwrite($socket, $packet);
        };
    }
}
