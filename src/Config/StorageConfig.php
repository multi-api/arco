<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/** One S3 bucket Arco manages (or references, when `existing` is set). */
final readonly class StorageConfig
{
    /** S3 general purpose bucket naming rules (3 to 63 characters, lowercase, no IP address, no reserved prefix or suffix). */
    private const BUCKET = '/^(?=.{3,63}$)(?!\d+\.\d+\.\d+\.\d+$)(?!xn--|sthree-|amzn-s3-demo-)(?!.*(-s3alias|--ol-s3|--x-s3|--table-s3)$)[a-z0-9](?:[a-z0-9-]|\.(?!\.))*[a-z0-9]$/';

    /**
     * @param list<string> $public key prefixes anyone can read
     * @param array<string, int> $expire key prefix => days before objects under it are deleted
     * @param list<string> $cors origins allowed to call the bucket from a browser
     */
    public function __construct(
        public string $name,
        public array $public = [],
        public array $expire = [],
        public array $cors = [],
        /** name of a bucket Arco does not manage */
        public ?string $existing = null,
        public bool $default = false,
    ) {
    }

    public static function isBucketName(string $name): bool
    {
        return preg_match(self::BUCKET, $name) === 1;
    }

    public function bucketName(AppConfig $config): string
    {
        return $this->existing ?? $config->app . '-' . $config->environment . '-' . $this->name . '-' . ($config->account ?? '');
    }

    public function arn(AppConfig $config): string
    {
        return 'arn:' . Partition::of($config->region ?? '') . ':s3:::' . $this->bucketName($config);
    }

    public function url(AppConfig $config): string
    {
        return 'https://' . $this->bucketName($config) . '.s3.' . ($config->region ?? '') . '.amazonaws.com';
    }
}
