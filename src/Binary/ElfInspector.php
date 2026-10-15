<?php declare(strict_types=1);

namespace Multiapi\Arco\Binary;

final class ElfInspector
{
    private const EM_AARCH64 = 0xB7;

    public static function isLinuxArm64(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 20);
        fclose($handle);
        if ($header === false || strlen($header) < 20 || ! str_starts_with($header, "\x7FELF")) {
            return false;
        }
        $is64Bit = ord($header[4]) === 2;
        $littleEndian = ord($header[5]) === 1;
        /** @var array{1: int} $machine */
        $machine = unpack('v', substr($header, 18, 2));
        return $is64Bit && $littleEndian && $machine[1] === self::EM_AARCH64;
    }
}
