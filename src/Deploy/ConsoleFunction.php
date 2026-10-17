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
    public const ALIAS = LambdaFunction::ALIAS;
    public const RUNTIME = LambdaFunction::RUNTIME;
    public const MEMORY_MB = 2048;
    /** /tmp holds the downloaded FrankenPHP binary (~170 MB) plus the app's storage. */
    public const EPHEMERAL_STORAGE_MB = 1024;
    /** Lambda's limit for a classic function's unzipped code (Web Functions accept larger packages). */
    public const MAX_UNZIPPED_BYTES = 262_144_000;

    private readonly LambdaFunction $function;

    public function __construct(private readonly LambdaClient $lambda, Sleeper $sleeper)
    {
        $this->function = new LambdaFunction($lambda, $sleeper);
    }

    /** @return array<string, mixed>|null */
    public function get(string $name): ?array
    {
        return $this->function->get($name);
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
        $code = ['S3Bucket' => $bucket, 'S3Key' => $key];
        if ($versionId !== null) {
            $code['S3ObjectVersion'] = $versionId;
        }
        return $this->function->deploy($config->consoleFunctionName(), 'arco.console', $code, self::settings($config, $roleArn, $variables), $tags, $roleJustCreated);
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
        $this->function->wait($name, $timeout);
    }

    public function liveVersion(string $name): ?string
    {
        return $this->function->liveVersion($name);
    }

    /** The live alias's ARN, or null when the function or the alias does not exist. */
    public function aliasArn(string $name): ?string
    {
        return $this->function->aliasArn($name);
    }

    /**
     * Points the live alias at $version and turns off Lambda's retries of asynchronous invocations
     * (`arco command --async`), so a command never runs twice. Workers get no such setting: SQS
     * redelivery is how a failed job comes back.
     *
     * @throws ConsoleRetriesNotDisabled when only the retry setting failed, after the alias moved
     */
    public function point(string $name, string $version): void
    {
        $this->function->point($name, $version);
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
        return $this->function->pruneVersions($name, $keep, $protected);
    }

    public function delete(string $name): void
    {
        $this->function->delete($name);
    }
}
