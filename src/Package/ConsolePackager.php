<?php declare(strict_types=1);

namespace Multiapi\Arco\Package;

use Multiapi\Arco\Build\BuildException;
use ZipArchive;

/**
 * Derives the console function's zip from the web zip. Classic Lambda functions allow 250 MB of
 * unzipped code, so the console zip leaves out the FrankenPHP binary and the Node web runtime. When the
 * runtime build has a PHP CLI, the console zip carries it as bin/php; otherwise the console bootstrap
 * downloads the web zip's exact FrankenPHP binary from S3. Deriving it, rather than building it apart,
 * keeps one `arco package` zip deployable and guarantees the code is the web revision's.
 */
final class ConsolePackager
{
    private const BINARY = 'bin/frankenphp';
    private const PHP_CLI = 'bin/php';
    private const WEB_ONLY_FILES = [self::BINARY, 'index.js'];
    private const WEB_ONLY_PREFIXES = ['lib/'];

    /** @param string|null $phpCli the runtime build's static PHP CLI, carried as bin/php */
    public static function fromWeb(Package $web, string $dir, ?string $phpCli = null): ConsoleArtifacts
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw BuildException::make('package.write_failed', ['path' => $dir]);
        }
        // The PHP CLI is part of the console zip's identity: CodeBucket skips keys that already exist, so
        // a zip with bin/php must never share a key with the fallback zip or one with another CLI.
        $hash = hash('sha256', "console\0" . $web->hash);
        if ($phpCli !== null) {
            $cliSha = @hash_file('sha256', $phpCli);
            if ($cliSha === false) {
                throw BuildException::make('package.write_failed', ['path' => $phpCli]);
            }
            $hash = hash('sha256', "console\0php\0$cliSha\0" . $web->hash);
        }
        $path = "$dir/console-$hash.zip";
        $binary = "$dir/frankenphp-{$web->hash}";
        if (! @copy($web->path, $path)) {
            throw BuildException::make('package.write_failed', ['path' => $path]);
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw BuildException::make('package.write_failed', ['path' => $path]);
        }

        if ($zip->locateName(self::BINARY) === false) {
            $zip->close();
            throw BuildException::make('package.no_binary', ['path' => $web->path]);
        }
        if ($phpCli === null) {
            self::extract($zip, $binary, $web->path);
        }

        $removed = 0;
        for ($i = $zip->numFiles - 1; $i >= 0; $i--) {
            $stat = $zip->statIndex($i);
            if ($stat !== false && self::webOnly($stat['name'])) {
                $removed += $stat['size'];
                $zip->deleteIndex($i);
            }
        }
        $added = 0;
        if ($phpCli !== null) {
            // addFile() reads the file at close(), which happens below while $phpCli still exists.
            if (! @$zip->addFile($phpCli, self::PHP_CLI)
                || ! $zip->setExternalAttributesName(self::PHP_CLI, ZipArchive::OPSYS_UNIX, (0100000 | 0755) << 16)) {
                $zip->close();
                throw BuildException::make('package.write_failed', ['path' => $path]);
            }
            $added = (int) filesize($phpCli);
        }
        if (! $zip->close()) {
            throw BuildException::make('package.write_failed', ['path' => $path]);
        }

        $package = new Package($path, $hash, $web->bytes - $removed + $added, $web->manifest);
        if ($phpCli !== null) {
            return new ConsoleArtifacts($package, null);
        }

        $sha = (string) hash_file('sha256', $binary);
        $build = $web->manifest?->runtimeBuild;
        return new ConsoleArtifacts($package, new ConsoleDownload(
            $binary,
            $sha,
            (int) filesize($binary),
            $build !== null ? "runtime/$build/frankenphp" : "runtime/custom/$sha/frankenphp",
        ));
    }

    private static function extract(ZipArchive $zip, string $binary, string $webPath): void
    {
        $in = $zip->getStream(self::BINARY);
        $out = $in === false ? false : fopen($binary, 'wb');
        if ($in === false || $out === false) {
            $zip->close();
            throw BuildException::make('package.no_binary', ['path' => $webPath]);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
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
