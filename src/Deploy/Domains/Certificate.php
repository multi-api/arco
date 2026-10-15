<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

final readonly class Certificate
{
    /**
     * @param list<string> $names DomainName plus SubjectAlternativeNames
     * @param array<string, string> $tags
     * @param list<DnsRecord> $validationRecords CNAMEs ACM asks for, once it has generated them
     * @param bool $validationReady ACM has generated the record of every name
     * @param string|null $type AMAZON_ISSUED, IMPORTED or PRIVATE
     * @param string|null $keyAlgorithm normalized to the API enum, e.g. RSA_2048 (ACM answers "RSA-2048")
     */
    public function __construct(
        public string $arn,
        public array $names,
        public string $status,
        public array $tags = [],
        public array $validationRecords = [],
        public bool $validationReady = false,
        public ?string $type = null,
        public ?string $keyAlgorithm = null,
    ) {
    }

    public function taggedFor(string $app, string $environment): bool
    {
        return ($this->tags['arco:app'] ?? null) === $app && ($this->tags['arco:environment'] ?? null) === $environment;
    }

    /** Requested by Arco for this environment, so Arco may delete it. */
    public function managedFor(string $app, string $environment): bool
    {
        return $this->taggedFor($app, $environment) && ($this->tags['arco:managed'] ?? null) === 'true';
    }

    /** @param array<string, string> $tags */
    public function withTags(array $tags): self
    {
        return new self($this->arn, $this->names, $this->status, $tags, $this->validationRecords, $this->validationReady, $this->type, $this->keyAlgorithm);
    }
}
