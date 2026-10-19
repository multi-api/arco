<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

use Multiapi\Arco\Config\AppConfig;

final class Builder
{
    /** `storage/framework/down` is Laravel's maintenance flag: shipping it would take the app down. */
    public const ALWAYS_EXCLUDED = [
        '.git/**', '.arco/**', 'node_modules/**', 'vendor/**', 'storage/framework/down',
        // Editor, CI and agent folders: never needed at runtime and often large.
        '.idea/**', '.vscode/**', '.fleet/**', '.zed/**', '.github/**', '**/.DS_Store',
        // AI assistant config and context (Claude, Cursor, Junie, Windsurf, Gemini, Codex, Aider, MCP).
        '.claude/**', '.superpowers/**', '.ai/**', '.junie/**', '.cursor/**', '.windsurf/**', '.gemini/**', '.codex/**',
        '.aider*', '.mcp.json',
        // Packages written by `arco package` at the project root (its old default output).
        'arco-*.zip',
    ];

    /** Secrets that are never packaged, at any depth; `include` patterns cannot override these. */
    public const NEVER_PACKAGED = ['.env', '.env.*', '**/.env', '**/.env.*'];

    /** Copied for `composer install` (private repositories) and deleted before packaging. */
    public const BUILD_ONLY = ['auth.json'];

    public function __construct(private readonly CommandRunner $runner, private readonly string $composer = 'composer')
    {
    }

    /**
     * @param (callable(string, array<string, string|int>): void)|null $onStep told before each phase starts
     * @param list<string> $exclude more patterns to leave out, such as the package's own output path
     */
    public function build(AppConfig $config, FrameworkPreset $preset, string $projectDir, ?callable $onStep = null, array $exclude = []): BuildResult
    {
        $step = $onStep ?? static function (string $key, array $params = []): void {
        };
        $staging = sys_get_temp_dir() . '/arco-build-' . bin2hex(random_bytes(6));
        $appDir = "$staging/app";
        mkdir($appDir, 0777, true);

        $matcher = new PathMatcher(
            [...self::ALWAYS_EXCLUDED, ...$preset->excludeRules(), ...$config->exclude, ...$exclude],
            $config->include,
            self::NEVER_PACKAGED,
        );
        $step('build.copying', []);
        $skipped = ProjectCopier::copy($projectDir, $appDir, $matcher);
        $result = new BuildResult($staging, $appDir, $skipped);

        try {
            if (is_file("$appDir/composer.json")) {
                self::absolutizePathRepositories($projectDir, $appDir);
                $step('build.composer', []);
                $this->runner->run(
                    [$this->composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress'],
                    $appDir,
                );
            }
            foreach (self::BUILD_ONLY as $file) {
                if (is_file("$appDir/$file")) {
                    unlink("$appDir/$file");
                }
            }
            $preset->prepare($appDir);
            foreach ($preset->buildSteps() as $command) {
                $step('build.step', ['command' => implode(' ', $command)]);
                $this->runner->run($command, $appDir, ['ARCO_BUILD' => '1']);
            }
        } catch (BuildException $e) {
            $result->cleanup();
            throw $e;
        }

        return $result;
    }

    /**
     * The staged copy lives in a temporary directory, so relative `path` repositories that point outside the project
     * (monorepos) would no longer resolve. Rewrites those to absolute paths, with `symlink` off so the package is
     * copied into vendor and ships in the zip, in the staged composer.json and composer.lock only. Paths inside the
     * project resolve in the staged copy as they are and are left alone. The edited composer.json may make Composer
     * warn that the lock file is not up to date: expected, the install still uses the lock.
     */
    private static function absolutizePathRepositories(string $projectDir, string $appDir): void
    {
        $root = realpath($projectDir) ?: $projectDir;

        $json = self::readJson("$appDir/composer.json");
        $repositories = $json->repositories ?? null;
        if ($json !== null && ($repositories instanceof \stdClass || is_array($repositories))) {
            $changed = false;
            foreach ((array) $repositories as $repository) {
                if ($repository instanceof \stdClass && ($repository->type ?? null) === 'path' && is_string($repository->url ?? null)) {
                    $url = self::outsidePath($root, $repository->url);
                    if ($url !== null) {
                        $repository->url = $url;
                        $options = ($repository->options ?? null) instanceof \stdClass ? $repository->options : new \stdClass();
                        $options->symlink ??= false;
                        $repository->options = $options;
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                self::writeJson("$appDir/composer.json", $json);
            }
        }

        $lock = self::readJson("$appDir/composer.lock");
        if ($lock === null) {
            return;
        }
        $changed = false;
        foreach (['packages', 'packages-dev'] as $section) {
            if (! isset($lock->$section) || ! is_array($lock->$section)) {
                continue;
            }
            foreach ($lock->$section as $package) {
                $dist = $package instanceof \stdClass ? ($package->dist ?? null) : null;
                if ($package instanceof \stdClass && $dist instanceof \stdClass && ($dist->type ?? null) === 'path' && is_string($dist->url ?? null)) {
                    $url = self::outsidePath($root, $dist->url);
                    if ($url !== null) {
                        $dist->url = $url;
                        $options = ($package->{'transport-options'} ?? null) instanceof \stdClass ? $package->{'transport-options'} : new \stdClass();
                        $options->symlink ??= false;
                        $package->{'transport-options'} = $options;
                        $changed = true;
                    }
                }
            }
        }
        if ($changed) {
            self::writeJson("$appDir/composer.lock", $lock);
        }
    }

    /** Decoded with objects so an empty `{}` (config, extra, scripts) is written back as `{}`. */
    private static function readJson(string $file): ?\stdClass
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        $data = $contents === false ? null : json_decode($contents);
        return $data instanceof \stdClass ? $data : null;
    }

    private static function writeJson(string $file, \stdClass $data): void
    {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /** The absolute form of a relative URL that resolves outside $root, or null when it should stay as it is. */
    private static function outsidePath(string $root, string $url): ?string
    {
        $absolute = self::absolutePath($root, $url);
        if ($absolute === $url) {
            return null;
        }
        return $absolute === $root || str_starts_with($absolute, rtrim($root, '/') . '/') ? null : $absolute;
    }

    /** Absolute paths, `~` paths, URLs and Windows drives stay as they are; the rest resolves against $projectDir. */
    private static function absolutePath(string $projectDir, string $url): string
    {
        if ($url === '' || $url[0] === '/' || $url[0] === '~' || $url[0] === '\\' || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $url) === 1) {
            return $url;
        }
        $segments = [];
        foreach (explode('/', rtrim($projectDir, '/') . '/' . $url) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }
        return '/' . implode('/', $segments);
    }
}
