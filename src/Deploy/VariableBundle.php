<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\S3\S3Client;
use Multiapi\Arco\Config\AppConfig;

/**
 * bundleVariables(): the app's variables in one JSON object of the code bucket instead of the Lambda environment, which
 * AWS caps at 4 KB per function. The functions keep ARCO, ARCO_APP, ARCO_ENV, the runtime's own wiring added after
 * the split (ARCO_WORKER_QUEUE, ARCO_BINARY_*, ...) and ARCO_VARIABLES, an s3:// pointer the web, console and worker runtimes read at cold start, before they resolve arco-ssm: and arco-secret:
 * references. The key is the SHA-256 of the body: each function version or revision pins exactly the variables it was
 * deployed with (a rollback brings them back), and the runtime checks the bytes against it.
 */
final class VariableBundle
{
    public const POINTER = 'ARCO_VARIABLES';
    /** Objects kept besides the one this deploy uses, as for the packages (AwsDeployer::KEEP_PACKAGES). */
    public const KEEP = 20;
    /** Small and stable: they stay readable in the Lambda console and by anything that runs before the bundle loads. */
    private const IN_LAMBDA = ['ARCO', 'ARCO_APP', 'ARCO_ENV'];

    public function __construct(private readonly S3Client $s3)
    {
    }

    /** S3 prefix of the environment's bundles, outside the package prefix so the package prune never counts them. */
    public static function prefix(AppConfig $config): string
    {
        return "variables/{$config->app}/{$config->environment}/";
    }

    /**
     * ARCO, ARCO_APP and ARCO_ENV stay in the Lambda environment; everything else, the JSON maps arco-laravel reads
     * (ARCO_QUEUES, ARCO_STORAGE, ARCO_TABLES) included, goes to the bundle. An empty value is left out, as Lambda leaves
     * it out of the environment.
     *
     * @param array<string, string> $variables
     * @return array{bundle: array<string, string>, environment: array<string, string>}
     */
    public static function split(array $variables): array
    {
        $bundle = [];
        $environment = [];
        foreach ($variables as $name => $value) {
            $name = (string) $name;
            if (in_array($name, self::IN_LAMBDA, true)) {
                $environment[$name] = $value;
            } elseif ($value !== '') {
                $bundle[$name] = $value;
            }
        }
        return ['bundle' => $bundle, 'environment' => $environment];
    }

    /**
     * The object's bytes (schema 1), sorted by name so the same variables always give the same key.
     *
     * @param array<string, string> $bundle
     */
    public static function body(array $bundle): string
    {
        ksort($bundle, SORT_STRING);
        return json_encode(['schema' => 1, 'variables' => (object) $bundle], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function key(AppConfig $config, string $body): string
    {
        return self::prefix($config) . hash('sha256', $body) . '.json';
    }

    /**
     * Puts the bundle and returns the variables the functions keep, ARCO_VARIABLES included, and the object's key. The
     * object is put even when it exists: its LastModified restarts, so prune() keeps it among the newest. With kmsKey()
     * it is encrypted with that key, like the Lambda environment it replaces.
     *
     * @param array<string, string> $variables the deploy's variables
     * @param (callable(string, array<string, string|int>): void)|null $onStep receives deploy.step_variables
     * @return array{variables: array<string, string>, key: string}
     */
    public function store(AppConfig $config, string $bucket, array $variables, ?callable $onStep = null): array
    {
        ['bundle' => $bundle, 'environment' => $environment] = self::split($variables);
        $body = self::body($bundle);
        $key = self::key($config, $body);
        if ($onStep !== null) {
            $onStep('deploy.step_variables', ['count' => count($bundle), 'bucket' => $bucket, 'key' => $key]);
        }
        $this->s3->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => 'application/json',
            'ACL' => 'bucket-owner-full-control',
            ...($config->kmsKey === null ? [] : ['ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => $config->kmsKey]),
        ]);
        return ['variables' => [...$environment, self::POINTER => "s3://$bucket/$key"], 'key' => $key];
    }

    /** After a verified deploy: all but the KEEP newest bundles of the environment go, never $current. */
    public function prune(AppConfig $config, string $bucket, string $current): int
    {
        return (new CodeBucket($this->s3))->prune($bucket, self::prefix($config), self::KEEP, [$current]);
    }
}
