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
        return $runtime->binary === null ? $this->manifest->resolve($runtime->build, $runtime->frankenphp) : null;
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

        $target = "{$this->cacheDir}/{$build->id}/frankenphp";
        if (is_file($target) && filesize($target) === $build->size && hash_file('sha256', $target) === $build->sha256) {
            return $target;
        }
        @unlink($target);

        $dir = dirname($target);
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw BinaryException::make('binary.cache_write_failed', ['path' => $dir]);
        }
        $partial = $target . '.' . bin2hex(random_bytes(4)) . '.part';
        try {
            $this->downloader->download($build->url, $partial, $onProgress);
        } catch (\Throwable $e) {
            @unlink($partial);
            throw $e;
        }

        $size = (int) filesize($partial);
        if ($size !== $build->size) {
            @unlink($partial);
            throw BinaryException::make('binary.size_mismatch', ['build' => $build->id, 'expected' => $build->size, 'actual' => $size]);
        }
        $actual = (string) hash_file('sha256', $partial);
        if ($actual !== $build->sha256) {
            @unlink($partial);
            throw BinaryException::make('binary.checksum_mismatch', ['build' => $build->id, 'expected' => $build->sha256, 'actual' => $actual]);
        }

        if (! @rename($partial, $target) || ! @chmod($target, 0755)) {
            @unlink($partial);
            throw BinaryException::make('binary.cache_write_failed', ['path' => $target]);
        }
        return $target;
    }
}
