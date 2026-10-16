<?php declare(strict_types=1);

namespace Multiapi\Arco\Console;

use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Aws\ResultInterface;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\ConsoleFunction;
use Multiapi\Arco\Deploy\DeployException;
use Psr\Http\Message\StreamInterface;

/** Console over lambda:InvokeFunction on <app>-<env>-console. */
final class LambdaConsole implements Console
{
    public function __construct(private readonly LambdaClient $lambda)
    {
    }

    /**
     * Client options for invocations: no SDK retries (a retried invoke would run the command a second
     * time) and an HTTP timeout above the function timeout (synchronous invokes last up to 15 minutes).
     *
     * @param bool|null $curl whether Guzzle uses ext-curl (detected when null); without it, the stream
     *                        handler is used and the CURLOPT_* constants do not exist
     * @return array<string, mixed>
     */
    public static function clientOptions(AppConfig $config, ?bool $curl = null): array
    {
        $curl ??= extension_loaded('curl') && defined('CURLOPT_TCP_KEEPIDLE') && defined('CURLOPT_TCP_KEEPINTVL');
        $http = ['timeout' => $config->consoleTimeout + 30, 'connect_timeout' => 10];
        if ($curl) {
            // Keeps a long, silent invoke alive through NAT gateways and firewalls.
            $http['curl'] = [CURLOPT_TCP_KEEPALIVE => 1, CURLOPT_TCP_KEEPIDLE => 30, CURLOPT_TCP_KEEPINTVL => 15];
        }
        return ['retries' => 0, 'http' => $http];
    }

    public function run(AppConfig $config, string $command, ?int $timeout = null, string $qualifier = ConsoleFunction::ALIAS): CommandResult
    {
        return $this->invoke($config, $qualifier, self::commandPayload($command, $timeout));
    }

    public function start(AppConfig $config, string $command, ?int $timeout = null): void
    {
        $this->call($config, [
            'FunctionName' => $config->consoleFunctionName(),
            'Qualifier' => ConsoleFunction::ALIAS,
            'InvocationType' => 'Event',
            'Payload' => json_encode(self::commandPayload($command, $timeout), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function tinker(AppConfig $config, string $code, ?string $context): CommandResult
    {
        return $this->invoke($config, ConsoleFunction::ALIAS, ['tinker' => ['code' => base64_encode($code), 'context' => $context]]);
    }

    /** @return array<string, string|int> */
    private static function commandPayload(string $command, ?int $timeout): array
    {
        return $timeout === null ? ['command' => $command] : ['command' => $command, 'timeout' => $timeout];
    }

    /** @param array<string, mixed> $payload */
    private function invoke(AppConfig $config, string $qualifier, array $payload): CommandResult
    {
        $result = $this->call($config, [
            'FunctionName' => $config->consoleFunctionName(),
            'Qualifier' => $qualifier,
            'InvocationType' => 'RequestResponse',
            'LogType' => 'Tail',
            'Payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
        $raw = $result['Payload'] ?? '';
        $body = $raw instanceof StreamInterface ? (string) $raw : (is_string($raw) ? $raw : '');
        $decoded = json_decode($body, true);
        $tail = self::logTail($result['LogResult'] ?? null);

        $error = $result['FunctionError'] ?? null;
        if (is_string($error) && $error !== '') {
            $type = is_array($decoded) && is_string($decoded['errorType'] ?? null) ? $decoded['errorType'] : $error;
            $message = is_array($decoded) && is_string($decoded['errorMessage'] ?? null) ? $decoded['errorMessage'] : $body;
            return new CommandResult(1, '', $type, $message, $tail);
        }
        if (! is_array($decoded) || ! is_int($decoded['exitCode'] ?? null) || ! is_string($decoded['output'] ?? null)) {
            return new CommandResult(1, '', 'Arco.InvalidResponse', $body, $tail);
        }
        return new CommandResult($decoded['exitCode'], $decoded['output'], null, null, $tail);
    }

    /** @param array{FunctionName: string, Qualifier: string, InvocationType: 'Event'|'RequestResponse', LogType?: 'Tail', Payload: string} $args */
    private function call(AppConfig $config, array $args): ResultInterface
    {
        try {
            return $this->lambda->invoke($args);
        } catch (LambdaException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                throw DeployException::make('console.not_deployed', ['function' => $config->consoleFunctionName(), 'environment' => $config->environment], $e);
            }
            throw $e;
        }
    }

    /** @return list<string> */
    private static function logTail(mixed $encoded): array
    {
        $text = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($text === false) {
            return [];
        }
        $lines = array_values(array_filter(array_map('rtrim', preg_split('/\R/', $text) ?: []), fn (string $line) => $line !== ''));
        return array_slice($lines, -20);
    }
}
