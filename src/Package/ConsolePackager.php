<?php declare(strict_types=1);

namespace Multiapi\Arco\Package;

use Multiapi\Arco\Build\BuildException;
use ZipArchive;

/**
 * Derives the console function's zip from the web zip. Classic Lambda functions allow 250 MB of
 * unzipped code, so the console zip leaves out the FrankenPHP binary (the console bootstrap downloads
 * this exact binary from S3) and the Node web runtime. Deriving it, rather than building it apart, keeps
 * one `arco package` zip deployable and guarantees the binary is the web revision's.
 */
final class ConsolePackager
{
    private const BINARY = 'bin/frankenphp';
    private const WEB_ONLY_FILES = [self::BINARY, 'index.js'];
    private const WEB_ONLY_PREFIXES = ['lib/'];

    public static function fromWeb(Package $web, string $dir): ConsoleArtifacts
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw BuildException::make('package.write_failed', ['path' => $dir]);
        }
        $path = "$dir/console-{$web->hash}.zip";
        $binary = "$dir/frankenphp-{$web->hash}";
        if (! @copy($web->path, $path)) {
            throw BuildException::make('package.write_failed', ['path' => $path]);
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw BuildException::make('package.write_failed', ['path' => $path]);
        }

        $in = $zip->getStream(self::BINARY);
        $out = $in === false ? false : fopen($binary, 'wb');
        if ($in === false || $out === false) {
            $zip->close();
            throw BuildException::make('package.no_binary', ['path' => $web->path]);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        $removed = 0;
        for ($i = $zip->numFiles - 1; $i >= 0; $i--) {
            $stat = $zip->statIndex($i);
            if ($stat !== false && self::webOnly($stat['name'])) {
                $removed += $stat['size'];
                $zip->deleteIndex($i);
            }
        }
        if (! $zip->close()) {
            throw BuildException::make('package.write_failed', ['path' => $path]);
        }

        $sha = (string) hash_file('sha256', $binary);
        $build = $web->manifest?->runtimeBuild;
        return new ConsoleArtifacts(
            new Package($path, hash('sha256', "console\0" . $web->hash), $web->bytes - $removed, $web->manifest),
            $binary,
            $sha,
            (int) filesize($binary),
            $build !== null ? "runtime/$build/frankenphp" : "runtime/custom/$sha/frankenphp",
        );
    }

    private static function webOnly(string $name): bool
    {
        if (in_array($name, self::WEB_ONLY_FILES, true)) {
            return true;
        }
        foreach (self::WEB_ONLY_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
