<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

final class Environment
{
    use ConfiguresDeployment;

    public function account(string $accountId): self
    {
        $this->settings['account'] = $accountId;
        return $this;
    }

    /**
     * Read by Arco's config loader. This shape minus `runtime` and `deployCommands` is the public contract of
     * the config layer's environment entry (docs/config-layer.md, "Config layer"): keys are only added to. The
     * `runtime` and `deployCommands` entries are internal and may change.
     *
     * @return array{settings: array<string, string|int|bool|array<string, string|array<int|string, string>>>, variables: array<string, string>, runtime: array<string, mixed>, permissions: list<array<string, mixed>>, domains: list<string>|null, deployCommands: list<string>|null, queues: array<string, array<string, mixed>|false>, storage: array<string, array<string, mixed>|false>, tables?: array<string, array<string, mixed>|false>}
     */
    public function toArray(): array
    {
        return $this->deploymentArray();
    }
}
