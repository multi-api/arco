<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Aws\Acm\AcmClient;
use Aws\Exception\AwsException;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Sleeper;

/** ACM in us-east-1, the only region CloudFront takes certificates from. */
final class Certificates
{
    private const KEY_TYPES = ['RSA_1024', 'RSA_2048', 'RSA_3072', 'RSA_4096', 'EC_prime256v1', 'EC_secp384r1', 'EC_secp521r1'];
    private const POLL = 10;
    private const DELETE_RETRIES = 30;

    public function __construct(private readonly AcmClient $acm, private readonly Sleeper $sleeper)
    {
    }

    public function describe(string $arn): ?Certificate
    {
        try {
            $detail = Aws::map(Aws::call($this->acm, 'DescribeCertificate', ['CertificateArn' => $arn])['Certificate'] ?? null);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
        $names = array_values(array_unique([...Aws::strings([$detail['DomainName'] ?? null]), ...Aws::strings($detail['SubjectAlternativeNames'] ?? [])]));
        $records = [];
        $options = Aws::maps($detail['DomainValidationOptions'] ?? []);
        $ready = $options !== [];
        foreach ($options as $option) {
            $record = Aws::map($option['ResourceRecord'] ?? null);
            $ready = $ready && $record !== [];
            $name = Aws::string($record['Name'] ?? null);
            $value = Aws::string($record['Value'] ?? null);
            if ($name !== null && $value !== null && ($record['Type'] ?? 'CNAME') === 'CNAME') {
                $records[Hostname::normalize($name)] = DnsRecord::cname($name, $value);
            }
        }
        return new Certificate(
            $arn,
            $names,
            (string) Aws::string($detail['Status'] ?? null),
            [],
            array_values($records),
            $ready,
            Aws::string($detail['Type'] ?? null),
            CertificateMatcher::keyAlgorithm(Aws::string($detail['KeyAlgorithm'] ?? null)),
        );
    }

    /**
     * Issued certificates, and pending ones, that CloudFront accepts and whose names cover every
     * domain, with their tags.
     *
     * @param list<string> $domains
     * @return list<Certificate>
     */
    public function candidates(array $domains): array
    {
        $candidates = [];
        foreach ($this->summaries(['ISSUED', 'PENDING_VALIDATION']) as $summary) {
            $names = [...Aws::strings([$summary['DomainName'] ?? null]), ...Aws::strings($summary['SubjectAlternativeNameSummaries'] ?? [])];
            if (! CertificateMatcher::usableByCloudFront(Aws::string($summary['Type'] ?? null), Aws::string($summary['KeyAlgorithm'] ?? null))) {
                continue;
            }
            if (($summary['HasAdditionalSubjectAlternativeNames'] ?? false) !== true && CertificateMatcher::uncovered($names, $domains) !== []) {
                continue;
            }
            $certificate = $this->describe((string) Aws::string($summary['CertificateArn'] ?? null));
            if ($certificate !== null && CertificateMatcher::uncovered($certificate->names, $domains) === []) {
                $candidates[] = $certificate->withTags($this->tags($certificate->arn));
            }
        }
        return $candidates;
    }

    public function request(AppConfig $config): string
    {
        $result = Aws::call($this->acm, 'RequestCertificate', [
            'DomainName' => $config->domains[0],
            'SubjectAlternativeNames' => $config->domains,
            'ValidationMethod' => 'DNS',
            'IdempotencyToken' => CertificateMatcher::idempotencyToken($config->app, $config->environment, $config->domains),
            'Tags' => [
                ['Key' => 'arco:app', 'Value' => $config->app],
                ['Key' => 'arco:environment', 'Value' => $config->environment],
                ['Key' => 'arco:managed', 'Value' => 'true'],
            ],
        ]);
        return Aws::string($result['CertificateArn'] ?? null) ?? throw new \UnexpectedValueException('acm:RequestCertificate returned no CertificateArn.');
    }

    /** ACM fills in the validation records a few seconds after the request. */
    public function waitForValidationRecords(string $arn, int $timeout = 300): Certificate
    {
        for ($waited = 0; ; $waited += self::POLL) {
            $certificate = $this->describe($arn) ?? throw DeployException::make('domains.certificate_missing', ['arn' => $arn]);
            if ($certificate->status !== 'PENDING_VALIDATION' || $certificate->validationReady) {
                return $certificate;
            }
            if ($waited >= $timeout) {
                throw DeployException::make('deploy.timeout', ['what' => "validation records of $arn"]);
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    public function waitIssued(string $arn, int $timeout = 1800): void
    {
        for ($waited = 0; ; $waited += self::POLL) {
            $certificate = $this->describe($arn) ?? throw DeployException::make('domains.certificate_missing', ['arn' => $arn]);
            if ($certificate->status === 'ISSUED') {
                return;
            }
            if ($certificate->status !== 'PENDING_VALIDATION') {
                throw DeployException::make('domains.certificate_failed', ['arn' => $arn, 'status' => $certificate->status]);
            }
            if ($waited >= $timeout) {
                throw DeployException::make('domains.certificate_timeout', ['arn' => $arn, 'minutes' => intdiv($timeout, 60)]);
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    /**
     * Certificates Arco requested for this environment (any status).
     *
     * @return list<Certificate>
     */
    public function managed(AppConfig $config): array
    {
        $managed = [];
        foreach ($this->summaries([]) as $summary) {
            $arn = Aws::string($summary['CertificateArn'] ?? null);
            if ($arn === null) {
                continue;
            }
            $tags = $this->tags($arn);
            if ((new Certificate($arn, [], '', $tags))->managedFor($config->app, $config->environment)) {
                $certificate = $this->describe($arn);
                if ($certificate !== null) {
                    $managed[] = $certificate->withTags($tags);
                }
            }
        }
        return $managed;
    }

    /** Right after CloudFront lets go of a certificate, ACM may still report it in use for a while. */
    public function delete(string $arn): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                Aws::call($this->acm, 'DeleteCertificate', ['CertificateArn' => $arn]);
                return;
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                    return;
                }
                if ($e->getAwsErrorCode() !== 'ResourceInUseException' || $attempt >= self::DELETE_RETRIES) {
                    throw $e;
                }
                $this->sleeper->sleep(self::POLL);
            }
        }
    }

    /** @return array<string, string> */
    private function tags(string $arn): array
    {
        $tags = [];
        foreach (Aws::maps(Aws::call($this->acm, 'ListTagsForCertificate', ['CertificateArn' => $arn])['Tags'] ?? []) as $tag) {
            if (is_string($tag['Key'] ?? null)) {
                $tags[$tag['Key']] = is_string($tag['Value'] ?? null) ? $tag['Value'] : '';
            }
        }
        return $tags;
    }

    /**
     * @param list<string> $statuses empty for all
     * @return list<array<string, mixed>>
     */
    private function summaries(array $statuses): array
    {
        $summaries = [];
        $token = null;
        do {
            $args = ['Includes' => ['keyTypes' => self::KEY_TYPES], 'MaxItems' => 1000];
            if ($statuses !== []) {
                $args['CertificateStatuses'] = $statuses;
            }
            if ($token !== null) {
                $args['NextToken'] = $token;
            }
            $page = Aws::call($this->acm, 'ListCertificates', $args);
            array_push($summaries, ...Aws::maps($page['CertificateSummaryList'] ?? []));
            $token = Aws::string($page['NextToken'] ?? null);
        } while ($token !== null);
        return $summaries;
    }
}
