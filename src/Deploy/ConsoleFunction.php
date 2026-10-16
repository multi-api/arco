<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Multiapi\Arco\Arco;
use Multiapi\Arco\Config\AppConfig;

/**
 * The <app>-<env>-console function: a classic Lambda function (provided.al2023, arm64) running the
 * console zip (the web package without FrankenPHP, see ConsolePackager) through the console runtime
 * (bootstrap at the zip root, which downloads the binary from S3). Each deploy
 * publishes a version; the "live" alias marks the version that matches the revision serving traffic.
 */
final class ConsoleFunction
{
    public const ALIAS = 'live';
    public const RUNTIME = 'provided.al2023';
    public const MEMORY_MB = 2048;
    /** /tmp holds the downloaded FrankenPHP binary (~170 MB) plus the app's storage. */
    public const EPHEMERAL_STORAGE_MB = 1024;
    /** Lambda's limit for a classic function's unzipped code (Web Functions accept larger packages). */
    public const MAX_UNZIPPED_BYTES = 262_144_000;
    private const ROLE_ERRORS = ['InvalidParameterValueException', 'AccessDeniedException'];

    public function __construct(private readonly LambdaClient $lambda, private readonly Sleeper $sleeper)
    {
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
     * Creates or updates the function with the package and publishes a version.
     *
     * @param array<string, string> $variables
     * @param array<string, string> $tags
     * @return string the published version
     */
    public function deploy(AppConfig $config, string $bucket, string $key, ?string $versionId, string $roleArn, array $variables, array $tags, bool $roleJustCreated = false): string
    {
        $name = $config->consoleFunctionName();
        $code = ['S3Bucket' => $bucket, 'S3Key' => $key];
        if ($versionId !== null) {
            $code['S3ObjectVersion'] = $versionId;
        }
        $settings = self::settings($config, $roleArn, $variables);

        if ($this->get($name) === null) {
            LambdaHelpers::retryRolePropagation($this->sleeper, $roleJustCreated, self::ROLE_ERRORS, fn () => $this->execute('CreateFunction', [
                'FunctionName' => $name,
                'Runtime' => self::RUNTIME,
                'Architectures' => ['arm64'],
                // provided.al2023 runs bootstrap; the handler is unused.
                'Handler' => 'arco.console',
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

    /**
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    public static function settings(AppConfig $config, string $roleArn, array $variables): array
    {
        $settings = [
            'Role' => $roleArn,
            'Description' => 'Arco console function (Arco ' . Arco::version() . ')',
            'MemorySize' => self::MEMORY_MB,
            'EphemeralStorage' => ['Size' => self::EPHEMERAL_STORAGE_MB],
            'Timeout' => $config->consoleTimeout,
            // Lambda rejects empty values; an unset variable reads the same way in PHP.
            'Environment' => ['Variables' => array_filter($variables, fn (string $value) => $value !== '')],
        ];
        if ($config->kmsKey !== null) {
            $settings['KMSKeyArn'] = $config->kmsKey;
        }
        return $settings;
    }

    /** Waits until the function is neither being created nor updated. */
    public function wait(string $name, int $timeout = 600): void
    {
        $deadline = $this->sleeper->now() + $timeout;
        while (true) {
            $function = $this->get($name) ?? throw DeployException::make('deploy.console_missing', ['function' => $name]);
            $state = $function['State'] ?? null;
            $update = $function['LastUpdateStatus'] ?? null;
            if ($state === 'Failed' || $update === 'Failed') {
                throw DeployException::make('deploy.console_failed', [
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

    /**
     * Points the live alias at $version and turns off Lambda's retries of asynchronous invocations
     * (`arco command --async`), so a command never runs twice.
     *
     * @throws ConsoleRetriesNotDisabled when only the retry setting failed, after the alias moved
     */
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
        try {
            $this->lambda->putFunctionEventInvokeConfig([
                'FunctionName' => $name,
                'Qualifier' => self::ALIAS,
                'MaximumRetryAttempts' => 0,
                'MaximumEventAgeInSeconds' => 3600,
            ]);
        } catch (LambdaException $e) {
            // The alias already moved: told apart so the caller does not report it as unmoved.
            throw new ConsoleRetriesNotDisabled($e->getAwsErrorMessage() ?: $e->getMessage(), 0, $e);
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
