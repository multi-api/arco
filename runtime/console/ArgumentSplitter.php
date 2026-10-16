<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/**
 * Splits a command line into arguments with POSIX shell quoting rules, without a shell: single
 * quotes are literal, double quotes allow \" \\ \$ \` escapes, a backslash outside quotes escapes the
 * next character. Variables, globs, pipes, redirections and ; are never interpreted.
 */
final class ArgumentSplitter
{
    private const DOUBLE_QUOTE_ESCAPES = ['"', '\\', '$', '`'];

    /**
     * @return list<string>
     * @throws \InvalidArgumentException on an unclosed quote or a trailing backslash
     */
    public static function split(string $line): array
    {
        $args = [];
        $current = '';
        $inArgument = false;
        $quote = null;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                } else {
                    $current .= $char;
                }
                continue;
            }
            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                } elseif ($char === '\\' && $i + 1 < $length && in_array($line[$i + 1], self::DOUBLE_QUOTE_ESCAPES, true)) {
                    $current .= $line[++$i];
                } else {
                    $current .= $char;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $inArgument = true;
                continue;
            }
            if ($char === '\\') {
                if ($i + 1 >= $length) {
                    throw new \InvalidArgumentException('The command ends with a backslash.');
                }
                $current .= $line[++$i];
                $inArgument = true;
                continue;
            }
            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                if ($inArgument) {
                    $args[] = $current;
                    $current = '';
                    $inArgument = false;
                }
                continue;
            }
            $current .= $char;
            $inArgument = true;
        }
        if ($quote !== null) {
            throw new \InvalidArgumentException("The command has an unclosed $quote quote.");
        }
        if ($inArgument) {
            $args[] = $current;
        }
        return $args;
    }
}
