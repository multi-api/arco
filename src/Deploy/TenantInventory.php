<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\CloudFront\CloudFrontClient;
use Aws\Exception\AwsException;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * The distribution tenants (customer domains) of an environment's tenant distribution, read from CloudFront:
 * every page of ListDistributionTenants, and for each tenant GetDistributionTenant, because the list carries no
 * tags. A tenant is the environment's only when its distribution, its connection group and both of its Arco tags
 * match; anything else on the distribution is someone else's and is never counted, changed or deleted.
 *
 * Reads fail closed: a list or a tenant that cannot be read completely stops the caller rather than being
 * counted as "none".
 */
final class TenantInventory
{
    private const PAGE = 100;

    /**
     * @param string $errors prefix of the error keys (`remove.tenant_` or `info.tenant_`):
     *        <prefix>listing_incomplete and <prefix>unverified, each with $context added to its params
     * @param array<string, string|int> $context
     */
    public function __construct(
        private readonly CloudFrontClient $cloudFront,
        public readonly string $distributionId,
        public readonly string $connectionGroupId,
        private readonly AppConfig $config,
        private readonly string $errors = 'remove.tenant_',
        private readonly array $context = [],
    ) {
    }

    /**
     * Every tenant on the distribution, owned or not.
     *
     * @return list<Tenant>
     * @throws DeployException listing_incomplete or unverified (with the error prefix)
     */
    public function scan(): array
    {
        $tenants = [];
        $marker = null;
        $seen = [];
        do {
            $args = ['AssociationFilter' => ['DistributionId' => $this->distributionId], 'MaxItems' => self::PAGE];
            if ($marker !== null) {
                $args['Marker'] = $marker;
            }
            $page = $this->call('ListDistributionTenants', $args);
            $list = $page['DistributionTenantList'] ?? null;
            if (! is_array($list) || ! array_is_list($list)) {
                throw $this->incomplete('incomplete answer');
            }
            foreach ($list as $summary) {
                $id = is_array($summary) ? Aws::string($summary['Id'] ?? null) : null;
                if ($id === null) {
                    throw $this->incomplete('incomplete answer');
                }
                $tenant = $this->read($id) ?? throw $this->incomplete('incomplete answer');
                $tenants[$id] = $tenant;
            }
            $next = $page['NextMarker'] ?? null;
            if ($next === null || $next === '') {
                break;
            }
            if (! is_string($next) || isset($seen[$next])) {
                throw $this->incomplete('incomplete answer');
            }
            $seen[$next] = true;
            $marker = $next;
        } while (true);
        return array_values($tenants);
    }

    /**
     * One tenant, read fresh (its ETag is current), with its ownership decided; null when CloudFront no longer has it.
     *
     * @throws DeployException unverified (with the error prefix)
     */
    public function read(string $id): ?Tenant
    {
        try {
            $result = Aws::call($this->cloudFront, 'GetDistributionTenant', ['Identifier' => $id]);
        } catch (AwsException $e) {
            if (in_array($e->getAwsErrorCode(), ['EntityNotFound', 'NoSuchDistributionTenant'], true)) {
                return null;
            }
            throw $this->unverified($id, self::describe($e));
        }
        if (! is_array($result['DistributionTenant'] ?? null)) {
            throw $this->unverified($id, 'no tenant in the answer');
        }
        $tenant = Aws::map($result['DistributionTenant']);
        if (Aws::string($tenant['Id'] ?? null) !== $id) {
            throw $this->unverified($id, 'the answer is for another tenant');
        }
        $tags = $this->tags($id, $tenant);
        $enabled = $tenant['Enabled'] ?? null;
        $domains = [];
        foreach (is_array($tenant['Domains'] ?? null) ? $tenant['Domains'] : [] as $domain) {
            $name = is_array($domain) ? Aws::string($domain['Domain'] ?? null) : Aws::string($domain);
            if ($name !== null) {
                $domains[] = $name;
            }
        }
        $owned = Aws::string($tenant['DistributionId'] ?? null) === $this->distributionId
            && Aws::string($tenant['ConnectionGroupId'] ?? null) === $this->connectionGroupId
            && ($tags[Ownership::APP] ?? null) === $this->config->app
            && ($tags[Ownership::ENVIRONMENT] ?? null) === $this->config->environment;
        return new Tenant(
            $id,
            Aws::string($tenant['Name'] ?? null),
            $domains,
            Aws::string($tenant['Status'] ?? null),
            is_bool($enabled) ? $enabled : null,
            Aws::string($result['ETag'] ?? null),
            $owned,
        );
    }

    /**
     * The tenant's tags. Real CloudFront answers GetDistributionTenant without them: they come from
     * ListTagsForResource on the ARN the answer carries (never built or guessed). An inline `Tags.Items` list is
     * still used when present. A tag list that cannot be read is an error, never "no tags".
     *
     * @param array<string, mixed> $tenant
     * @return array<string, string>
     * @throws DeployException unverified
     */
    private function tags(string $id, array $tenant): array
    {
        $items = Aws::map($tenant['Tags'] ?? null)['Items'] ?? null;
        if (is_array($items) && array_is_list($items)) {
            return Aws::tagMap($items);
        }
        $arn = Aws::string($tenant['Arn'] ?? null);
        if ($arn === null || ! str_starts_with($arn, 'arn:') || ! str_ends_with($arn, 'distribution-tenant/' . $id)) {
            throw $this->unverified($id, 'its ARN is missing or is not the tenant\'s, so its tags cannot be read');
        }
        try {
            $answer = Aws::call($this->cloudFront, 'ListTagsForResource', ['Resource' => $arn]);
        } catch (AwsException $e) {
            throw $this->unverified($id, self::describe($e));
        }
        $list = Aws::map($answer['Tags'] ?? null)['Items'] ?? null;
        if (! is_array($list) || ! array_is_list($list)) {
            throw $this->unverified($id, 'ListTagsForResource returned no tag list');
        }
        return Aws::tagMap($list);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function call(string $operation, array $args): array
    {
        try {
            return Aws::call($this->cloudFront, $operation, $args);
        } catch (AwsException $e) {
            throw $this->incomplete(self::describe($e));
        }
    }

    public static function describe(AwsException $e): string
    {
        $message = $e->getAwsErrorMessage();
        return $e->getCommand()->getName() . ': ' . (string) $e->getAwsErrorCode() . ': ' . (is_string($message) && $message !== '' ? $message : $e->getMessage());
    }

    private function incomplete(string $error): DeployException
    {
        return DeployException::make($this->errors . 'listing_incomplete', ['distribution' => $this->distributionId, 'error' => $error] + $this->context);
    }

    private function unverified(string $tenant, string $error): DeployException
    {
        return DeployException::make($this->errors . 'unverified', ['tenant' => $tenant, 'error' => $error] + $this->context);
    }
}
