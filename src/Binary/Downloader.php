<?php declare(strict_types=1);

namespace Multiapi\Arco\Binary;

interface Downloader
{
    /** @param (callable(int $downloaded, int $total): void)|null $onProgress */
    public function download(string $url, string $destination, ?callable $onProgress = null): void;
}
