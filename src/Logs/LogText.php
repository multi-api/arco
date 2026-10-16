<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

use Symfony\Component\Console\Formatter\OutputFormatter;

/** Log text from the function made safe to print on a terminal. */
final class LogText
{
    /** Line breaks become spaces, other C0 controls but tab (and DEL) go, and console markup is escaped. */
    public static function escape(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', str_replace(["\r\n", "\n", "\r"], ' ', $text));
        return OutputFormatter::escape($text);
    }
}
