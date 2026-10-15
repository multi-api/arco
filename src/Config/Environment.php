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

    /** @return array{settings: array<string, string|int|bool>, variables: array<string, string>, runtime: array<string, mixed>, permissions: list<array<string, mixed>>, domains: list<string>|null} */
    public function toArray(): array
    {
        return $this->deploymentArray();
    }
}
