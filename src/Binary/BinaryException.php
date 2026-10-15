<?php declare(strict_types=1);

namespace Multiapi\Arco\Binary;

use RuntimeException;

final class BinaryException extends RuntimeException
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key . ' ' . json_encode($params));
    }

    /** @param array<string, string|int> $params */
    public static function make(string $key, array $params = []): self
    {
        return new self($key, $params);
    }
}
