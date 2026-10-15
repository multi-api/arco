<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use RuntimeException;

final class DeployException extends RuntimeException
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $key, public readonly array $params = [], ?\Throwable $previous = null)
    {
        parent::__construct($key . ' ' . json_encode($params), 0, $previous);
    }

    /** @param array<string, string|int> $params */
    public static function make(string $key, array $params = [], ?\Throwable $previous = null): self
    {
        return new self($key, $params, $previous);
    }
}
