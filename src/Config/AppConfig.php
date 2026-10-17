<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

final readonly class AppConfig
{
    /**
     * @param array<string, string> $variables
     * @param list<string> $exclude
     * @param list<string> $include
     * @param array<string, string> $sources
     * @param list<array<string, mixed>> $permissions
     * @param list<string> $domains
     * @param list<string> $deployCommands
     */
    public function __construct(
        public string $app,
        public string $environment,
        public ?string $region,
        /** null: AWS's default (64) with its dynamic per-instance limit */
        public ?int $concurrency,
        public int $timeout,
        public ?int $maxInstances,
        public ?int $rateLimit,
        public string $auth,
        public string $logLevel,
        public ?string $role,
        public ?string $kmsKey,
        public ?string $profile,
        public ?string $account,
        public RuntimeConfig $runtime,
        public array $variables,
        public array $exclude,
        public array $include,
        public array $sources,
        public array $permissions = [],
        public array $domains = [],
        public ?string $dnsProfile = null,
        public bool $dnsManual = false,
        public ?string $certificate = null,
        public array $deployCommands = [],
        public int $consoleTimeout = 900,
        /** null: protected when the environment is named production */
        public ?bool $protected = null,
        /** null: never set (the console function is off unless a scheduler needs it) */
        public ?bool $console = null,
        /** minutes between `schedule:run` invocations; null: no scheduler */
        public ?int $scheduler = null,
        /** days to keep logs; false: never expire; null: Arco does not manage retention */
        public int|false|null $logRetention = null,
    ) {
    }

    public function functionName(): string
    {
        return $this->app . '-' . $this->environment;
    }

    public function consoleFunctionName(): string
    {
        return $this->functionName() . '-console';
    }

    /** Whether the console function is deployed: asked for, or needed by the scheduler. */
    public function consoleEnabled(): bool
    {
        return $this->console === true || $this->scheduler !== null;
    }

    /** Whether `arco tinker` asks for confirmation first. */
    public function isProtected(): bool
    {
        return $this->protected ?? $this->environment === 'production';
    }
}
