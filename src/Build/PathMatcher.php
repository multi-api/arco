<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

final class PathMatcher
{
    /** @var list<string> */
    private array $exclude;
    /** @var list<string> */
    private array $include;
    /** @var list<string> hard-deny regexes that include patterns cannot override */
    private array $deny;
    /** @var list<string> literal directory prefixes of include patterns */
    private array $includePrefixes = [];

    /**
     * @param list<string> $exclude
     * @param list<string> $include
     * @param list<string> $deny patterns never packaged, even when matched by $include
     */
    public function __construct(array $exclude, array $include, array $deny = [])
    {
        $this->deny = array_map(self::toRegex(...), $deny);
        $this->exclude = array_map(self::toRegex(...), $exclude);
        $this->include = array_map(self::toRegex(...), $include);
        foreach ($include as $pattern) {
            $literal = preg_split('/[*?]/', ltrim($pattern, '/'))[0] ?? '';
            $this->includePrefixes[] = rtrim($literal, '/');
        }
    }

    public function excludes(string $relativePath, bool $isDir): bool
    {
        $candidates = $isDir ? [$relativePath, $relativePath . '/'] : [$relativePath];

        if ($this->matchesAny($this->deny, $candidates)) {
            return true;
        }
        if ($this->matchesAny($this->include, $candidates)) {
            return false;
        }
        if ($isDir) {
            foreach ($this->includePrefixes as $prefix) {
                if ($prefix !== '' && str_starts_with($prefix . '/', $relativePath . '/')) {
                    return false;
                }
            }
        }
        return $this->matchesAny($this->exclude, $candidates);
    }

    /**
     * @param list<string> $regexes
     * @param list<string> $candidates
     */
    private function matchesAny(array $regexes, array $candidates): bool
    {
        foreach ($regexes as $regex) {
            foreach ($candidates as $candidate) {
                if (preg_match($regex, $candidate) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function toRegex(string $glob): string
    {
        $glob = ltrim($glob, '/');
        $regex = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];
            if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
                if (($glob[$i + 2] ?? '') === '/') {
                    $regex .= '(?:.*/)?';
                    $i += 2;
                } else {
                    $regex .= '.*';
                    $i++;
                }
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }
        return '#^' . $regex . '$#';
    }
}
