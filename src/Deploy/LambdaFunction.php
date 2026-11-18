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
     * @param array{S3Bucket: string, S3Key: string, S3ObjectVersion?: string}|array{ZipFile: string} $code
     * @param array<string, string> $tags
     * @return string the published version
     */
    public function deploy(string $name, string $handler, array $code, array $settings, array $tags, bool $roleJustCreated = false): string
    {
        return self::deployAll($this->sleeper, [$this->request($name, $handler, $code, $settings, $tags, $roleJustCreated)])[$name];
    }

    /**
     * deploy() without publishing a version: for a function that is invoked unqualified and has no alias. Code
     * is sent inline, as {ZipFile: bytes}. Waits and reconfigures exactly as deploy() does.
     *
     * @param array<string, mixed> $settings
     * @param array{ZipFile: string} $code
     * @param array<string, string> $tags
     */
    public function deployInline(string $name, string $handler, array $code, array $settings, array $tags, bool $roleJustCreated, string $runtime): void
    {
        self::deployAll($this->sleeper, [new FunctionDeploy($this, $name, $handler, $code, $settings, $tags, $roleJustCreated, $runtime, publish: false)]);
    }

    /**
     * What deploy() needs for one function, for deployAll().
     *
     * @param array<string, mixed> $settings
     * @param array{S3Bucket: string, S3Key: string, S3ObjectVersion?: string}|array{ZipFile: string} $code
     * @param array<string, string> $tags
     */
    public function request(string $name, string $handler, array $code, array $settings, array $tags, bool $roleJustCreated = false, string $runtime = self::RUNTIME): FunctionDeploy
    {
        return new FunctionDeploy($this, $name, $handler, $code, $settings, $tags, $roleJustCreated, $runtime);
    }

    /**
     * Deploys several functions phase by phase instead of one after another: Lambda applies each
     * code and configuration update on its side, so the functions are updated together and the
     * deploy waits about as long as for the slowest one. Every function gets its code (create or
     * UpdateFunctionCode), then, once all are ready, its configuration, then, once all are ready
     * again, its version. A failure names the function it happened to. The configuration is only
     * sent to a function whose current one differs from $settings (configured()), or whose role
     * was just created.
     *
     * @param list<FunctionDeploy> $deploys
     * @return array<string, string> the published version by function name (none for a deploy that does not publish)
     */
    public static function deployAll(Sleeper $sleeper, array $deploys): array
    {
        $all = [];
        $existing = [];
        $reconfigure = [];
        foreach ($deploys as $deploy) {
            $all[$deploy->name] = $deploy->function;
            $current = $deploy->function->get($deploy->name);
            if ($current !== null) {
                $existing[$deploy->name] = $deploy->function;
                // Read before the code update, which leaves the configuration as it is.
                if ($deploy->roleJustCreated || ! self::configured($current, $deploy->settings)) {
                    $reconfigure[$deploy->name] = $deploy->function;
                }
            }
        }

        self::waitAll($sleeper, $existing);
        foreach ($deploys as $deploy) {
            $function = $deploy->function;
            if (! isset($existing[$deploy->name])) {
                LambdaHelpers::retryRolePropagation($sleeper, $deploy->roleJustCreated, self::ROLE_ERRORS, fn () => $function->execute('CreateFunction', [
                    'FunctionName' => $deploy->name,
                    'Runtime' => $deploy->runtime,
                    'Architectures' => ['arm64'],
                    // provided.al2023 runs bootstrap and the handler is unused; nodejs24.x runs worker.handler.
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
            if (! isset($reconfigure[$deploy->name])) {
                continue;
            }
            $function = $deploy->function;
            // An empty KMSKeyArn puts back the AWS managed key when kmsKey() was removed from arco.php.
            LambdaHelpers::retryRolePropagation($sleeper, $deploy->roleJustCreated, self::ROLE_ERRORS, fn () => $function->execute('UpdateFunctionConfiguration', ['FunctionName' => $deploy->name, ...$deploy->settings + ['KMSKeyArn' => '']]));
        }
        self::waitAll($sleeper, $reconfigure);

        $versions = [];
        foreach ($deploys as $deploy) {
            if (! $deploy->publish) {
                continue;
            }
            $version = $deploy->function->lambda->publishVersion(['FunctionName' => $deploy->name])['Version'] ?? null;
            if (! is_string($version) || $version === '') {
                throw new \UnexpectedValueException("Lambda returned no version for $deploy->name.");
            }
            $versions[$deploy->name] = $version;
        }
        return $versions;
    }

    /**
     * Whether UpdateFunctionConfiguration with $settings would change nothing on a function whose
     * GetFunctionConfiguration is $current: every setting Arco sends is compared, with the KMSKeyArn
     * reset deployAll() adds. A setting it does not know how to compare counts as a change.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $settings
     */
    public static function configured(array $current, array $settings): bool
    {
        foreach ($settings + ['KMSKeyArn' => ''] as $key => $wanted) {
            $have = $current[$key] ?? null;
            $same = match ($key) {
                'Role', 'Description', 'MemorySize', 'Timeout' => $have !== null && $have === $wanted,
                // The AWS managed key: no KMSKeyArn in the configuration.
                'KMSKeyArn' => (is_string($have) ? $have : '') === $wanted,
                'EphemeralStorage' => is_array($have) && is_array($wanted) && ($have['Size'] ?? null) === ($wanted['Size'] ?? false),
                'Environment' => is_array($wanted) && self::sameVariables($have, $wanted),
                'VpcConfig' => is_array($wanted) && self::sameVpc($have, $wanted),
                default => false,
            };
            if (! $same) {
                return false;
            }
        }
        return true;
    }

    /** @param array<mixed> $wanted ['Variables' => [...]] */
    private static function sameVariables(mixed $have, array $wanted): bool
    {
        if (array_keys($wanted) !== ['Variables'] || ! is_array($wanted['Variables'])) {
            return false;
        }
        // Variables Lambda could not decrypt come back as Error instead.
        if (is_array($have) && isset($have['Error'])) {
            return false;
        }
        $variables = is_array($have) && is_array($have['Variables'] ?? null) ? $have['Variables'] : [];
        $want = $wanted['Variables'];
        ksort($variables);
        ksort($want);
        return $variables === $want;
    }

    /** @param array<mixed> $wanted ['SubnetIds' => [...], 'SecurityGroupIds' => [...]] */
    private static function sameVpc(mixed $have, array $wanted): bool
    {
        $keys = array_keys($wanted);
        sort($keys);
        if ($keys !== ['SecurityGroupIds', 'SubnetIds']) {
            return false;
        }
        foreach ($keys as $key) {
            $current = is_array($have) && is_array($have[$key] ?? null) ? $have[$key] : [];
            $want = is_array($wanted[$key]) ? $wanted[$key] : [null];
            sort($current);
            sort($want);
            if ($current !== $want) {
                return false;
            }
        }
        return true;
    }

    /** Waits until the function is neither being created nor updated. */
    public function wait(string $name, int $timeout = 600): void
    {
        self::waitAll($this->sleeper, [$name => $this], $timeout);
    }

    /** Pauses between the polls of a wait, in ms; the last one repeats. Most updates finish within a few seconds. */
    private const POLL_MS = [500, 500, 1000, 1000, 2000];

    /**
     * Waits until none of the functions is being created or updated, polling those still busy after
     * 0.5, 1, 2 and 3 s, then every 2 s, so the wait lasts about as long as the slowest one.
     *
     * @param array<string, self> $functions by function name
     */
    private static function waitAll(Sleeper $sleeper, array $functions, int $timeout = 600): void
    {
        $deadline = $sleeper->now() + $timeout;
        $poll = 0;
        while (true) {
            $functions = array_filter($functions, fn (self $function, string $name) => ! $function->ready($name), ARRAY_FILTER_USE_BOTH);
            if ($functions === []) {
                return;
            }
            if ($sleeper->now() >= $deadline) {
                throw DeployException::make('deploy.timeout', ['what' => implode(', ', array_keys($functions))]);
            }
            $sleeper->sleepMs(self::POLL_MS[min($poll++, count(self::POLL_MS) - 1)]);
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
