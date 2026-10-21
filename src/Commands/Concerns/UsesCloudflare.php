<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Closure;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\CloudflareApi;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The Cloudflare API token of dns('cloudflare'): --cloudflare-token, else CLOUDFLARE_API_TOKEN. Arco never
 * stores, prints or creates it.
 */
trait UsesCloudflare
{
    /** @var (Closure(string): CloudflareApi)|null */
    protected ?Closure $cloudflareFactory = null;

    protected function addCloudflareTokenOption(): void
    {
        $this->addOption('cloudflare-token', null, InputOption::VALUE_REQUIRED, 'Cloudflare API token for dns(\'cloudflare\') (default: the ' . CloudflareApi::TOKEN_ENV . ' environment variable)');
    }

    protected function cloudflareToken(InputInterface $input): ?string
    {
        $flag = $input->hasOption('cloudflare-token') ? $input->getOption('cloudflare-token') : null;
        if (is_string($flag) && trim($flag) !== '') {
            return trim($flag);
        }
        $env = getenv(CloudflareApi::TOKEN_ENV);
        return is_string($env) && trim($env) !== '' ? trim($env) : null;
    }

    /** The API client, or null without a token. */
    protected function cloudflareApiOrNull(InputInterface $input): ?CloudflareApi
    {
        $token = $this->cloudflareToken($input);
        if ($token === null) {
            return null;
        }
        return $this->cloudflareFactory !== null ? ($this->cloudflareFactory)($token) : CloudflareApi::create($token);
    }

    /** @throws DeployException cloudflare.token_missing */
    protected function cloudflareApi(InputInterface $input): CloudflareApi
    {
        return $this->cloudflareApiOrNull($input)
            ?? throw DeployException::make('cloudflare.token_missing', ['env' => CloudflareApi::TOKEN_ENV, 'permissions' => CloudflareApi::PERMISSIONS]);
    }
}
