<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

final class Runtime
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function build(string $id): self { $this->values['build'] = $id; return $this; }
    public function frankenphp(string $version): self { $this->values['frankenphp'] = $version; return $this; }
    public function binary(string $path): self { $this->values['binary'] = $path; return $this; }
    public function workers(int $min, int $max): self { $this->values['workers'] = ['min' => $min, 'max' => $max]; return $this; }
    public function threads(int $threads): self { $this->values['threads'] = $threads; return $this; }
    public function mode(string $mode): self { $this->values['mode'] = $mode; return $this; }

    /** @param array<string, string|int|bool> $ini */
    public function phpIni(array $ini): self
    {
        /** @var array<string, string|int|bool> $current */
        $current = $this->values['phpIni'] ?? [];
        $this->values['phpIni'] = [...$current, ...$ini];
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}
