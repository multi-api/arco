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
     */
    public function __construct(
        public string $app,
        public string $environment,
        public ?string $region,
        public int $concurrency,
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
    ) {
    }

    public function functionName(): string
    {
        return $this->app . '-' . $this->environment;
    }
}
