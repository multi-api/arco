<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

final class ValidationResult
{
    /** @var list<array{key: string, params: array<string, string|int>}> */
    public array $errors = [];
    /** @var list<array{key: string, params: array<string, string|int>}> */
    public array $warnings = [];

    /** @param array<string, string|int> $params */
    public function error(string $key, array $params = []): void
    {
        $this->errors[] = ['key' => $key, 'params' => $params];
    }

    /** @param array<string, string|int> $params */
    public function warning(string $key, array $params = []): void
    {
        $this->warnings[] = ['key' => $key, 'params' => $params];
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
