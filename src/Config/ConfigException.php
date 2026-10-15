<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

use RuntimeException;

final class ConfigException extends RuntimeException
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key . ($params === [] ? '' : ' ' . json_encode($params)));
    }

    /** @param array<string, string|int> $params */
    public static function make(string $key, array $params = []): self
    {
        return new self($key, $params);
    }
}
