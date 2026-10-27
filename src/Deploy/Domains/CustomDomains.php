<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\RevisionFactory;
use Multiapi\Arco\Deploy\Sleeper;

/**
 * Puts an environment's endpoint behind its custom domains: ACM certificate, CloudFront
 * distribution (one CloudFormation stack) and DNS records, through a DnsProvider or printed for the user.
 */
final class CustomDomains
{
    private const CERTIFICATE_TIMEOUT = 1800;

    /** @param DnsProvider|null $dns the service holding the zones; null when DNS is managed elsewhere (dns(false)) */
    public function __construct(
        private readonly Certificates $certificates,
        private readonly Stacks $stacks,
        private readonly ?DnsProvider $dns,
        private readonly bool $forceDns = false,
    ) {
    }

    /**
     * @param AwsClients $aws deploy account
     * @param AwsClients|null $dnsAws account of the dns() profile; null to use the deploy account
     * @param DnsProvider|null $provider the provider when it is not Route 53 (dns('cloudflare')); without
     *     one, a Cloudflare environment fails on its first DNS operation and never touches Route 53
     */
    public static function create(AppConfig $config, AwsClients $aws, ?AwsClients $dnsAws, Sleeper $sleeper, bool $forceDns = false, ?DnsProvider $provider = null): self
    {
        $dns = match (true) {
            $provider !== null => $provider,
            $config->dnsCloudflare => UnavailableDns::cloudflareTokenMissing(),
            $config->dnsManual && $dnsAws === null => null,
            default => new Route53Dns(($dnsAws ?? $aws)->route53(), $sleeper),
        };
        return new self(new Certificates($aws->acm(), $sleeper), new Stacks($aws->cloudFormation(), $sleeper, 'domains.stack_'), $dns, $forceDns);
    }

    /**
     * @param list<string> $cachedPaths
     * @param array<string, string> $tags
     * @param callable(string, array<string, string|int>): void $step
     * @param (callable(string, string): void)|null $onDistribution runs with the distribution id and domain once the
     *     stack is complete, before any DNS change: AwsDeployer locks the endpoint to the distribution there
     * @return list<string> the domains now served
     */
    public function deploy(AppConfig $config, string $endpointDomain, array $cachedPaths, array $tags, callable $step, ?callable $onDistribution = null): array
    {
        $stackName = DomainStack::stackName($config);
        $stack = $this->stacks->describe($stackName);
        self::assertOwned($stack, $stackName, $config);
        $distribution = $stack['outputs']['DistributionDomain'] ?? null;

        // Checked before anything slow is created, so a conflict fails in seconds.
        $this->guardAliases($config, $distribution);

        $step('domains.step_certificate', ['domains' => implode(', ', $config->domains)]);
        $certificate = $this->certificate($config, $step);

        $step('domains.step_distribution', ['stack' => $stackName]);
        $template = DomainStack::template($config, $endpointDomain, $certificate, $cachedPaths, RevisionFactory::protectsEndpoint($config));
        $outputs = $this->stacks->deploy($stackName, DomainStack::body($template), $tags);
        $distribution = $outputs['DistributionDomain'] ?? throw DeployException::make('domains.distribution_missing', ['stack' => $stackName, 'output' => 'DistributionDomain']);
        if ($onDistribution !== null) {
            $onDistribution($outputs['DistributionId'] ?? throw DeployException::make('domains.distribution_missing', ['stack' => $stackName, 'output' => 'DistributionId']), $distribution);
        }

        $step('domains.step_dns', ['distribution' => $distribution]);
        $records = [];
        foreach ($config->domains as $domain) {
            array_push($records, ...($this->dns?->servingRecords($domain, $distribution) ?? DnsRecord::aliases($domain, $distribution)));
        }
        // Checked again on a fresh read: records may have changed during the certificate and
        // CloudFront waits, and an UPSERT would silently overwrite a new A/AAAA record.
        $guard = $this->guardAliases($config, $distribution);
        $manual = $this->write($config, $records, $guard['deletes'], $guard['existing']);
        if ($manual !== []) {
            $step('domains.manual_records', []);
            foreach ($config->domains as $domain) {
                if (array_filter($manual, fn (DnsRecord $r) => $r->name === Hostname::normalize($domain)) !== []) {
                    $step('domains.manual_record', ['type' => 'CNAME', 'name' => $domain, 'value' => $distribution]);
                }
            }
        }
        return $config->domains;
    }

    /**
     * Deletes what deploy created: aliases pointing at this environment's distribution, the stack,
     * and the certificate when Arco requested it (not its validation records). Pieces already gone
     * are skipped.
     *
     * @param callable(string, array<string, string|int>): void $step
     */
    public function remove(AppConfig $config, callable $step): void
    {
        $stackName = DomainStack::stackName($config);
        $stack = $this->stacks->describe($stackName);
        self::assertOwned($stack, $stackName, $config);
        $distribution = $stack['outputs']['DistributionDomain'] ?? null;
        $aliases = array_filter(explode(',', $stack['outputs']['Aliases'] ?? ''));
        $domains = array_values(array_unique([...$config->domains, ...$aliases]));

        if ($distribution !== null && $this->dns !== null && $domains !== []) {
            $step('remove.step_dns', ['domains' => implode(', ', $domains)]);
            $byZone = [];
            foreach ($domains as $domain) {
                $zone = $this->dns->zoneFor($domain);
                if ($zone !== null) {
                    foreach ($this->dns->removable($this->dns->records($zone, $domain), $domain, $distribution, $config) as $set) {
                        $byZone[$zone->id]['zone'] = $zone;
                        $byZone[$zone->id]['deletes'][] = $set;
                    }
                }
            }
            foreach ($byZone as $batch) {
                $this->dns->apply($batch['zone'], $batch['deletes'], [], $config);
            }
        }

        if ($stack !== null) {
            $step('remove.step_distribution', ['stack' => $stackName]);
            $this->stacks->delete($stackName);
        }

        // Validation CNAMEs stay: they are inert, and ACM shares one per name and account across
        // every region, so another certificate may still need them for renewal.
        foreach ($this->certificates->managed($config) as $certificate) {
            $step('remove.step_certificate', ['arn' => $certificate->arn]);
            $this->certificates->delete($certificate->arn);
        }
    }

    /**
     * A stack with Arco's name that this environment did not create (no matching arco:app and
     * arco:environment tags) is never updated or deleted. Also AwsDeployer::checkOwnership()'s read-only
     * pre-check, so a deploy refuses before it tags or changes anything.
     *
     * @throws DeployException domains.stack_not_ours
     *
     * @param array{status: string, outputs: array<string, string>, tags: array<string, string>}|null $stack
     */
    public static function assertOwned(?array $stack, string $stackName, AppConfig $config): void
    {
        if ($stack !== null && (($stack['tags']['arco:app'] ?? null) !== $config->app || ($stack['tags']['arco:environment'] ?? null) !== $config->environment)) {
            throw DeployException::make('domains.stack_not_ours', ['stack' => $stackName, 'app' => $config->app, 'environment' => $config->environment]);
        }
    }

    /** @return array{domain: string, status: string}|null */
    public function describe(AppConfig $config): ?array
    {
        $stack = $this->stacks->describe(DomainStack::stackName($config));
        return $stack === null ? null : ['domain' => $stack['outputs']['DistributionDomain'] ?? '-', 'status' => $stack['status']];
    }

    /**
     * @return array{deletes: array<string, list<array<string, mixed>>>, existing: array<string, list<array<string, mixed>>>}
     *         records to delete with the serving records, and the records read, by domain
     */
    private function guardAliases(AppConfig $config, ?string $distribution): array
    {
        if ($this->dns === null) {
            return ['deletes' => [], 'existing' => []];
        }
        $conflicts = [];
        $deletes = [];
        $existing = [];
        foreach ($config->domains as $domain) {
            $zone = $this->dns->zoneFor($domain);
            if ($zone === null) {
                continue;
            }
            $sets = $this->dns->records($zone, $domain);
            $plan = $this->dns->plan($sets, $domain, $distribution, $this->forceDns, $config);
            array_push($conflicts, ...$plan['conflicts']);
            $deletes[Hostname::normalize($domain)] = $plan['deletes'];
            $existing[Hostname::normalize($domain)] = $sets;
        }
        if ($conflicts !== [] && ! $this->forceDns) {
            throw DeployException::make('domains.record_conflict', ['records' => implode("\n", array_map(fn (array $s) => '  ' . $this->dns->describe($s), $conflicts))]);
        }
        return ['deletes' => $deletes, 'existing' => $existing];
    }

    /** @param callable(string, array<string, string|int>): void $step */
    private function certificate(AppConfig $config, callable $step): string
    {
        if ($config->certificate !== null) {
            $certificate = $this->certificates->describe($config->certificate)
                ?? throw DeployException::make('domains.certificate_missing', ['arn' => $config->certificate]);
            if ($certificate->status !== 'ISSUED') {
                throw DeployException::make('domains.certificate_not_issued', ['arn' => $certificate->arn, 'status' => $certificate->status]);
            }
            if (! CertificateMatcher::usableByCloudFront($certificate->type, $certificate->keyAlgorithm)) {
                throw DeployException::make('domains.certificate_unsupported', [
                    'arn' => $certificate->arn,
                    'type' => $certificate->type ?? '-',
                    'algorithm' => $certificate->keyAlgorithm ?? '-',
                ]);
            }
            $uncovered = CertificateMatcher::uncovered($certificate->names, $config->domains);
            if ($uncovered !== []) {
                throw DeployException::make('domains.certificate_uncovered', ['arn' => $certificate->arn, 'domains' => implode(', ', $uncovered)]);
            }
            $step('domains.certificate_using', ['arn' => $certificate->arn]);
            return $certificate->arn;
        }

        $found = CertificateMatcher::pick($this->certificates->candidates($config->domains), $config->domains, $config->app, $config->environment);
        if ($found !== null && $found->status === 'ISSUED') {
            $step('domains.certificate_using', ['arn' => $found->arn]);
            return $found->arn;
        }
        $arn = $found->arn ?? $this->certificates->request($config);
        $step('domains.certificate_requested', ['arn' => $arn]);

        $certificate = $this->certificates->waitForValidationRecords($arn);
        if ($certificate->status === 'PENDING_VALIDATION') {
            $manual = $this->write($config, $certificate->validationRecords, []);
            if ($manual !== []) {
                $step('domains.manual_records', []);
                foreach ($manual as $record) {
                    $step('domains.manual_record', ['type' => $record->type, 'name' => $record->name, 'value' => $record->value]);
                }
            }
            $step('domains.waiting_certificate', ['minutes' => intdiv(self::CERTIFICATE_TIMEOUT, 60)]);
        }
        $this->certificates->waitIssued($arn, self::CERTIFICATE_TIMEOUT);
        return $arn;
    }

    /**
     * UPSERTs records into their zones, one batch per zone with the given deletes, and returns the
     * records that have no zone (to create by hand).
     *
     * @param list<DnsRecord> $records
     * @param array<string, list<array<string, mixed>>> $deletes by record name
     * @param array<string, list<array<string, mixed>>> $existing current records by record name, when already read
     * @return list<DnsRecord>
     */
    private function write(AppConfig $config, array $records, array $deletes, array $existing = []): array
    {
        if ($this->dns === null) {
            return $records;
        }
        $manual = [];
        $batches = [];
        foreach ($records as $record) {
            $zone = $this->dns->zoneFor($record->name);
            if ($zone === null) {
                $manual[] = $record;
                continue;
            }
            $batches[$zone->id] ??= ['zone' => $zone, 'deletes' => [], 'upserts' => []];
            $recordDeletes = $deletes[$record->name] ?? [];
            // A redeploy finds its records already in place: skip them, and the INSYNC wait.
            if ($recordDeletes === [] && array_filter($existing[$record->name] ?? [], fn (array $set) => $this->dns->isSatisfied($record, $set, $config)) !== []) {
                continue;
            }
            foreach ($recordDeletes as $set) {
                $batches[$zone->id]['deletes'][serialize($set)] = $set;
            }
            $batches[$zone->id]['upserts'][] = $record;
        }
        foreach ($batches as $batch) {
            if ($batch['deletes'] !== [] || $batch['upserts'] !== []) {
                $this->dns->apply($batch['zone'], array_values($batch['deletes']), $batch['upserts'], $config);
            }
        }
        return $manual;
    }
}
