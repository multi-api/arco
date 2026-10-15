<?php declare(strict_types=1);

namespace Multiapi\Arco\Package;

/**
 * Identifies a zip written by `arco package`, stored as arco-package.json at the zip root.
 * It is not part of the content hash (it holds a timestamp), so S3 keys stay content-addressed.
 */
final readonly class PackageManifest
{
    public const FILE = 'arco-package.json';

    public function __construct(
        public string $app,
        public string $environment,
        public string $preset,
        public ?string $runtimeBuild,
        public string $hash,
        public int $bytes,
        public string $arcoVersion,
        public string $createdAt,
    ) {
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'app' => $this->app,
            'environment' => $this->environment,
            'preset' => $this->preset,
            'runtime_build' => $this->runtimeBuild,
            'hash' => $this->hash,
            'bytes' => $this->bytes,
            'arco_version' => $this->arcoVersion,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @param array<mixed> $data
     * @return self|null null when a required field is missing or has the wrong type
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['app', 'environment', 'preset', 'hash', 'arco_version', 'created_at'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || $data[$field] === '') {
                return null;
            }
        }
        $runtimeBuild = $data['runtime_build'] ?? null;
        $bytes = $data['bytes'] ?? null;
        if (! is_int($bytes) || ($runtimeBuild !== null && ! is_string($runtimeBuild)) || preg_match('/^[0-9a-f]{64}$/', (string) $data['hash']) !== 1) {
            return null;
        }

        return new self(
            (string) $data['app'],
            (string) $data['environment'],
            (string) $data['preset'],
            $runtimeBuild,
            (string) $data['hash'],
            $bytes,
            (string) $data['arco_version'],
            (string) $data['created_at'],
        );
    }
}
