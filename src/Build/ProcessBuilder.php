<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

use Multiapi\Arco\Config\AppConfig;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\ExecutableFinder;

/** Builds a process app (Go) into a staging directory: the compiled binary plus the `include` files. */
final class ProcessBuilder
{
    private readonly \Closure $goExists;

    /** @param (\Closure(string): bool)|null $goExists whether the go executable can be found; null looks it up on PATH */
    public function __construct(private readonly CommandRunner $runner, private readonly string $go = 'go', ?\Closure $goExists = null)
    {
        $this->goExists = $goExists ?? static fn (string $go): bool => str_contains($go, '/')
            ? is_executable($go)
            : (new ExecutableFinder())->find($go) !== null;
    }

    /**
     * @param (callable(string, array<string, string|int>): void)|null $onStep told before each phase starts
     * @throws BuildException runtime.main_required, build.go_missing, build.command_failed
     */
    public function build(AppConfig $config, string $projectDir, ?callable $onStep = null): BuildResult
    {
        $step = $onStep ?? static function (string $key, array $params = []): void {
        };
        $main = self::mainPackage($projectDir, $config->process?->main);
        if (! ($this->goExists)($this->go)) {
            throw BuildException::make('build.go_missing');
        }

        $staging = sys_get_temp_dir() . '/arco-build-' . bin2hex(random_bytes(6));
        $appDir = "$staging/app";
        $result = new BuildResult($staging, $appDir, []);
        try {
            mkdir("$appDir/bin", 0777, true);
            $step('build.copying', []);
            // Only what `include` names is copied; `exclude` then applies to those files.
            $matcher = new PathMatcher(['**'], $config->include, [...Builder::NEVER_PACKAGED, ...Builder::ALWAYS_EXCLUDED], descend: true);
            $skipped = ProjectCopier::copy($projectDir, $appDir, $matcher);
            $result = new BuildResult($staging, $appDir, $skipped);
            self::applyExclude($appDir, $config->exclude);
            self::removeEmptyDirectories($appDir);
            self::copyCloudExtensions($projectDir, $appDir);

            $step('build.go', ['main' => $main]);
            try {
                $this->runner->run(
                    [$this->go, 'build', '-trimpath', '-o', "$appDir/bin/app", $main],
                    $projectDir,
                    ['CGO_ENABLED' => '0', 'GOOS' => 'linux', 'GOARCH' => 'arm64'],
                );
            } catch (ProcessStartFailedException) {
                throw BuildException::make('build.go_missing');
            }
        } catch (\Throwable $e) {
            $result->cleanup();
            throw $e;
        }

        return $result;
    }

    /**
     * `.` when the root declares `package main`, else the only `cmd/<dir>` that does, unless $configured says.
     *
     * @throws BuildException runtime.main_required
     */
    public static function mainPackage(string $projectDir, ?string $configured): string
    {
        if ($configured !== null && $configured !== '') {
            return $configured;
        }
        if (self::declaresMain($projectDir)) {
            return '.';
        }
        $commands = [];
        foreach (is_dir("$projectDir/cmd") ? (scandir("$projectDir/cmd") ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir("$projectDir/cmd/$entry") && self::declaresMain("$projectDir/cmd/$entry")) {
                $commands[] = "./cmd/$entry";
            }
        }
        if (count($commands) === 1) {
            return $commands[0];
        }
        throw BuildException::make('runtime.main_required');
    }

    /** Whether a non-test `*.go` file directly in $dir declares `package main`. */
    private static function declaresMain(string $dir): bool
    {
        foreach (glob($dir . '/*.go') ?: [] as $file) {
            if (str_ends_with($file, '_test.go') || ! is_file($file)) {
                continue;
            }
            if (self::packageName((string) file_get_contents($file)) === 'main') {
                return true;
            }
        }
        return false;
    }

    /** The name in the `package` clause, after comments; null when there is none or the file is `//go:build ignore`. */
    private static function packageName(string $source): ?string
    {
        $source = str_starts_with($source, "\xEF\xBB\xBF") ? substr($source, 3) : $source;
        $offset = 0;
        $length = strlen($source);
        while ($offset < $length) {
            if (preg_match('/\G\s+/', $source, $m, 0, $offset) === 1) {
                $offset += strlen($m[0]);
            } elseif (preg_match('#\G//[^\n]*#', $source, $m, 0, $offset) === 1) {
                if (preg_match('#^//(?:go:build\s+ignore|\s*\+build\s+ignore)\s*$#', $m[0]) === 1) {
                    return null;
                }
                $offset += strlen($m[0]);
            } elseif (preg_match('#\G/\*.*?\*/#s', $source, $m, 0, $offset) === 1) {
                $offset += strlen($m[0]);
            } else {
                break;
            }
        }
        return preg_match('/\Gpackage\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $m, 0, $offset) === 1 ? $m[1] : null;
    }

    /**
     * Ships `<project>/.arco-cloud` as `app/.arco-cloud`, bypassing `include`, `exclude` and the dotfile rules, keeping
     * file modes. Symlinks are skipped.
     */
    private static function copyCloudExtensions(string $projectDir, string $appDir): void
    {
        $source = "$projectDir/.arco-cloud";
        if (is_link($source) || ! is_dir($source)) {
            return;
        }
        self::copyTree($source, "$appDir/.arco-cloud");
    }

    private static function copyTree(string $from, string $to): void
    {
        if (! is_dir($to) && ! mkdir($to, 0777, true) && ! is_dir($to)) {
            throw BuildException::make('build.read_failed', ['path' => $to]);
        }
        $entries = scandir($from);
        if ($entries === false) {
            throw BuildException::make('build.read_failed', ['path' => $from]);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || is_link("$from/$entry")) {
                continue;
            }
            if (is_dir("$from/$entry")) {
                self::copyTree("$from/$entry", "$to/$entry");
            } elseif (is_file("$from/$entry")) {
                if (! copy("$from/$entry", "$to/$entry")) {
                    throw BuildException::make('build.read_failed', ['path' => "$from/$entry"]);
                }
                chmod("$to/$entry", fileperms("$from/$entry") & 0777);
            }
        }
    }

    /** The copy walks every directory so wildcard includes work; the ones that matched nothing are dropped. */
    private static function removeEmptyDirectories(string $dir): bool
    {
        $empty = true;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$dir/$entry";
            if (is_dir($path) && ! is_link($path) && self::removeEmptyDirectories($path)) {
                continue;
            }
            $empty = false;
        }
        if ($empty && basename($dir) !== 'app' && basename($dir) !== 'bin') {
            rmdir($dir);
            return true;
        }
        return false;
    }

    /** @param list<string> $exclude */
    private static function applyExclude(string $appDir, array $exclude): void
    {
        if ($exclude === []) {
            return;
        }
        $matcher = new PathMatcher($exclude, []);
        $remove = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($appDir) + 1);
            if ($relative !== 'bin' && $relative !== 'bin/app' && $matcher->excludes($relative, $file->isDir())) {
                $remove[] = $file->getPathname();
            }
        }
        (new Filesystem())->remove($remove);
    }
}
