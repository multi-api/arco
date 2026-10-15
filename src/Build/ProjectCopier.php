<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

final class ProjectCopier
{
    /**
     * Copies $from into $to, pruning excluded paths. Symlinks are never followed or copied.
     *
     * @return list<string> skipped symlinks, relative to $from
     */
    public static function copy(string $from, string $to, PathMatcher $matcher): array
    {
        $skipped = [];
        self::copyDir($from, $to, '', $matcher, $skipped);
        return $skipped;
    }

    /** @param list<string> $skipped */
    private static function copyDir(string $root, string $target, string $relative, PathMatcher $matcher, array &$skipped): void
    {
        $source = $relative === '' ? $root : "$root/$relative";
        $entries = scandir($source);
        if ($entries === false) {
            throw BuildException::make('build.read_failed', ['path' => $source]);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $rel = $relative === '' ? $entry : "$relative/$entry";
            $path = "$root/$rel";

            if (is_link($path)) {
                $skipped[] = $rel;
                continue;
            }
            $isDir = is_dir($path);
            if ($matcher->excludes($rel, $isDir)) {
                continue;
            }
            if ($isDir) {
                if (! is_dir("$target/$rel")) {
                    mkdir("$target/$rel", 0777, true);
                }
                self::copyDir($root, $target, $rel, $matcher, $skipped);
            } else {
                copy($path, "$target/$rel");
                chmod("$target/$rel", fileperms($path) & 0777);
            }
        }
    }
}
