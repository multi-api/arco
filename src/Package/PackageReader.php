<?php declare(strict_types=1);

namespace Multiapi\Arco\Package;

use Multiapi\Arco\Arco;
use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Build\FrameworkPreset;
use Multiapi\Arco\Config\AppConfig;
use ZipArchive;

/** Opens a zip written by `arco package` so it can be deployed without building. */
final class PackageReader
{
    private static function hasEntry(string $path, string $name): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $found = $zip->locateName($name) !== false;
        $zip->close();
        return $found;
    }

    /** @throws BuildException package.not_arco when the file is not a readable zip with a valid manifest */
    public static function open(string $path): Package
    {
        $manifest = null;
        $zip = new ZipArchive();
        if (is_file($path) && $zip->open($path, ZipArchive::RDONLY) === true) {
            $zip->close();
            // Cheap consistency check of every entry header, short of rehashing the content: a
            // damaged copy would otherwise be uploaded under the manifest's content-addressed key.
            if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw BuildException::make('package.corrupt', ['path' => $path]);
            }
            $json = $zip->getFromName(PackageManifest::FILE);
            $zip->close();
            $data = is_string($json) ? json_decode($json, true) : null;
            $manifest = is_array($data) ? PackageManifest::fromArray($data) : null;
        }
        if ($manifest === null) {
            throw BuildException::make('package.not_arco', ['path' => $path]);
        }

        return new Package($path, $manifest->hash, $manifest->bytes, $manifest);
    }

    /**
     * Refuses a package built for another app, environment or preset; reports softer differences.
     *
     * @param string|null $runtimeBuild the build the current config resolves to, null for a custom binary
     * @return list<array{0: string, 1: array<string, string>}> warnings as [translation key, params]
     * @throws BuildException
     */
    public static function check(Package $package, AppConfig $config, FrameworkPreset $preset, ?string $runtimeBuild): array
    {
        $manifest = $package->manifest ?? throw BuildException::make('package.not_arco', ['path' => $package->path]);
        if ($manifest->app !== $config->app || $manifest->environment !== $config->environment) {
            throw BuildException::make('package.wrong_target', [
                'path' => $package->path,
                'package_app' => $manifest->app,
                'package_environment' => $manifest->environment,
                'app' => $config->app,
                'environment' => $config->environment,
            ]);
        }
        if ($manifest->preset !== $preset->name()) {
            throw BuildException::make('package.wrong_preset', ['path' => $package->path, 'package_preset' => $manifest->preset, 'preset' => $preset->name()]);
        }

        if (! self::hasEntry($package->path, 'bootstrap')) {
            throw BuildException::make('package.no_console_runtime', ['path' => $package->path]);
        }

        $warnings = [];
        if ($manifest->arcoVersion !== Arco::version()) {
            $warnings[] = ['package.version_differs', ['path' => $package->path, 'package_version' => $manifest->arcoVersion, 'version' => Arco::version()]];
        }
        if ($manifest->runtimeBuild !== $runtimeBuild) {
            $warnings[] = ['package.runtime_build_differs', [
                'path' => $package->path,
                'package_build' => $manifest->runtimeBuild ?? 'custom binary',
                'build' => $runtimeBuild ?? 'custom binary',
            ]];
        }
        // Round-trip through JSON so `{}` and `[]` (an empty phpIni) compare equal.
        $expected = json_decode((string) json_encode(Packager::runtimeConfig($config, $preset)), true);
        if (self::runtimeConfig($package->path) != $expected) {
            $warnings[] = ['package.runtime_differs', ['path' => $package->path]];
        }

        return $warnings;
    }

    /** @return array<mixed>|null the runtime config (arco-runtime.json) inside the package */
    public static function runtimeConfig(string $path): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }
        $json = $zip->getFromName('arco-runtime.json');
        $zip->close();
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : null;
    }
}
