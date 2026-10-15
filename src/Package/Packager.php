<?php declare(strict_types=1);

namespace Multiapi\Arco\Package;

use Multiapi\Arco\Arco;
use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Build\BuildResult;
use Multiapi\Arco\Build\FrameworkPreset;
use Multiapi\Arco\Config\AppConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

final class Packager
{
    private readonly string $runtimeDir;

    private const BINARY = 'bin/frankenphp';

    public function __construct(?string $runtimeDir = null, private readonly int $maxBytes = Arco::MAX_PACKAGE_BYTES)
    {
        $this->runtimeDir = $runtimeDir ?? Arco::packageRoot() . '/runtime';
    }

    /**
     * @param string|null $runtimeBuild id of the runtime build, null for a custom binary
     * @param (callable(int): void)|null $onProgress whole percent of the zip written, each value once, ending at 100
     */
    public function package(BuildResult $build, string $binaryPath, AppConfig $config, FrameworkPreset $preset, ?string $runtimeBuild = null, ?callable $onProgress = null): Package
    {
        // zip entry name => [source path, unix mode]
        $entries = [];
        $entries['index.js'] = [$this->runtimeDir . '/index.js', 0644];
        foreach (glob($this->runtimeDir . '/lib/*.js') ?: [] as $file) {
            $entries['lib/' . basename($file)] = [$file, 0644];
        }
        $entries[self::BINARY] = [$binaryPath, 0755];
        foreach ($this->files($build->appDir) as $relative => $file) {
            $entries["app/$relative"] = [$file->getPathname(), $file->getPerms() & 0777];
        }

        $runtimeJson = json_encode(self::runtimeConfig($config, $preset), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        ksort($entries);
        $bytes = strlen($runtimeJson);
        $hashInput = "arco-runtime.json\0" . hash('sha256', $runtimeJson) . "\n";
        $directorySizes = [];
        foreach ($entries as $name => [$source]) {
            $size = (int) filesize($source);
            $bytes += $size;
            $hashInput .= $name . "\0" . hash_file('sha256', $source) . "\n";
            $parts = explode('/', $name);
            for ($depth = 2; $depth <= min(3, count($parts) - 1); $depth++) {
                $dir = implode('/', array_slice($parts, 0, $depth));
                $directorySizes[$dir] = ($directorySizes[$dir] ?? 0) + $size;
            }
        }

        if ($bytes > $this->maxBytes) {
            arsort($directorySizes);
            throw new PackageTooLarge($bytes, $this->maxBytes, array_slice($directorySizes, 0, 10, true));
        }

        $hash = hash('sha256', $hashInput);
        $manifest = new PackageManifest($config->app, $config->environment, $preset->name(), $runtimeBuild, $hash, $bytes, Arco::version(), gmdate('Y-m-d\\TH:i:s\\Z'));

        $path = $build->stagingDir . '/package.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw BuildException::make('package.write_failed', ['path' => $path]);
        }
        $zip->addFromString('arco-runtime.json', $runtimeJson);
        $zip->addFromString(PackageManifest::FILE, json_encode($manifest->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        foreach ($entries as $name => [$source, $mode]) {
            $zip->addFile($source, $name);
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0100000 | $mode) << 16);
            if ($name === self::BINARY) {
                // The static FrankenPHP binary barely compresses (~5%) but deflating its 160 MB
                // took most of the packaging time.
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
        }
        $reported = -1;
        $report = static function (int $percent) use ($onProgress, &$reported): void {
            if ($onProgress !== null && $percent > $reported) {
                $reported = $percent;
                $onProgress($percent);
            }
        };
        // libzip does the actual compression and writing in close() and reports the share done.
        // The method only exists with libzip >= 1.3; without it the phase lines are all users see.
        if ($onProgress !== null && self::reportsProgress($zip)) {
            $zip->registerProgressCallback(0.01, static fn (float $state) => $report(min(100, max(0, (int) floor($state * 100)))));
        }
        if (! $zip->close()) {
            throw BuildException::make('package.write_failed', ['path' => $path]);
        }

        $report(100);

        return new Package($path, $hash, $bytes, $manifest);
    }

    /** ZipArchive::registerProgressCallback() is only compiled in with libzip >= 1.3. */
    private static function reportsProgress(object $zip): bool
    {
        return method_exists($zip, 'registerProgressCallback');
    }

    /** @return array<string, mixed> the arco-runtime.json the package carries */
    public static function runtimeConfig(AppConfig $config, FrameworkPreset $preset): array
    {
        $worker = $preset->workerScript();
        return [
            'mode' => $config->runtime->mode,
            'publicDir' => 'app/' . $preset->publicDir(),
            'workerScript' => $worker === null ? null : 'app/' . $worker,
            'threads' => $config->runtime->threads,
            'workers' => ['min' => $config->runtime->workersMin, 'max' => $config->runtime->workersMax],
            // Empty maps must serialize as {} because the runtime expects an object.
            'phpIni' => $config->runtime->phpIni === [] ? new \stdClass() : $config->runtime->phpIni,
        ];
    }

    /** @return array<string, SplFileInfo> relative path => file */
    private function files(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $files[substr($file->getPathname(), strlen($dir) + 1)] = $file;
            }
        }
        return $files;
    }
}
