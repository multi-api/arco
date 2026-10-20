<?php declare(strict_types=1);

namespace Multiapi\Arco\Binary;

use Multiapi\Arco\Arco;

/**
 * The runtime builds this Arco release knows (resources/runtime-builds.php).
 *
 * Build ids are `php<php>-fp<frankenphp>-r<n>`. The ids of earlier Arco releases (`1.13.0-r2`) are
 * kept forever in `aliases`, so an arco.php pinning one keeps deploying the identical binaries.
 *
 * Default policy (owner decision 2): the default build is the newest build of `default_php`, the one
 * PHP minor the manifest pins. A 1.x release may add newer builds of that minor (a new PHP patch,
 * FrankenPHP version or revision) and so move the default; a new PHP minor is opt-in via php().
 */
final class RuntimeManifest
{
    public const ID_PATTERN = '/^php(\d+\.\d+\.\d+)-fp(\d+\.\d+\.\d+)-r([1-9]\d*)$/D';
    /** A release id of multi-api/arco-runtime (tag `frankenphp-<id>`), also the build id before Arco 0.9. */
    public const RELEASE_ID_PATTERN = '/^(\d+\.\d+\.\d+)-r([1-9]\d*)$/D';
    public const MINOR_PATTERN = '/^\d+\.\d+$/D';

    /**
     * @param array<string, RuntimeBuild> $builds oldest first (see compare())
     * @param array<string, string> $aliases legacy id => build id
     */
    private function __construct(private readonly string $defaultPhp, private readonly array $builds, private readonly array $aliases)
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

    /** The build id of a PHP version, FrankenPHP version and revision. */
    public static function id(string $php, string $frankenphp, int $revision): string
    {
        return "php$php-fp$frankenphp-r$revision";
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $defaultPhp = $data['default_php'] ?? null;
        $entries = $data['builds'] ?? null;
        $aliasEntries = $data['aliases'] ?? [];
        if (! is_string($defaultPhp) || ! preg_match(self::MINOR_PATTERN, $defaultPhp) || ! is_array($entries) || $entries === []) {
            throw self::invalid('"default_php" (a PHP minor such as 8.5) and a non-empty "builds" map are required');
        }
        if (! is_array($aliasEntries)) {
            throw self::invalid('"aliases" must map old build ids to build ids');
        }

        $builds = [];
        foreach ($entries as $id => $entry) {
            $id = (string) $id;
            if (! preg_match(self::ID_PATTERN, $id, $m)) {
                throw self::invalid("build id \"$id\" must look like php8.5.11-fp1.13.0-r1");
            }
            if (! is_array($entry)) {
                throw self::invalid("build $id must be an array");
            }
            $url = $entry['url'] ?? null;
            $sha = $entry['sha256'] ?? null;
            $size = $entry['size'] ?? null;
            if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                throw self::invalid("build $id needs an https url");
            }
            if (! self::isSha256($sha)) {
                throw self::invalid("build $id needs a lowercase hex sha256");
            }
            if (! is_int($size) || $size <= 0) {
                throw self::invalid("build $id needs a positive integer size");
            }
            if (($entry['php'] ?? null) !== $m[1]) {
                throw self::invalid("build $id must have php \"{$m[1]}\"");
            }
            if (($entry['frankenphp'] ?? null) !== $m[2]) {
                throw self::invalid("build $id must have frankenphp \"{$m[2]}\"");
            }
            $builds[$id] = new RuntimeBuild($id, $url, $sha, $size, $m[2], $m[1], self::phpCli($id, $entry['php_cli'] ?? null));
        }

        $aliases = [];
        foreach ($aliasEntries as $alias => $target) {
            $alias = (string) $alias;
            if (! preg_match(self::RELEASE_ID_PATTERN, $alias) || ! is_string($target) || ! isset($builds[$target])) {
                throw self::invalid("alias \"$alias\" must be an old build id naming a build");
            }
            $aliases[$alias] = $target;
        }

        uasort($builds, self::compare(...));
        $manifest = new self($defaultPhp, $builds, $aliases);
        if ($manifest->ofPhp($defaultPhp) === []) {
            throw self::invalid("no build has the default PHP $defaultPhp");
        }
        return $manifest;
    }

    /** The PHP minor the default build is chosen from. */
    public function defaultPhp(): string
    {
        return $this->defaultPhp;
    }

    /** @return list<string> build ids, oldest first */
    public function ids(): array
    {
        return array_keys($this->builds);
    }

    /** @return array<string, string> old build id => build id */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /** @return list<string> the PHP minors with at least one build, lowest first */
    public function phpMinors(): array
    {
        $minors = array_values(array_unique(array_map(fn (RuntimeBuild $b) => $b->phpMinor(), $this->builds)));
        usort($minors, static fn (string $a, string $b): int => version_compare($a, $b));
        return $minors;
    }

    /** The build id an id or old id names; an unknown id is returned unchanged. */
    public function canonical(string $id): string
    {
        return $this->aliases[$id] ?? $id;
    }

    public function get(string $id): RuntimeBuild
    {
        return $this->builds[$this->canonical($id)] ?? throw BinaryException::make('binary.unknown_build', [
            'build' => $id,
            'builds' => implode(', ', $this->ids()),
        ]);
    }

    /**
     * build() is an exact pin (a build id or an old id); otherwise the newest build of the PHP minor
     * (the default one when $php is null) whose FrankenPHP version starts with $frankenphp.
     */
    public function resolve(?string $build, ?string $frankenphp, ?string $php = null): RuntimeBuild
    {
        if ($build !== null && ($frankenphp !== null || $php !== null)) {
            throw BinaryException::make('binary.build_and_version');
        }
        if ($build !== null) {
            return $this->get($build);
        }
        if ($php !== null && ! preg_match(self::MINOR_PATTERN, $php)) {
            throw BinaryException::make('binary.invalid_php', ['php' => $php, 'minors' => implode(', ', $this->phpMinors())]);
        }
        $php ??= $this->defaultPhp;
        $matches = $this->ofPhp($php);
        if ($matches === []) {
            throw BinaryException::make('binary.unknown_php', ['php' => $php, 'minors' => implode(', ', $this->phpMinors())]);
        }
        if ($frankenphp !== null) {
            $matches = array_values(array_filter($matches, fn (RuntimeBuild $b) => $b->frankenphp === $frankenphp || str_starts_with($b->frankenphp, "$frankenphp.")));
            if ($matches === []) {
                throw BinaryException::make('binary.unknown_version', [
                    'version' => $frankenphp,
                    'php' => $php,
                    'builds' => implode(', ', $this->ids()),
                ]);
            }
        }
        return $matches[count($matches) - 1];
    }

    /** @return list<RuntimeBuild> oldest first */
    private function ofPhp(string $minor): array
    {
        return array_values(array_filter($this->builds, fn (RuntimeBuild $b) => $b->phpMinor() === $minor));
    }

    /** @phpstan-assert-if-true string $sha */
    private static function isSha256(mixed $sha): bool
    {
        return is_string($sha) && preg_match('/^[0-9a-f]{64}$/D', $sha) === 1;
    }

    private static function phpCli(string $id, mixed $cli): ?PhpCli
    {
        if ($cli === null) {
            return null;
        }
        $url = is_array($cli) ? ($cli['url'] ?? null) : null;
        $sha = is_array($cli) ? ($cli['sha256'] ?? null) : null;
        $size = is_array($cli) ? ($cli['size'] ?? null) : null;
        if (
            ! is_string($url) || ! str_starts_with($url, 'https://')
            || ! self::isSha256($sha)
            || ! is_int($size) || $size <= 0
        ) {
            throw self::invalid("build $id has an invalid php_cli");
        }
        return new PhpCli($url, $sha, $size);
    }

    /** Newest last: by PHP version, then FrankenPHP version, then revision. */
    private static function compare(RuntimeBuild $a, RuntimeBuild $b): int
    {
        return version_compare($a->php, $b->php)
            ?: version_compare($a->frankenphp, $b->frankenphp)
            ?: $a->revision() <=> $b->revision();
    }

    private static function invalid(string $reason): BinaryException
    {
        return BinaryException::make('binary.manifest_invalid', ['reason' => $reason]);
    }
}
