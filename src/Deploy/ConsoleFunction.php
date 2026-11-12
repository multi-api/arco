<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Multiapi\Arco\Arco;
use Multiapi\Arco\Config\AppConfig;

/**
 * The <app>-<env>-console function: a classic Lambda function (provided.al2023, arm64) running the
 * console zip (the web package without FrankenPHP, see ConsolePackager) through the console runtime
 * (bootstrap at the zip root, which downloads the binary from S3). A process app's console runs its web zip on
 * nodejs24.x (worker.js, for its schedules). Each deploy publishes a version; the "live" alias marks the version that matches the revision serving traffic.
 */
final class ConsoleFunction
{
    public const ALIAS = LambdaFunction::ALIAS;
    public const RUNTIME = LambdaFunction::RUNTIME;
    /** Default memory of the console function; console(memory:) overrides it. */
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
    public function deploy(AppConfig $config, string $bucket, string $key, ?string $versionId, string $roleArn, array $variables, array $tags, bool $roleJustCreated = false, bool $detachVpc = false): string
    {
        $request = $this->request($config, $bucket, $key, $versionId, $roleArn, $variables, $tags, $roleJustCreated, $detachVpc);
        return $this->function->deploy($request->name, $request->handler, $request->code, $request->settings, $request->tags, $roleJustCreated);
    }

    /**
     * The function's part in LambdaFunction::deployAll(), which updates it together with the workers.
     *
     * @param array<string, string> $variables
     * @param array<string, string> $tags
     */
    public function request(AppConfig $config, string $bucket, string $key, ?string $versionId, string $roleArn, array $variables, array $tags, bool $roleJustCreated = false, bool $detachVpc = false): FunctionDeploy
    {
        $code = ['S3Bucket' => $bucket, 'S3Key' => $key];
        if ($versionId !== null) {
            $code['S3ObjectVersion'] = $versionId;
        }
        if ($config->isProcess()) {
            // worker.js posts each schedule's event to its route, for at most its timeout, read from ARCO_SCHEDULES.
            $schedules = [];
            foreach ($config->schedules as $schedule) {
                $schedules[$schedule->name] = ['route' => $schedule->route(), 'timeout' => $schedule->timeout];
            }
            $variables = [...$variables, 'ARCO_SCHEDULES' => json_encode((object) $schedules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
            return $this->function->request($config->consoleFunctionName(), WorkerFunctions::PROCESS_HANDLER, $code, self::settings($config, $roleArn, $variables, $detachVpc), $tags, $roleJustCreated, Arco::LAMBDA_RUNTIME);
        }
        return $this->function->request($config->consoleFunctionName(), 'arco.console', $code, self::settings($config, $roleArn, $variables, $detachVpc), $tags, $roleJustCreated);
    }

    /**
     * @param array<string, string> $variables
     * @param bool $detachVpc send an empty VpcConfig (a removal deploy takes the function out of the VPC)
     * @return array<string, mixed>
     */
    public static function settings(AppConfig $config, string $roleArn, array $variables, bool $detachVpc = false): array
    {
        $settings = [
            'Role' => $roleArn,
            'Description' => 'Arco console function (Arco ' . Arco::version() . ')',
            'MemorySize' => $config->consoleMemory,
            'EphemeralStorage' => ['Size' => self::EPHEMERAL_STORAGE_MB],
            'Timeout' => self::timeout($config),
            // Lambda rejects empty values; an unset variable reads the same way in PHP.
            'Environment' => ['Variables' => array_filter($variables, fn (string $value) => $value !== '')],
        ];
        if ($config->kmsKey !== null) {
            $settings['KMSKeyArn'] = $config->kmsKey;
        }
        if (RevisionFactory::usesVpc($config) && $config->vpc !== null) {
            $settings['VpcConfig'] = ['SubnetIds' => $config->vpc->subnets, 'SecurityGroupIds' => $config->vpc->securityGroups];
        } elseif ($detachVpc) {
            // Empty lists take a function out of the VPC; an update without the key would leave it there.
            $settings['VpcConfig'] = ['SubnetIds' => [], 'SecurityGroupIds' => []];
        }
        return $settings;
    }

    /** Seconds a process console adds to its longest schedule: worker.js's 2 s margin and up to 10 s of process start. */
    public const PROCESS_OVERHEAD_SECONDS = 12;

    /**
     * The function's timeout: the console setting, or for a process app the longest schedule's timeout plus
     * PROCESS_OVERHEAD_SECONDS, at most Lambda's 900 (worker.js bounds each schedule's POST by its own timeout).
     */
    public static function timeout(AppConfig $config): int
    {
        if (! $config->isProcess() || $config->schedules === []) {
            return $config->consoleTimeout;
        }
        $longest = max(array_map(static fn ($schedule): int => $schedule->timeout, $config->schedules));
        return min(900, $longest + self::PROCESS_OVERHEAD_SECONDS);
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
