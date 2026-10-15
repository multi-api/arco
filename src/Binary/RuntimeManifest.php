<?php declare(strict_types=1);

namespace Multiapi\Arco\Binary;

use Multiapi\Arco\Arco;

final class RuntimeManifest
{
    public const ID_PATTERN = '/^(\d+\.\d+\.\d+)-r([1-9]\d*)$/';

    /** @param array<string, RuntimeBuild> $builds sorted by id */
    private function __construct(private readonly string $default, private readonly array $builds)
    {
    }

    public static function load(?string $path = null): self
    {
        $data = require ($path ?? Arco::packageRoot() . '/resources/runtime-builds.php');
        if (! is_array($data)) {
            throw self::invalid('the manifest file must return an array');
        }
        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $default = $data['default'] ?? null;
        $entries = $data['builds'] ?? null;
        if (! is_string($default) || ! is_array($entries) || $entries === []) {
            throw self::invalid('"default" and a non-empty "builds" map are required');
        }

        $builds = [];
        foreach ($entries as $id => $entry) {
            $id = (string) $id;
            if (! preg_match(self::ID_PATTERN, $id, $m)) {
                throw self::invalid("build id \"$id\" must look like 1.13.0-r1");
            }
            if (! is_array($entry)) {
                throw self::invalid("build $id must be an array");
            }
            $url = $entry['url'] ?? null;
            $sha = $entry['sha256'] ?? null;
            $size = $entry['size'] ?? null;
            $frankenphp = $entry['frankenphp'] ?? null;
            $php = $entry['php'] ?? null;
            if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                throw self::invalid("build $id needs an https url");
            }
            if (! is_string($sha) || ! preg_match('/^[0-9a-f]{64}$/', $sha)) {
                throw self::invalid("build $id needs a lowercase hex sha256");
            }
            if (! is_int($size) || $size <= 0) {
                throw self::invalid("build $id needs a positive integer size");
            }
            if ($frankenphp !== $m[1]) {
                throw self::invalid("build $id must have frankenphp \"{$m[1]}\"");
            }
            if (! is_string($php) || $php === '') {
                throw self::invalid("build $id needs a php version");
            }
            $builds[$id] = new RuntimeBuild($id, $url, $sha, $size, $m[1], $php);
        }

        if (! isset($builds[$default])) {
            throw self::invalid("default build \"$default\" is not in builds");
        }
        uksort($builds, fn (string $a, string $b) => self::compare($builds[$a], $builds[$b]));
        return new self($default, $builds);
    }

    public function defaultId(): string
    {
        return $this->default;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->builds);
    }

    public function get(string $id): RuntimeBuild
    {
        return $this->builds[$id] ?? throw BinaryException::make('binary.unknown_build', [
            'build' => $id,
            'builds' => implode(', ', $this->ids()),
        ]);
    }

    public function latestFor(string $frankenphp): RuntimeBuild
    {
        $matches = array_values(array_filter($this->builds, fn (RuntimeBuild $b) => $b->frankenphp === $frankenphp));
        if ($matches === []) {
            throw BinaryException::make('binary.unknown_version', [
                'version' => $frankenphp,
                'builds' => implode(', ', $this->ids()),
            ]);
        }
        return $matches[count($matches) - 1];
    }

    public function resolve(?string $build, ?string $frankenphp): RuntimeBuild
    {
        if ($build !== null && $frankenphp !== null) {
            throw BinaryException::make('binary.build_and_version');
        }
        if ($build !== null) {
            return $this->get($build);
        }
        if ($frankenphp !== null) {
            return $this->latestFor($frankenphp);
        }
        return $this->get($this->default);
    }

    private static function compare(RuntimeBuild $a, RuntimeBuild $b): int
    {
        return version_compare($a->frankenphp, $b->frankenphp) ?: $a->revision() <=> $b->revision();
    }

    private static function invalid(string $reason): BinaryException
    {
        return BinaryException::make('binary.manifest_invalid', ['reason' => $reason]);
    }
}
