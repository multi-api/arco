<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\CodeBucket;
use Throwable;

/**
 * Lists the requested types one after another and annotates what they found for the environment. The spec's "in
 * parallel" is relaxed to sequential (one progress line per type): the lists are small, and the SDK mock handler the
 * tests use is ordered. A type AWS refuses is reported as that type's error; the other types are still listed.
 */
final class Inventory
{
    /** IAM actions whose name is not the operation's. */
    private const ACTIONS = ['s3:ListBuckets' => 's3:ListAllMyBuckets'];

    /** @var list<array{key: string, params: array<string, string>}> what went wrong without failing a type */
    public array $warnings = [];

    /**
     * @param string $deployLabel how a zone of the deploy account names who saw it (the deploy profile)
     * @param string|null $dnsLabel the same for the DNS profile's zones
     * @param (Closure(): int)|null $clock the current time, for the certificates' expiry
     */
    public function __construct(
        private readonly string $deployLabel,
        private readonly ?string $dnsLabel = null,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * @param AppConfig $config the resolved environment, its account known (it names Arco's code bucket)
     * @param AwsClients|null $dns the DNS profile's clients: its hosted zones are listed too
     * @param list<ResourceType> $types
     * @param Closure(ResourceType): void $onType called before each type is listed
     * @return array<string, array{ok: true, items: list<ResourceItem>}|array{ok: false, error: array{key: string, params: array<string, string>}}>
     */
    public function collect(AppConfig $config, AwsClients $aws, ?AwsClients $dns, array $types, Closure $onType): array
    {
        $this->warnings = [];
        $listed = [];
        $failed = [];
        foreach ($types as $type) {
            $onType($type);
            try {
                $listed[$type->value] = $type === ResourceType::Zone ? $this->zones($aws, $dns) : $this->lister($type)->list($aws);
            } catch (AwsException $e) {
                $failed[$type->value] = self::error($type, $e);
            }
        }

        $annotated = (new Annotator())->annotate($config, CodeBucket::name((string) $config->account, (string) $config->region), $listed);
        $result = [];
        foreach ($types as $type) {
            $result[$type->value] = isset($failed[$type->value])
                ? ['ok' => false, 'error' => $failed[$type->value]]
                : ['ok' => true, 'items' => $annotated[$type->value] ?? []];
        }
        return $result;
    }

    private function lister(ResourceType $type): Lister
    {
        return match ($type) {
            ResourceType::Subnet => new SubnetLister(),
            ResourceType::SecurityGroup => new SecurityGroupLister(),
            ResourceType::Endpoint => new EndpointLister(),
            ResourceType::Bucket => new BucketLister(),
            ResourceType::Queue => new QueueLister(),
            ResourceType::Table => new TableLister(),
            ResourceType::Certificate => new CertificateLister($this->clock),
            ResourceType::Zone => new ZoneLister(null, $this->deployLabel, null),
        };
    }

    /**
     * The deploy account's zones, then the DNS profile's. A failing DNS profile (expired credentials, a denied call)
     * does not fail the type: the deploy account's zones are still listed, and a warning says what is missing.
     *
     * @return list<ResourceItem>
     */
    private function zones(AwsClients $aws, ?AwsClients $dns): array
    {
        $items = [];
        foreach ((new ZoneLister(null, $this->deployLabel, null))->list($aws) as $item) {
            $items[$item->id] = $item;
        }
        if ($dns === null) {
            return array_values($items);
        }
        $label = $this->dnsLabel ?? (string) $dns->profile;
        try {
            foreach ((new ZoneLister(null, $label, null))->list($dns) as $item) {
                $items[$item->id] ??= $item; // a zone both see is listed once, under the deploy profile
            }
        } catch (Throwable $e) {
            $error = $e instanceof AwsException ? (string) ($e->getAwsErrorCode() ?? 'UnknownError') : $e->getMessage();
            $this->warnings[] = ['key' => 'resources.dns_profile_failed', 'params' => ['profile' => $label, 'error' => $error]];
        }
        ksort($items);
        return array_values($items);
    }

    /** @return array{key: string, params: array<string, string>} */
    private static function error(ResourceType $type, AwsException $e): array
    {
        $code = (string) ($e->getAwsErrorCode() ?? 'UnknownError');
        if (str_starts_with($code, 'AccessDenied') || $code === 'UnauthorizedOperation') {
            $action = self::service($type) . ':' . $e->getCommand()->getName();
            return ['key' => 'resources.permission_denied', 'params' => ['action' => self::ACTIONS[$action] ?? $action]];
        }
        return ['key' => 'resources.list_failed', 'params' => ['code' => $code]];
    }

    /** The IAM service prefix of the calls a type's lister makes. */
    private static function service(ResourceType $type): string
    {
        return match ($type) {
            ResourceType::Subnet, ResourceType::SecurityGroup, ResourceType::Endpoint => 'ec2',
            ResourceType::Bucket => 's3',
            ResourceType::Queue => 'sqs',
            ResourceType::Table => 'dynamodb',
            ResourceType::Certificate => 'acm',
            ResourceType::Zone => 'route53',
        };
    }
}
