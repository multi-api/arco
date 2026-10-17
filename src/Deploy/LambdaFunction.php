<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;

/**
 * Classic Lambda function plumbing shared by the console and the queue workers (both run the console
 * zip through the console runtime): create or update, publish a version, the "live" alias, prune and
 * delete. What differs per function (name, handler, size, timeout, environment) is passed in.
 */
final class LambdaFunction
{
    public const ALIAS = 'live';
    public const RUNTIME = 'provided.al2023';
    private const ROLE_ERRORS = ['InvalidParameterValueException', 'AccessDeniedException'];

    /**
     * @param string $missingKey DeployException key when the function disappears during a wait
     * @param string $failedKey DeployException key when an update fails
     */
    public function __construct(
        private readonly LambdaClient $lambda,
        private readonly Sleeper $sleeper,
        private readonly string $missingKey = 'deploy.console_missing',
        private readonly string $failedKey = 'deploy.console_failed',
    ) {
    }

    /** @return array<string, mixed>|null */
    public function get(string $name): ?array
    {
        try {
            return LambdaHelpers::normalize($this->lambda->getFunctionConfiguration(['FunctionName' => $name])->toArray());
        } catch (LambdaException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Creates or updates the function with the code and publishes a version. $settings are the
     * parameters shared by create and update (Role, Description, MemorySize, EphemeralStorage, Timeout,
     * Environment, KMSKeyArn when set).
     *
     * @param array<string, mixed> $settings
     * @param array{S3Bucket: string, S3Key: string, S3ObjectVersion?: string} $code
     * @param array<string, string> $tags
     * @return string the published version
     */
    public function deploy(string $name, string $handler, array $code, array $settings, array $tags, bool $roleJustCreated = false): string
    {
        if ($this->get($name) === null) {
            LambdaHelpers::retryRolePropagation($this->sleeper, $roleJustCreated, self::ROLE_ERRORS, fn () => $this->execute('CreateFunction', [
                'FunctionName' => $name,
                'Runtime' => self::RUNTIME,
                'Architectures' => ['arm64'],
                // provided.al2023 runs bootstrap; the handler is unused.
                'Handler' => $handler,
                'Code' => $code,
                'Tags' => $tags,
                ...$settings,
            ]));
        } else {
            $this->wait($name);
            $this->lambda->updateFunctionCode(['FunctionName' => $name, ...$code]);
            $this->wait($name);
            // An empty KMSKeyArn puts back the AWS managed key when kmsKey() was removed from arco.php.
            LambdaHelpers::retryRolePropagation($this->sleeper, $roleJustCreated, self::ROLE_ERRORS, fn () => $this->execute('UpdateFunctionConfiguration', ['FunctionName' => $name, ...$settings + ['KMSKeyArn' => '']]));
        }
        $this->wait($name);

        $version = $this->lambda->publishVersion(['FunctionName' => $name])['Version'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new \UnexpectedValueException('Lambda returned no version.');
        }
        return $version;
    }

    /** Waits until the function is neither being created nor updated. */
    public function wait(string $name, int $timeout = 600): void
    {
        $deadline = $this->sleeper->now() + $timeout;
        while (true) {
            $function = $this->get($name) ?? throw DeployException::make($this->missingKey, ['function' => $name]);
            $state = $function['State'] ?? null;
            $update = $function['LastUpdateStatus'] ?? null;
            if ($state === 'Failed' || $update === 'Failed') {
                throw DeployException::make($this->failedKey, [
                    'function' => $name,
                    'reason' => LambdaHelpers::text($function['LastUpdateStatusReason'] ?? $function['StateReason'] ?? ''),
                ]);
            }
            if ($state !== 'Pending' && $update !== 'InProgress') {
                return;
            }
            if ($this->sleeper->now() >= $deadline) {
                throw DeployException::make('deploy.timeout', ['what' => $name]);
            }
            $this->sleeper->sleep(2);
        }
    }

    public function liveVersion(string $name): ?string
    {
        try {
            $version = $this->lambda->getAlias(['FunctionName' => $name, 'Name' => self::ALIAS])['FunctionVersion'] ?? null;
        } catch (LambdaException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
        return is_string($version) ? $version : null;
    }

    /** The live alias's ARN, or null when the function or the alias does not exist. */
    public function aliasArn(string $name): ?string
    {
        try {
            $arn = $this->lambda->getAlias(['FunctionName' => $name, 'Name' => self::ALIAS])['AliasArn'] ?? null;
        } catch (LambdaException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
        return is_string($arn) && $arn !== '' ? $arn : null;
    }

    /** Points the live alias at $version, creating it when missing. */
    public function point(string $name, string $version): void
    {
        $alias = ['FunctionName' => $name, 'Name' => self::ALIAS, 'FunctionVersion' => $version];
        try {
            $this->lambda->updateAlias($alias);
        } catch (LambdaException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
            $this->lambda->createAlias($alias);
        }
    }

    /**
     * Deletes published versions except the $keep newest, the version the live alias points to (read
     * now) and $protected, so Lambda code storage does not grow with every deploy. $LATEST stays.
     *
     * @param list<string> $protected
     * @return list<string> the deleted versions
     */
    public function pruneVersions(string $name, int $keep, array $protected): array
    {
        $live = $this->liveVersion($name);
        if ($live !== null) {
            $protected[] = $live;
        }
        $versions = [];
        $marker = null;
        do {
            $page = $this->lambda->listVersionsByFunction(['FunctionName' => $name, ...($marker !== null ? ['Marker' => $marker] : [])]);
            $listed = $page['Versions'] ?? [];
            foreach (is_array($listed) ? $listed : [] as $version) {
                $number = is_array($version) ? ($version['Version'] ?? null) : null;
                if (is_string($number) && ctype_digit($number)) {
                    $versions[] = (int) $number;
                }
            }
            $marker = is_string($page['NextMarker'] ?? null) && $page['NextMarker'] !== '' ? $page['NextMarker'] : null;
        } while ($marker !== null);

        rsort($versions);
        $deleted = [];
        foreach (array_slice($versions, $keep) as $number) {
            if (! in_array((string) $number, $protected, true)) {
                $deleted[] = (string) $number;
            }
        }
        sort($deleted, SORT_NUMERIC);
        foreach ($deleted as $version) {
            $this->lambda->deleteFunction(['FunctionName' => $name, 'Qualifier' => $version]);
        }
        return $deleted;
    }

    public function delete(string $name): void
    {
        try {
            $this->lambda->deleteFunction(['FunctionName' => $name]);
        } catch (LambdaException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
    }

    /**
     * Runs an operation without the SDK's per-operation argument shapes (the payloads are built dynamically).
     *
     * @param array<string, mixed> $args
     */
    private function execute(string $operation, array $args): mixed
    {
        return $this->lambda->execute($this->lambda->getCommand($operation, $args));
    }
}
