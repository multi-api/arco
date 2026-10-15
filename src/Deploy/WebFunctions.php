<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\LambdaWeb\Exception\LambdaWebException;
use Aws\LambdaWeb\LambdaWebClient;
use Multiapi\Arco\Config\AppConfig;

final class WebFunctions
{
    public const ENDPOINT = 'default';
    private const ROLE_RETRIES = 10;
    private const CONFLICT_RETRIES = 10;

    public function __construct(private readonly LambdaWebClient $client, private readonly Sleeper $sleeper)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(string $function): ?array
    {
        try {
            return self::normalize($this->call('GetWebFunction', ['functionName' => $function]));
        } catch (LambdaWebException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $revision
     * @param array<string, mixed> $endpoint
     * @param array<string, string> $tags
     * @param bool $roleJustCreated the execution role was created in this deploy and may still be propagating
     */
    public function create(string $function, array $revision, array $endpoint, array $tags, bool $roleJustCreated = false): string
    {
        $result = $this->withRoleRetry($roleJustCreated, fn () => $this->call('CreateWebFunction', [
            'functionName' => $function,
            'revisionConfig' => $revision,
            'endpointConfig' => $endpoint,
            'tags' => $tags,
        ]));
        $created = $result['revision'] ?? null;
        return self::text(is_array($created) ? ($created['revisionId'] ?? null) : null);
    }

    /**
     * @param array<string, mixed> $revision
     * @param bool $roleJustCreated the execution role was created in this deploy and may still be propagating
     */
    public function createRevision(string $function, array $revision, bool $roleJustCreated = false): string
    {
        $result = $this->withRoleRetry($roleJustCreated, fn () => $this->call('CreateWebFunctionRevision', ['functionName' => $function, ...$revision]));
        return self::text($result['revisionId'] ?? null);
    }

    public function waitRevision(string $function, string $revisionId, int $timeout = 600): void
    {
        $deadline = time() + $timeout;
        while (true) {
            $revision = $this->call('GetWebFunctionRevision', ['functionName' => $function, 'revisionId' => $revisionId]);
            $state = $revision['state'] ?? null;
            if ($state === 'Active') {
                return;
            }
            if ($state === 'Failed') {
                $errors = [];
                $raw = $revision['errors'] ?? [];
                foreach (is_array($raw) ? $raw : [] as $e) {
                    if (is_array($e)) {
                        $errors[] = self::text($e['errorCode'] ?? null) . ': ' . self::text($e['errorMessage'] ?? null) . ' (' . self::text($e['attribute'] ?? null) . ')';
                    }
                }
                throw DeployException::make('deploy.revision_failed', [
                    'revision' => $revisionId,
                    'reason' => trim(self::text($revision['stateReason'] ?? null) . ' ' . implode('; ', $errors)),
                ]);
            }
            if (time() >= $deadline) {
                throw DeployException::make('deploy.timeout', ['what' => "revision $revisionId"]);
            }
            $this->sleeper->sleep(2);
        }
    }

    /** @return array<string, mixed>|null */
    public function endpoint(string $function): ?array
    {
        try {
            return self::normalize($this->call('GetWebFunctionEndpoint', ['functionName' => $function, 'endpointName' => self::ENDPOINT]));
        } catch (LambdaWebException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
    }

    /** Retries ConflictException (endpoint still busy with a previous update) a bounded number of times. */
    public function route(string $function, string $revisionId, AppConfig $config): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->call('UpdateWebFunctionEndpoint', [
                    'functionName' => $function,
                    'endpointName' => self::ENDPOINT,
                    'authType' => RevisionFactory::authType($config),
                    'autoDeploymentMode' => 'Disabled',
                    'revisionWeights' => [['revisionId' => $revisionId, 'weight' => 100]],
                    ...RevisionFactory::limits($config),
                ]);
                return;
            } catch (LambdaWebException $e) {
                if ($e->getAwsErrorCode() !== 'ConflictException' || $attempt >= self::CONFLICT_RETRIES) {
                    throw $e;
                }
                $this->sleeper->sleep(3);
            }
        }
    }

    /**
     * Waits until the endpoint is idle; with $expectedRevision, also until that revision carries the traffic.
     *
     * @return array<string, mixed>
     */
    public function waitEndpoint(string $function, int $timeout = 600, ?string $expectedRevision = null): array
    {
        $deadline = time() + $timeout;
        while (true) {
            $endpoint = $this->endpoint($function) ?? throw DeployException::make('deploy.endpoint_missing', ['function' => $function]);
            $updating = ($endpoint['updateStatus'] ?? null) === 'InProgress';
            if (($endpoint['state'] ?? null) === 'Active' && ! $updating && ($expectedRevision === null || self::activeRevision($endpoint) === $expectedRevision)) {
                return $endpoint;
            }
            if (($endpoint['state'] ?? null) === 'Failed' || ($endpoint['updateStatus'] ?? null) === 'Failed') {
                throw DeployException::make('deploy.endpoint_failed', [
                    'reason' => self::text($endpoint['updateStatusReason'] ?? $endpoint['stateReason'] ?? ''),
                ]);
            }
            if (time() >= $deadline) {
                throw DeployException::make('deploy.timeout', ['what' => 'endpoint']);
            }
            $this->sleeper->sleep(2);
        }
    }

    public function delete(string $function, int $timeout = 600): void
    {
        try {
            $this->call('DeleteWebFunction', ['functionName' => $function]);
        } catch (LambdaWebException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return;
            }
            throw $e;
        }
        $deadline = time() + $timeout;
        while ($this->get($function) !== null) {
            if (time() >= $deadline) {
                throw DeployException::make('deploy.timeout', ['what' => "deleting $function"]);
            }
            $this->sleeper->sleep(3);
        }
    }

    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Runs an operation without the SDK's per-operation argument shapes (the payloads are built dynamically).
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function call(string $operation, array $args): array
    {
        return self::normalize($this->client->execute($this->client->getCommand($operation, $args))->toArray());
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private static function normalize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }
        return $out;
    }

    /** @param array<string, mixed> $endpoint */
    public static function activeRevision(array $endpoint): ?string
    {
        $raw = $endpoint['revisionWeights'] ?? [];
        $weights = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (is_array($entry) && is_string($entry['revisionId'] ?? null)) {
                $weights[] = ['revisionId' => $entry['revisionId'], 'weight' => is_int($entry['weight'] ?? null) ? $entry['weight'] : 0];
            }
        }
        usort($weights, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);
        return $weights[0]['revisionId'] ?? null;
    }

    /**
     * IAM roles take a few seconds to become assumable after creation. Only a role created in this
     * deploy is retried, and only when the AWS error message (not the request context that
     * getMessage() includes, such as the function name) mentions the role.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function withRoleRetry(bool $roleJustCreated, callable $call): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (LambdaWebException $e) {
                $roleProblem = $roleJustCreated
                    && in_array($e->getAwsErrorCode(), ['ValidationException', 'AccessDeniedException'], true)
                    && str_contains(strtolower((string) $e->getAwsErrorMessage()), 'role');
                if (! $roleProblem || $attempt >= self::ROLE_RETRIES) {
                    throw $e;
                }
                $this->sleeper->sleep(3);
            }
        }
    }
}
