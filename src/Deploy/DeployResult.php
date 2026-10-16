<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

final readonly class DeployResult
{
    /** @param list<string> $domains custom domains now serving the endpoint */
    public function __construct(
        public string $domainName,
        public string $revisionId,
        public ?string $previousRevisionId,
        public string $bucket,
        public string $key,
        public array $domains = [],
        /** console version published by this deploy; null when the console function was skipped */
        public ?string $consoleVersion = null,
        /** version the console's live alias pointed to before this deploy */
        public ?string $previousConsoleVersion = null,
    ) {
    }

    /** @param list<string> $domains */
    public function withDomains(array $domains): self
    {
        return new self($this->domainName, $this->revisionId, $this->previousRevisionId, $this->bucket, $this->key, $domains, $this->consoleVersion, $this->previousConsoleVersion);
    }

    public function url(): string
    {
        return 'https://' . $this->domainName;
    }
}
