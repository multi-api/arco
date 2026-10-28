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
        return self::deployAll($this->sleeper, [$this->request($name, $handler, $code, $settings, $tags, $roleJustCreated)])[$name];
    }

    /**
     * What deploy() needs for one function, for deployAll().
     *
     * @param array<string, mixed> $settings
     * @param array{S3Bucket: string, S3Key: string, S3ObjectVersion?: string} $code
     * @param array<string, string> $tags
     */
    public function request(string $name, string $handler, array $code, array $settings, array $tags, bool $roleJustCreated = false): FunctionDeploy
    {
        return new FunctionDeploy($this, $name, $handler, $code, $settings, $tags, $roleJustCreated);
    }

    /**
     * Deploys several functions phase by phase instead of one after another: Lambda applies each
     * code and configuration update on its side, so the functions are updated together and the
     * deploy waits about as long as for the slowest one. Every function gets its code (create or
     * UpdateFunctionCode), then, once all are ready, its configuration, then, once all are ready
     * again, its version. A failure names the function it happened to.
     *
     * @param list<FunctionDeploy> $deploys
     * @return array<string, string> the published version by function name
     */
    public static function deployAll(Sleeper $sleeper, array $deploys): array
    {
        $all = [];
        $existing = [];
        foreach ($deploys as $deploy) {
            $all[$deploy->name] = $deploy->function;
            if ($deploy->function->get($deploy->name) !== null) {
                $existing[$deploy->name] = $deploy->function;
            }
        }

        self::waitAll($sleeper, $existing);
        foreach ($deploys as $deploy) {
            $function = $deploy->function;
            if (! isset($existing[$deploy->name])) {
                LambdaHelpers::retryRolePropagation($sleeper, $deploy->roleJustCreated, self::ROLE_ERRORS, fn () => $function->execute('CreateFunction', [
                    'FunctionName' => $deploy->name,
                    'Runtime' => self::RUNTIME,
                    'Architectures' => ['arm64'],
                    // provided.al2023 runs bootstrap; the handler is unused.
                    'Handler' => $deploy->handler,
                    'Code' => $deploy->code,
                    'Tags' => $deploy->tags,
                    ...$deploy->settings,
                ]));
            } else {
                $function->lambda->updateFunctionCode(['FunctionName' => $deploy->name, ...$deploy->code]);
            }
        }
        self::waitAll($sleeper, $all);

        foreach ($deploys as $deploy) {
            if (! isset($existing[$deploy->name])) {
                continue;
            }
            $function = $deploy->function;
            // An empty KMSKeyArn puts back the AWS managed key when kmsKey() was removed from arco.php.
            LambdaHelpers::retryRolePropagation($sleeper, $deploy->roleJustCreated, self::ROLE_ERRORS, fn () => $function->execute('UpdateFunctionConfiguration', ['FunctionName' => $deploy->name, ...$deploy->settings + ['KMSKeyArn' => '']]));
        }
        self::waitAll($sleeper, $existing);

        $versions = [];
        foreach ($deploys as $deploy) {
            $version = $deploy->function->lambda->publishVersion(['FunctionName' => $deploy->name])['Version'] ?? null;
            if (! is_string($version) || $version === '') {
                throw new \UnexpectedValueException("Lambda returned no version for $deploy->name.");
            }
            $versions[$deploy->name] = $version;
        }
        return $versions;
    }

    /** Waits until the function is neither being created nor updated. */
    public function wait(string $name, int $timeout = 600): void
    {
        self::waitAll($this->sleeper, [$name => $this], $timeout);
    }

    /**
     * Waits until none of the functions is being created or updated, polling those still busy every
     * 2 s, so the wait lasts as long as the slowest one.
     *
     * @param array<string, self> $functions by function name
     */
    private static function waitAll(Sleeper $sleeper, array $functions, int $timeout = 600): void
    {
        $deadline = $sleeper->now() + $timeout;
        while (true) {
            $functions = array_filter($functions, fn (self $function, string $name) => ! $function->ready($name), ARRAY_FILTER_USE_BOTH);
            if ($functions === []) {
                return;
            }
            if ($sleeper->now() >= $deadline) {
                throw DeployException::make('deploy.timeout', ['what' => implode(', ', array_keys($functions))]);
            }
            $sleeper->sleep(2);
        }
    }

    /** Whether the function is neither being created nor updated; a missing or failed function stops the deploy. */
    private function ready(string $name): bool
    {
        $function = $this->get($name) ?? throw DeployException::make($this->missingKey, ['function' => $name]);
        $state = $function['State'] ?? null;
        $update = $function['LastUpdateStatus'] ?? null;
        if ($state === 'Failed' || $update === 'Failed') {
            throw DeployException::make($this->failedKey, [
                'function' => $name,
                'reason' => LambdaHelpers::text($function['LastUpdateStatusReason'] ?? $function['StateReason'] ?? ''),
            ]);
        }
        return $state !== 'Pending' && $update !== 'InProgress';
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

    /**
     * The versions ($LATEST included) whose VpcConfig names subnets, with those subnets and their security groups
     * (sorted). A missing function has none.
     *
     * @return array<string, array{subnets: list<string>, groups: list<string>}> by version
     */
    public function vpcVersions(string $name): array
    {
        $versions = [];
        $marker = null;
        try {
            do {
                $page = $this->lambda->listVersionsByFunction(['FunctionName' => $name, ...($marker !== null ? ['Marker' => $marker] : [])]);
                $listed = $page['Versions'] ?? [];
                foreach (is_array($listed) ? $listed : [] as $version) {
                    $number = is_array($version) ? ($version['Version'] ?? null) : null;
                    $vpc = is_array($version) && is_array($version['VpcConfig'] ?? null) ? $version['VpcConfig'] : [];
                    $subnets = array_values(array_filter(is_array($vpc['SubnetIds'] ?? null) ? $vpc['SubnetIds'] : [], 'is_string'));
                    $groups = array_values(array_filter(is_array($vpc['SecurityGroupIds'] ?? null) ? $vpc['SecurityGroupIds'] : [], 'is_string'));
                    sort($groups);
                    if (is_string($number) && $subnets !== []) {
                        $versions[$number] = ['subnets' => $subnets, 'groups' => $groups];
                    }
                }
                $marker = is_string($page['NextMarker'] ?? null) && $page['NextMarker'] !== '' ? $page['NextMarker'] : null;
            } while ($marker !== null);
        } catch (LambdaException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
        return $versions;
    }

    /** Deletes one published version; one already gone is not an error. */
    public function deleteVersion(string $name, string $version): void
    {
        try {
            $this->lambda->deleteFunction(['FunctionName' => $name, 'Qualifier' => $version]);
        } catch (LambdaException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
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
