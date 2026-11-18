<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

/**
 * A small zip written by hand, byte for byte reproducible: entries sorted by name, one fixed modification
 * time (1980-01-01, the DOS epoch), fixed permissions, no extra fields. ZipArchive would stamp each entry
 * through the C library's local time, so the same files would not give the same bytes in every time zone.
 * For code sent inline to Lambda (UpdateFunctionCode with ZipFile), where an unchanged runtime should be
 * an unchanged zip.
 */
final class InlineZip
{
    private const DOS_TIME = 0;
    private const DOS_DATE = (0 << 9) | (1 << 5) | 1;
    private const UNIX_MODE = 0100644;

    /**
     * @param array<string, string> $files contents by path inside the zip ("/"-separated, no leading "/")
     */
    public static function build(array $files): string
    {
        ksort($files, SORT_STRING);
        $data = '';
        $directory = '';
        $count = 0;
        foreach ($files as $name => $contents) {
            $name = (string) $name;
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, '\\')) {
                throw new \InvalidArgumentException("Invalid zip entry name: $name");
            }
            $deflated = gzdeflate($contents, 9);
            if ($deflated === false) {
                throw new \RuntimeException("Cannot compress $name.");
            }
            $crc = crc32($contents);
            $size = strlen($contents);
            $compressed = strlen($deflated);
            $offset = strlen($data);
            // Version 2.0, UTF-8 names (bit 11), deflate.
            $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, self::DOS_TIME, self::DOS_DATE, $crc, $compressed, $size, strlen($name), 0) . $name . $deflated;
            // Made by Unix (3) version 2.0, so the permissions below are honoured.
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, (3 << 8) | 20, 20, 0x0800, 8, self::DOS_TIME, self::DOS_DATE, $crc, $compressed, $size, strlen($name), 0, 0, 0, 0, self::UNIX_MODE << 16, $offset) . $name;
            $count++;
        }
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($directory), strlen($data), 0);
        return $data . $directory . $end;
    }
}
