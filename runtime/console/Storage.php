<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/** Writable directories under /tmp (the only writable path on Lambda), as the web runtime prepares them. */
final class Storage
{
    private const DIRECTORIES = [
        'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions',
        'storage/framework/views', 'storage/logs',
    ];

    public static function prepare(string $dir): void
    {
        foreach (self::DIRECTORIES as $sub) {
            $path = "$dir/$sub";
            if (! is_dir($path) && ! @mkdir($path, 0777, true) && ! is_dir($path)) {
                throw new \RuntimeException("Cannot create $path.");
            }
        }
    }
}
