<?php declare(strict_types=1);

namespace Multiapi\Arco\Binary;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class GuzzleDownloader implements Downloader
{
    public function __construct(private readonly Client $client = new Client(['timeout' => 600, 'connect_timeout' => 15]))
    {
    }

    public function download(string $url, string $destination, ?callable $onProgress = null): void
    {
        try {
            $options = ['sink' => $destination];
            if ($onProgress !== null) {
                $options['progress'] = static function (int $total, int $downloaded, int $uploadTotal = 0, int $uploaded = 0) use ($onProgress): void {
                    $onProgress($downloaded, $total);
                };
            }
            $this->client->request('GET', $url, $options);
        } catch (GuzzleException $e) {
            @unlink($destination);
            throw BinaryException::make('binary.download_failed', ['url' => $url, 'message' => $e->getMessage()]);
        }
    }
}
