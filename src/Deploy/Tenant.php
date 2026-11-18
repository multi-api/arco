<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

/** A distribution tenant as TenantInventory read it. */
final readonly class Tenant
{
    /**
     * @param list<string> $domains
     * @param string|null $status CloudFront's status ("Deployed" when no operation runs); null when it did not say
     * @param bool|null $enabled null when CloudFront did not say
     * @param string|null $etag null when CloudFront did not return one: nothing may be changed or deleted without it
     * @param bool $owned distribution, connection group and both Arco tags are this environment's
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public array $domains,
        public ?string $status,
        public ?bool $enabled,
        public ?string $etag,
        public bool $owned,
    ) {
    }

    /** An operation (a change or a deployment) is still running on it. */
    public function busy(): bool
    {
        return $this->status !== null && $this->status !== 'Deployed';
    }

    public function label(): string
    {
        return $this->domains === [] ? ($this->name ?? $this->id) : implode(', ', $this->domains);
    }
}
