<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/** The invocation response {"exitCode": int, "output": string}, kept under Lambda's 6 MB limit. */
final class Response
{
    /** Lambda refuses synchronous responses above 6 MB (6,291,456 bytes); this leaves headroom. */
    public const MAX_BYTES = 6_000_000;
    private const FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function encode(int $exitCode, string $output, int $maxBytes = self::MAX_BYTES): string
    {
        $json = json_encode(['exitCode' => $exitCode, 'output' => $output], self::FLAGS);
        while (strlen($json) > $maxBytes) {
            // Escaping can make text up to 6x longer (control characters become \u00XX): drop more from the start.
            $body = str_starts_with($output, OutputBuffer::MARKER) ? substr($output, strlen(OutputBuffer::MARKER)) : $output;
            $keep = (int) floor(strlen($body) * ($maxBytes / strlen($json)) * 0.9);
            $output = OutputBuffer::MARKER . ($keep > 0 ? substr($body, -$keep) : '');
            $json = json_encode(['exitCode' => $exitCode, 'output' => $output], self::FLAGS);
        }
        return $json;
    }
}
