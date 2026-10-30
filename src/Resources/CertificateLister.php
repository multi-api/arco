<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\Domains\Aws;

/** ACM certificates of us-east-1 (the only region CloudFront reads), with the checks of {@see CertificateChecks}. */
final class CertificateLister implements Lister
{
    /** ListCertificates only returns RSA_2048 certificates unless the key types are asked for. */
    private const KEY_TYPES = ['RSA_1024', 'RSA_2048', 'RSA_3072', 'RSA_4096', 'EC_prime256v1', 'EC_secp384r1', 'EC_secp521r1'];

    /** @param (Closure(): int)|null $clock the current time as a Unix timestamp */
    public function __construct(private readonly ?Closure $clock = null)
    {
    }

    public function type(): ResourceType
    {
        return ResourceType::Certificate;
    }

    public function list(AwsClients $aws): array
    {
        $acm = $aws->acm();
        $clock = $this->clock ?? static fn (): int => time();
        $items = [];
        foreach ($this->arns($acm) as $arn) {
            try {
                $certificate = Aws::map(Aws::call($acm, 'DescribeCertificate', ['CertificateArn' => $arn])['Certificate'] ?? []);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                    continue; // deleted while listing
                }
                throw $e;
            }
            $domains = CertificateChecks::names($certificate);
            $expires = $certificate['NotAfter'] ?? null;
            $items[] = new ResourceItem(
                ResourceType::Certificate,
                $arn,
                $domains[0] ?? null,
                [],
                [
                    'arn' => $arn,
                    'domains' => $domains,
                    'status' => Aws::string($certificate['Status'] ?? null),
                    'expires' => $expires instanceof \DateTimeInterface ? $expires->format(\DATE_ATOM) : (is_int($expires) ? gmdate(\DATE_ATOM, $expires) : Aws::string($expires)),
                    'inUseBy' => count(Aws::strings($certificate['InUseBy'] ?? [])),
                ],
                [...CertificateChecks::notIssued($certificate), ...CertificateChecks::expiring($certificate, $clock)],
            );
        }
        usort($items, fn (ResourceItem $a, ResourceItem $b) => $a->id <=> $b->id);
        return $items;
    }

    /** @return list<string> */
    private function arns(\Aws\Acm\AcmClient $acm): array
    {
        $arns = [];
        $token = null;
        do {
            $page = Aws::call($acm, 'ListCertificates', ['Includes' => ['keyTypes' => self::KEY_TYPES], ...($token === null ? [] : ['NextToken' => $token])]);
            foreach (Aws::maps($page['CertificateSummaryList'] ?? []) as $summary) {
                $arn = Aws::string($summary['CertificateArn'] ?? null);
                if ($arn !== null) {
                    $arns[] = $arn;
                }
            }
            $token = Aws::string($page['NextToken'] ?? null);
        } while ($token !== null);
        return $arns;
    }
}
