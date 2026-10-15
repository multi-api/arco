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
}
