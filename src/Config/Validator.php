<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

use Multiapi\Arco\Arco;
use Multiapi\Arco\Binary\BinaryException;
use Multiapi\Arco\Binary\ElfInspector;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\ConsoleRuntime\ArgumentSplitter;
use Multiapi\Arco\Secrets\InvalidReference;
use Multiapi\Arco\Secrets\SecretReference;

final class Validator
{
    private const NAME = '/^[a-z][a-z0-9-]*$/';
    private const VARIABLE = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const SECRET_LIKE = '/(_KEY|_SECRET|_PASSWORD|_TOKEN)$/';
    private const LOG_LEVELS = ['trace', 'debug', 'info', 'warn', 'error', 'fatal'];
    /** Environment variables Lambda sets itself and refuses in function configuration. */
    private const RESERVED = [
        '_HANDLER', '_X_AMZN_TRACE_ID', 'AWS_DEFAULT_REGION', 'AWS_REGION', 'AWS_EXECUTION_ENV',
        'AWS_ACCESS_KEY', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SECRET_KEY',
        'AWS_SESSION_TOKEN', 'LAMBDA_TASK_ROOT', 'LAMBDA_RUNTIME_DIR',
    ];
    private const RESERVED_PREFIXES = ['AWS_LAMBDA_', 'AWS_XRAY_'];
    private const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';
    private const CERTIFICATE = '/^arn:aws[a-z-]*:acm:us-east-1:\d{12}:certificate\/[A-Za-z0-9-]+$/';
    public const MAX_DOMAINS = 10;

    public function __construct(private readonly ?RuntimeManifest $manifest = null)
    {
    }

    public function validate(AppConfig $config, string $projectDir): ValidationResult
    {
        $result = new ValidationResult();

        if (! preg_match(self::NAME, $config->app)) {
            $result->error('validation.app_name', ['name' => $config->app]);
        }
        if (! preg_match(self::NAME, $config->environment)) {
            $result->error('validation.environment_name', ['name' => $config->environment]);
        }
        if (strlen($config->functionName()) > 64) {
            $result->error('validation.function_name_length', ['name' => $config->functionName()]);
        } elseif (strlen($config->consoleFunctionName()) > 64) {
            $result->error('validation.console_function_name_length', ['name' => $config->consoleFunctionName()]);
        }
        if ($config->region === null) {
            $result->error('validation.region_missing');
        } elseif (! in_array($config->region, Arco::REGIONS, true)) {
            $result->error('validation.region', ['region' => $config->region, 'regions' => implode(', ', Arco::REGIONS)]);
        }
        $limits = ['concurrency' => $config->concurrency, 'timeout' => $config->timeout, 'maxInstances' => $config->maxInstances, 'rateLimit' => $config->rateLimit, 'consoleTimeout' => $config->consoleTimeout];
        foreach ($limits as $setting => $value) {
            self::addSettingError($result, $setting, $value);
        }
        if (! in_array($config->auth, ['public', 'iam'], true)) {
            $result->error('validation.auth', ['value' => $config->auth]);
        }
        self::addSettingError($result, 'logLevel', $config->logLevel);
        if ($config->account !== null && ! preg_match('/^\d{12}$/', $config->account)) {
            $result->error('validation.account', ['value' => $config->account]);
        }

        $runtime = $config->runtime;
        if ($runtime->workersMin < 1 || $runtime->workersMax < $runtime->workersMin) {
            $result->error('validation.workers', ['min' => $runtime->workersMin, 'max' => $runtime->workersMax]);
        }
        if ($runtime->threads < 1) {
            $result->error('validation.threads', ['value' => $runtime->threads]);
        }
        foreach ($runtime->phpIni as $key => $value) {
            if (! preg_match('/^[A-Za-z0-9_.-]+$/', (string) $key)) {
                $result->error('validation.php_ini_key', ['key' => (string) $key]);
            } elseif (is_string($value) && preg_match('/[\r\n\0]/', $value)) {
                $result->error('validation.php_ini_value', ['key' => (string) $key]);
            }
        }
        if (! in_array($runtime->mode, ['direct', 'proxy'], true)) {
            $result->error('validation.mode', ['value' => $runtime->mode]);
        }
        if ($runtime->binary !== null) {
            if ($runtime->build !== null || $runtime->frankenphp !== null) {
                $result->warning('validation.binary_overrides_build');
            }
            $path = str_starts_with($runtime->binary, '/') ? $runtime->binary : $projectDir . '/' . $runtime->binary;
            if (! is_file($path)) {
                $result->error('validation.binary_missing', ['path' => $runtime->binary]);
            } elseif (! ElfInspector::isLinuxArm64($path)) {
                $result->error('validation.binary_arch', ['path' => $runtime->binary]);
            }
        } else {
            try {
                ($this->manifest ?? RuntimeManifest::load())->resolve($runtime->build, $runtime->frankenphp);
            } catch (BinaryException $e) {
                $result->error($e->key, $e->params);
            }
        }

        foreach ($config->permissions as $index => $statement) {
            $field = self::invalidStatementField($statement);
            if ($field !== null) {
                $result->error('validation.permission', ['index' => $index, 'field' => $field]);
            }
        }
        if ($config->permissions !== [] && $config->role !== null) {
            $result->warning('validation.permissions_with_role');
        }

        self::validateDomains($config, $result);

        foreach ($config->deployCommands as $index => $command) {
            if (trim($command) === '') {
                $result->error('validation.deploy_command_empty', ['index' => $index]);
                continue;
            }
            try {
                ArgumentSplitter::split($command);
            } catch (\InvalidArgumentException $e) {
                $result->error('validation.deploy_command', ['command' => $command, 'reason' => $e->getMessage()]);
            }
        }

        foreach ($config->variables as $name => $value) {
            if (self::isReserved($name)) {
                $result->error('validation.reserved_variable', ['variable' => $name]);
                continue;
            }
            if (! preg_match(self::VARIABLE, $name)) {
                $result->error('validation.variable_name', ['variable' => $name]);
                continue;
            }
            if ($value === '') {
                $result->warning('validation.empty_variable', ['variable' => $name]);
                continue;
            }
            if (SecretReference::isReference($value)) {
                try {
                    SecretReference::parse($value);
                } catch (InvalidReference $e) {
                    $result->error('validation.invalid_reference', ['variable' => $name, 'reason' => $e->getMessage()]);
                }
                continue;
            }
            if (preg_match(self::SECRET_LIKE, $name)) {
                $result->warning('validation.plaintext_secret', ['variable' => $name]);
            }
        }

        return $result;
    }

    /**
     * The error for one scalar setting value (concurrency, timeout, maxInstances, rateLimit,
     * logLevel), or null when it is valid. Used by validate() and by `arco set` before writing.
     *
     * @return array{key: string, params: array<string, string|int>}|null
     */
    public static function settingError(string $setting, int|string $value): ?array
    {
        [$key, $ok] = match ($setting) {
            'concurrency' => ['validation.concurrency', is_int($value) && $value >= 1 && $value <= 128],
            'timeout' => ['validation.timeout', is_int($value) && $value >= 3 && $value <= 900],
            // AWS limits: maxEnvironments 2–10,000; rateLimit a multiple of 100 up to 1,000, then a
            // multiple of 1,000 up to 10,000 (0 would block all traffic, so Arco does not accept it).
            'maxInstances' => ['validation.max_instances', is_int($value) && $value >= 2 && $value <= 10000],
            'rateLimit' => ['validation.rate_limit', is_int($value) && $value >= 100 && $value <= 10000
                && $value % ($value <= 1000 ? 100 : 1000) === 0],
            'consoleTimeout' => ['validation.console_timeout', is_int($value) && $value >= 3 && $value <= 900],
            'logLevel' => ['validation.log_level', in_array($value, self::LOG_LEVELS, true)],
            default => throw new \InvalidArgumentException("Unknown setting $setting."),
        };
        return $ok ? null : ['key' => $key, 'params' => ['value' => $value]];
    }

    private static function addSettingError(ValidationResult $result, string $setting, int|string|null $value): void
    {
        $error = $value === null ? null : self::settingError($setting, $value);
        if ($error !== null) {
            $result->error($error['key'], $error['params']);
        }
    }

    private static function validateDomains(AppConfig $config, ValidationResult $result): void
    {
        if ($config->domains === []) {
            if ($config->dnsProfile !== null || $config->dnsManual || $config->certificate !== null) {
                $result->warning('validation.domain_settings_unused');
            }
            return;
        }
        $seen = [];
        foreach ($config->domains as $domain) {
            if (str_contains($domain, '*')) {
                $result->error('validation.domain_wildcard', ['domain' => $domain]);
            } elseif (! self::isHostname($domain)) {
                $result->error('validation.domain', ['domain' => $domain]);
            } elseif (isset($seen[$domain])) {
                $result->error('validation.domain_duplicate', ['domain' => $domain]);
            }
            $seen[$domain] = true;
        }
        if (count($config->domains) > self::MAX_DOMAINS) {
            $result->error('validation.domain_count', ['count' => count($config->domains), 'max' => self::MAX_DOMAINS]);
        }
        if ($config->auth === 'iam') {
            $result->error('validation.domain_iam');
        }
        if ($config->certificate !== null && ! preg_match(self::CERTIFICATE, $config->certificate)) {
            $result->error('validation.certificate', ['value' => $config->certificate]);
        }
    }

    /** The rules domain() values must follow: lowercase, at least two labels, no wildcard. */
    public static function isHostname(string $domain): bool
    {
        return preg_match(self::HOSTNAME, $domain) === 1;
    }

    /** Returns the first missing or malformed field of an IAM statement, or null when it is usable. */
    private static function invalidStatementField(mixed $statement): ?string
    {
        if (! is_array($statement)) {
            return 'statement';
        }
        if (! in_array($statement['Effect'] ?? null, ['Allow', 'Deny'], true)) {
            return 'Effect';
        }
        if (array_key_exists('Sid', $statement) && (! is_string($statement['Sid']) || ! preg_match('/^[A-Za-z0-9]+$/', $statement['Sid']) || str_starts_with($statement['Sid'], 'Arco'))) {
            return 'Sid';
        }
        foreach (['Action', 'Resource'] as $field) {
            $value = $statement[$field] ?? $statement["Not$field"] ?? null;
            $list = is_string($value) ? [$value] : $value;
            if (! is_array($list) || $list === [] || array_filter($list, fn ($v) => ! is_string($v) || $v === '') !== []) {
                return $field;
            }
        }
        return null;
    }

    public static function isReserved(string $name): bool
    {
        if (in_array($name, self::RESERVED, true)) {
            return true;
        }
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
