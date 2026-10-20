<?php declare(strict_types=1);

namespace Multiapi\Arco\Binary;

use Multiapi\Arco\Config\RuntimeConfig;

final class BinaryProvider
{
    public function __construct(
        private readonly Downloader $downloader,
        private readonly string $cacheDir,
        private readonly RuntimeManifest $manifest,
    ) {
    }

    public static function default(): self
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
        return new self(new GuzzleDownloader(), "$home/.arco/cache/runtime", RuntimeManifest::load());
    }

    /** The runtime build a config selects, or null when it uses a custom binary. */
    public function describe(RuntimeConfig $runtime): ?RuntimeBuild
    {
        return $runtime->binary === null ? $this->manifest->resolve($runtime->build, $runtime->frankenphp, $runtime->php) : null;
    }

    /**
     * The PHP CLI given with a custom binary (binary(php: ...)), checked to be Linux arm64, or null when
     * the config uses a runtime build or gives no PHP CLI.
     */
    public function customPhpCli(RuntimeConfig $runtime, string $projectDir): ?string
    {
        if ($runtime->binary === null || $runtime->phpBinary === null) {
            return null;
        }
        $path = str_starts_with($runtime->phpBinary, '/') ? $runtime->phpBinary : $projectDir . '/' . $runtime->phpBinary;
        if (! ElfInspector::isLinuxArm64($path)) {
            throw BinaryException::make('binary.not_arm64', ['path' => $runtime->phpBinary]);
        }
        return $path;
    }

    /** The build id an id names (an id of an earlier Arco is an alias); unknown ids and null are returned as they are. */
    public function canonical(?string $id): ?string
    {
        return $id === null ? null : $this->manifest->canonical($id);
    }

    /** @param (callable(int, int): void)|null $onProgress */
    public function get(RuntimeConfig $runtime, string $projectDir, ?callable $onProgress = null): string
    {
        $build = $this->describe($runtime);
        if ($build === null) {
            $binary = (string) $runtime->binary;
            $path = str_starts_with($binary, '/') ? $binary : $projectDir . '/' . $binary;
            if (! ElfInspector::isLinuxArm64($path)) {
                throw BinaryException::make('binary.not_arm64', ['path' => $binary]);
            }
            return $path;
        }

        return $this->fetch($build->url, $build->sha256, $build->size, "{$this->cacheDir}/{$build->id}/frankenphp", $build->id, $onProgress);
    }

    /**
     * The verified, cached PHP CLI of a build, or null when the build publishes none.
     *
     * @param (callable(int, int): void)|null $onProgress
     */
    public function phpCli(RuntimeBuild $build, ?callable $onProgress = null): ?string
    {
        if ($build->phpCli === null) {
            return null;
        }
        $cli = $build->phpCli;
        return $this->fetch($cli->url, $cli->sha256, $cli->size, "{$this->cacheDir}/{$build->id}/php", $build->id, $onProgress);
    }

    /** A build of the manifest by id (or an id of an earlier Arco), or null when the id is unknown. */
    public function build(string $id): ?RuntimeBuild
    {
        try {
            return $this->manifest->get($id);
        } catch (BinaryException) {
            return null;
        }
    }

    /** @param (callable(int, int): void)|null $onProgress */
    private function fetch(string $url, string $sha256, int $size, string $target, string $buildId, ?callable $onProgress): string
    {
        if (is_file($target) && filesize($target) === $size && hash_file('sha256', $target) === $sha256) {
            return $target;
        }
        @unlink($target);

        $dir = dirname($target);
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw BinaryException::make('binary.cache_write_failed', ['path' => $dir]);
        }
        $partial = $target . '.' . bin2hex(random_bytes(4)) . '.part';
        try {
            $this->downloader->download($url, $partial, $onProgress);
        } catch (\Throwable $e) {
            @unlink($partial);
            throw $e;
        }

        $actualSize = (int) filesize($partial);
        if ($actualSize !== $size) {
            @unlink($partial);
            throw BinaryException::make('binary.size_mismatch', ['build' => $buildId, 'expected' => $size, 'actual' => $actualSize]);
        }
        $actual = (string) hash_file('sha256', $partial);
        if ($actual !== $sha256) {
            @unlink($partial);
            throw BinaryException::make('binary.checksum_mismatch', ['build' => $buildId, 'expected' => $sha256, 'actual' => $actual]);
        }

        if (! @rename($partial, $target) || ! @chmod($target, 0755)) {
            @unlink($partial);
            throw BinaryException::make('binary.cache_write_failed', ['path' => $target]);
        }
        return $target;
    }
}
