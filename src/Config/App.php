<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

final class App
{
    use ConfiguresDeployment;

    private ?string $region = null;
    /** @var list<string> */
    private array $exclude = [];
    /** @var list<string> */
    private array $include = [];
    /** @var array<string, Environment> */
    private array $environments = [];

    private function __construct(private readonly string $name)
    {
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function region(string $region): self { $this->region = $region; return $this; }

    /** @param list<string> $patterns */
    public function exclude(array $patterns): self { $this->exclude = [...$this->exclude, ...$patterns]; return $this; }

    /** @param list<string> $patterns */
    public function include(array $patterns): self { $this->include = [...$this->include, ...$patterns]; return $this; }

    /** @param (callable(Environment): mixed)|null $configure */
    public function environment(string $name, ?callable $configure = null): self
    {
        $environment = $this->environments[$name] ??= new Environment();
        if ($configure !== null) {
            $configure($environment);
        }
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $deployment = $this->deploymentArray();
        return [
            'name' => $this->name,
            'region' => $this->region,
            'exclude' => $this->exclude,
            'include' => $this->include,
            'settings' => $deployment['settings'],
            'variables' => $deployment['variables'],
            'runtime' => $deployment['runtime'],
            'permissions' => $deployment['permissions'],
            'domains' => $deployment['domains'],
            'environments' => array_map(fn (Environment $e) => $e->toArray(), $this->environments),
        ];
    }
}
