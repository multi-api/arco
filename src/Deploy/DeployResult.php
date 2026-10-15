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
    ) {
    }

    /** @param list<string> $domains */
    public function withDomains(array $domains): self
    {
        return new self($this->domainName, $this->revisionId, $this->previousRevisionId, $this->bucket, $this->key, $domains);
    }

    public function url(): string
    {
        return 'https://' . $this->domainName;
    }
}
