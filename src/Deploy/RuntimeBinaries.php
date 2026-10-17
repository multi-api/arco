<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;
use Aws\S3\S3Client;

/**
 * Deletes runtime binaries (runtime/<build>/frankenphp, runtime/custom/<sha256>/frankenphp) that no
 * published version of any Arco function in this region still downloads. The code bucket is shared by
 * every Arco app and environment of the account and region, and any published version can be rolled
 * back to, so every version of every function tagged arco:app is read. A binary uploaded less than
 * 24 hours ago is kept: another deploy may have uploaded it and not published its version yet.
 */
final class RuntimeBinaries
{
    public const PREFIX = 'runtime/';
    private const MIN_AGE_SECONDS = 86400;
    private const DELETE_BATCH = 1000;

    public function __construct(
        private readonly ResourceGroupsTaggingAPIClient $tagging,
        private readonly LambdaClient $lambda,
        private readonly S3Client $s3,
    ) {
    }

    /**
     * @param list<string> $keep keys never deleted (the binary of the deploy running this prune)
     * @return int objects deleted
     */
    public function prune(string $bucket, \DateTimeImmutable $now, array $keep = []): int
    {
        // Read before the bucket listing: a binary listed afterwards is either referenced here or too new.
        $referenced = $this->referencedKeys($bucket) + array_fill_keys($keep, true);
        $cutoff = $now->getTimestamp() - self::MIN_AGE_SECONDS;

        $delete = [];
        foreach ($this->objects($bucket) as $object) {
            $key = $object['Key'] ?? null;
            $modified = $object['LastModified'] ?? null;
            if (! is_string($key) || ! $modified instanceof \DateTimeInterface || isset($referenced[$key])) {
                continue;
            }
            if ($modified->getTimestamp() < $cutoff) {
                $delete[] = ['Key' => $key];
            }
        }
        foreach (array_chunk($delete, self::DELETE_BATCH) as $chunk) {
            $this->s3->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $chunk, 'Quiet' => true]]);
        }
        return count($delete);
    }

    /** @return array<string, true> ARCO_BINARY_KEY of every version whose ARCO_BINARY_BUCKET is $bucket */
    private function referencedKeys(string $bucket): array
    {
        $keys = [];
        $token = null;
        do {
            $params = ['ResourceTypeFilters' => ['lambda:function'], 'TagFilters' => [['Key' => 'arco:app']]];
            if ($token !== null) {
                $params['PaginationToken'] = $token;
            }
            $page = $this->tagging->getResources($params);
            // Fail closed: a page that cannot be read could hide a function that still references a binary.
            $resources = $page['ResourceTagMappingList'] ?? null;
            if (! is_array($resources)) {
                throw new \RuntimeException('GetResources returned a page without ResourceTagMappingList; no runtime binary was deleted.');
            }
            foreach ($resources as $resource) {
                $arn = is_array($resource) ? ($resource['ResourceARN'] ?? null) : null;
                if (! is_string($arn) || $arn === '') {
                    throw new \RuntimeException('GetResources returned a function without a ResourceARN; no runtime binary was deleted.');
                }
                $keys += $this->keysOf($arn, $bucket);
            }
            $token = is_string($page['PaginationToken'] ?? null) && $page['PaginationToken'] !== '' ? $page['PaginationToken'] : null;
        } while ($token !== null);
        return $keys;
    }

    /** @return array<string, true> */
    private function keysOf(string $function, string $bucket): array
    {
        $keys = [];
        $marker = null;
        do {
            $params = ['FunctionName' => $function];
            if ($marker !== null) {
                $params['Marker'] = $marker;
            }
            try {
                $page = $this->lambda->listVersionsByFunction($params);
            } catch (LambdaException $e) {
                // The tag listing lags behind: a function deleted since then has no versions left.
                if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                    return $keys;
                }
                throw $e;
            }
            $versions = $page['Versions'] ?? null;
            if (! is_array($versions)) {
                throw new \RuntimeException("ListVersionsByFunction returned a page without Versions for $function; no runtime binary was deleted.");
            }
            foreach ($versions as $version) {
                $variables = self::variablesOf($function, $version);
                if (($variables['ARCO_BINARY_BUCKET'] ?? null) === $bucket && is_string($variables['ARCO_BINARY_KEY'] ?? null)) {
                    $keys[$variables['ARCO_BINARY_KEY']] = true;
                }
            }
            $marker = is_string($page['NextMarker'] ?? null) && $page['NextMarker'] !== '' ? $page['NextMarker'] : null;
        } while ($marker !== null);
        return $keys;
    }

    /**
     * The version's environment variables. A version with no Environment, or an Environment without Variables, has none. One whose variables
     * cannot be read (Environment.Error: e.g. KMS-encrypted with a key this caller cannot decrypt, or a
     * disabled key) may reference any binary, so it stops the prune.
     *
     * @return array<mixed>
     */
    private static function variablesOf(string $function, mixed $version): array
    {
        if (! is_array($version)) {
            throw new \RuntimeException("ListVersionsByFunction returned an unreadable version of $function; no runtime binary was deleted.");
        }
        $name = $function . ':' . (is_string($version['Version'] ?? null) ? $version['Version'] : '?');
        if (! array_key_exists('Environment', $version) || $version['Environment'] === null) {
            return [];
        }
        $environment = $version['Environment'];
        if (! is_array($environment)) {
            throw new \RuntimeException("Cannot read the environment variables of $name; no runtime binary was deleted.");
        }
        if (array_key_exists('Error', $environment)) {
            $error = $environment['Error'];
            $detail = is_array($error) ? implode(': ', array_filter([$error['ErrorCode'] ?? null, $error['Message'] ?? null], 'is_string')) : '';
            throw new \RuntimeException("Cannot read the environment variables of $name" . ($detail === '' ? '' : " ($detail)") . '; no runtime binary was deleted.');
        }
        if (! array_key_exists('Variables', $environment)) {
            return [];
        }
        $variables = $environment['Variables'];
        if (! is_array($variables)) {
            throw new \RuntimeException("Cannot read the environment variables of $name; no runtime binary was deleted.");
        }
        return $variables;
    }

    /** @return list<array<mixed>> */
    private function objects(string $bucket): array
    {
        $objects = [];
        $token = null;
        do {
            $params = ['Bucket' => $bucket, 'Prefix' => self::PREFIX];
            if ($token !== null) {
                $params['ContinuationToken'] = $token;
            }
            $page = $this->s3->listObjectsV2($params);
            // No Contents is an empty prefix; anything else malformed stops the prune.
            $contents = $page['Contents'] ?? [];
            if (! is_array($contents)) {
                throw new \RuntimeException('ListObjectsV2 returned a malformed page; no runtime binary was deleted.');
            }
            foreach ($contents as $object) {
                if (is_array($object)) {
                    $objects[] = $object;
                }
            }
            $next = $page['NextContinuationToken'] ?? null;
            $truncated = ($page['IsTruncated'] ?? false) === true;
            if ($truncated && (! is_string($next) || $next === '')) {
                throw new \RuntimeException('ListObjectsV2 returned a truncated page without a continuation token; no runtime binary was deleted.');
            }
            $token = $truncated ? $next : null;
        } while ($token !== null);
        return $objects;
    }
}
