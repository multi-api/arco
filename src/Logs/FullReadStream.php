<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A response body whose read($n) returns exactly $n bytes unless the stream ends. The SDK's
 * event-stream decoder reads each message's trailing CRC with a single read(4), which fails with
 * "Message checksum mismatch" whenever the network delivers fewer bytes (see LiveTailSpikeTest).
 *
 * Waiting for bytes never blocks forever: between reads it checks an optional stop callback (Ctrl+C)
 * and an optional idle deadline, and a read whose Guzzle read_timeout elapsed counts as "no data yet".
 */
final class FullReadStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /** Request option (in '@http'): callable(): bool, true ends a waiting read with StreamInterrupted. */
    public const STOP_OPTION = 'arco_stop';
    /** Request option (in '@http'): seconds without a byte after which a read ends with StreamInterrupted. */
    public const IDLE_OPTION = 'arco_idle_seconds';

    /** @var StreamInterface */
    private $stream;

    /** @param (\Closure(): bool)|null $stop */
    public function __construct(StreamInterface $stream, private readonly ?\Closure $stop = null, private readonly ?float $idleSeconds = null)
    {
        $this->stream = $stream;
    }

    /**
     * Wraps an SDK http_handler: event-stream response bodies get full reads, others are untouched.
     * STOP_OPTION and IDLE_OPTION are taken out of the request options before they reach $http.
     */
    public static function wrap(callable $http): \Closure
    {
        return static function (RequestInterface $request, array $options) use ($http): PromiseInterface {
            $stopOption = $options[self::STOP_OPTION] ?? null;
            $stop = is_callable($stopOption) ? static fn (): bool => (bool) $stopOption() : null;
            $idleOption = $options[self::IDLE_OPTION] ?? null;
            $idle = is_int($idleOption) || is_float($idleOption) ? (float) $idleOption : null;
            unset($options[self::STOP_OPTION], $options[self::IDLE_OPTION]);

            $promise = $http($request, $options);
            if (! $promise instanceof PromiseInterface) {
                throw new \UnexpectedValueException('The http_handler must return a promise.');
            }
            return $promise->then(static function (mixed $response) use ($stop, $idle): ResponseInterface {
                if (! $response instanceof ResponseInterface) {
                    throw new \UnexpectedValueException('The http_handler must resolve to a response.');
                }
                return str_starts_with($response->getHeaderLine('Content-Type'), 'application/vnd.amazon.eventstream')
                    ? $response->withBody(new self($response->getBody(), $stop, $idle))
                    : $response;
            });
        };
    }

    /**
     * @param int $length
     * @throws StreamInterrupted when a stop is requested or the stream stays silent past the idle deadline
     */
    public function read($length): string
    {
        $data = '';
        $lastByte = microtime(true);
        while (strlen($data) < $length) {
            if ($this->stop !== null && ($this->stop)()) {
                throw StreamInterrupted::stopped();
            }
            try {
                $chunk = $this->stream->read($length - strlen($data));
            } catch (TimeoutException) {
                $chunk = ''; // read_timeout elapsed without data: not an error by itself
            }
            if ($chunk === '') {
                if ($this->stream->eof()) {
                    break;
                }
                if ($this->idleSeconds !== null && microtime(true) - $lastByte >= $this->idleSeconds) {
                    throw StreamInterrupted::stalled($this->idleSeconds);
                }
                usleep(1000);
                continue;
            }
            $lastByte = microtime(true);
            $data .= $chunk;
        }
        return $data;
    }
}
