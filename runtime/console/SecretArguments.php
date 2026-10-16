<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/**
 * Masks the values of secret-looking options (--password=..., --api-key ...) before a command line is
 * written to the function log. The command itself still gets the real values; the rest of the line is
 * kept for the audit trail.
 */
final class SecretArguments
{
    public const MASK = '***';
    /** Words of an option name (split on - _ . and camelCase) that make it secret-looking. */
    private const SECRET_WORDS = [
        'password', 'passwords', 'passwd', 'secret', 'secrets', 'token', 'tokens', 'key', 'keys',
        'apikey', 'apikeys', 'credential', 'credentials', 'auth', 'private',
    ];
    private const OPTION = '/^--?([A-Za-z0-9][A-Za-z0-9_.-]*)(=.*)?$/s';

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    public static function mask(array $arguments): array
    {
        $masked = [];
        $hideNext = false;
        foreach ($arguments as $argument) {
            // The value of a secret option given without = is the next argument, even one starting with -.
            if ($hideNext) {
                $masked[] = self::MASK;
                $hideNext = false;
                continue;
            }
            $hideNext = false;
            if (preg_match(self::OPTION, $argument, $m) === 1 && self::isSecret($m[1])) {
                if (isset($m[2])) {
                    $argument = substr($argument, 0, -strlen($m[2])) . '=' . self::MASK;
                } else {
                    $hideNext = true;
                }
            }
            $masked[] = $argument;
        }
        return $masked;
    }

    /** The command line as written, or rebuilt with masked values when it holds a secret-looking option. */
    public static function maskLine(string $line): string
    {
        try {
            $arguments = ArgumentSplitter::split($line);
        } catch (\InvalidArgumentException) {
            return self::maskUnsplittable($line);
        }
        $masked = self::mask($arguments);
        if ($masked === $arguments) {
            return $line;
        }
        return implode(' ', array_map(self::quote(...), $masked));
    }

    /** --api-key, --db_password, --dbPassword, --auth-token: yes; --author, --monkey, --keyboard: no. */
    private static function isSecret(string $name): bool
    {
        $words = preg_split('/[-_.]+|(?<=[a-z0-9])(?=[A-Z])/', $name) ?: [];
        foreach ($words as $word) {
            if (in_array(strtolower($word), self::SECRET_WORDS, true)) {
                return true;
            }
        }
        return false;
    }

    private static function quote(string $argument): string
    {
        if ($argument !== '' && preg_match('#^[A-Za-z0-9_\-.,:/=@%+*]+$#', $argument) === 1) {
            return $argument;
        }
        return "'" . str_replace("'", "'\\''", $argument) . "'";
    }

    /**
     * A line with an unclosed quote is logged too (the command then fails). Without a split nothing tells
     * where a value ends, so everything from the first secret-looking option on is replaced.
     */
    private static function maskUnsplittable(string $line): string
    {
        preg_match_all('/(?<=^|\s)--?([A-Za-z0-9][A-Za-z0-9_.-]*)(=?)/', $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            if (self::isSecret($match[1][0])) {
                $option = $match[0][1] + strlen($match[0][0]) - strlen($match[2][0]);
                return substr($line, 0, $option) . ($match[2][0] === '=' ? '=' : ' ') . self::MASK;
            }
        }
        return $line;
    }
}
