<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\JsonStream;
use Multiapi\Arco\Cli\StepPrinter;
use Multiapi\Arco\Commands\Concerns\ConfirmsProtected;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Commands\Concerns\StreamsJson;
use Multiapi\Arco\Commands\Concerns\UsesCloudflare;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\Deployer;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\CloudflareApi;
use Multiapi\Arco\Deploy\Domains\CloudflareDns;
use Multiapi\Arco\Deploy\Domains\DnsProvider;
use Multiapi\Arco\Deploy\Sleeper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'remove', description: 'Delete an environment (Web Function, endpoint and execution role)')]
final class RemoveCommand extends ArcoCommand
{
    use ConfirmsProtected;
    use ResolvesTarget;
    use StreamsJson;
    use UsesCloudflare;

    /** @var Closure(AwsClients, ?AwsClients, bool, ?DnsProvider): Deployer */
    private Closure $deployerFactory;
    /** --json: whether a deletion step started (a failure after it is "failed", before it "refused") */
    private bool $deleting = false;

    /** @param (Closure(): float)|null $clock */
    public function __construct(?Closure $awsFactory = null, ?Closure $deployerFactory = null, ?Closure $cloudflareFactory = null, ?Closure $clock = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->deployerFactory = $deployerFactory ?? fn (AwsClients $aws, ?AwsClients $dns = null, bool $forceDns = false, ?DnsProvider $dnsProvider = null) => new AwsDeployer($aws, new Sleeper(), $dns, $forceDns, false, $dnsProvider);
        $this->cloudflareFactory = $cloudflareFactory;
        $this->clock = $clock ?? fn (): float => microtime(true);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment to remove')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Answer yes to every confirmation (no typed confirmation)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Deprecated: use --yes')
            ->addOption('dns-profile', null, InputOption::VALUE_REQUIRED, 'AWS profile whose Route 53 holds the DNS zones of the custom domains')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print NDJSON step events and a final result line on stdout (never asks: implies --no-interaction)');
        $this->addCloudflareTokenOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->deleting = false;
        if (! $input->getOption('json')) {
            return $this->remove($input, new SymfonyStyle($input, $output), null);
        }
        // The same format as deploy --json.
        return $this->runJson($input, $output, fn (OutputInterface $stderr, JsonStream $stream): int => $this->remove($input, new SymfonyStyle($input, new NullOutput()), $stream));
    }

    protected function jsonChanged(): bool
    {
        return $this->deleting;
    }

    protected function jsonRolledBack(): bool
    {
        return false;
    }

    protected function jsonSucceeded(): string
    {
        return 'removed';
    }

    /** The remove. With --json, $stream gets the steps and the result and $io writes nowhere. */
    private function remove(InputInterface $input, SymfonyStyle $io, ?JsonStream $stream): int
    {
        try {
            if ($input->getOption('force')) {
                $io->warning($this->t('deprecated.force_remove'));
                $stream?->warning('deprecated.force_remove');
                $input->setOption('yes', true);
            }
            $config = $this->loadTarget($input, $io, $stream === null ? null : $stream->warning(...));
            $this->jsonTarget($stream, $config);
            $cloudflare = null;
            if ($config->dnsCloudflare) {
                $profile = $input->getOption('dns-profile');
                if (is_string($profile) && $profile !== '') {
                    throw $this->userError('cloudflare.dns_profile_conflict');
                }
                $api = $this->cloudflareApiOrNull($input);
                $cloudflare = $api === null ? null : new CloudflareDns($api);
            }
            $aws = $this->awsClients($config, $input, $io);
            // A Cloudflare environment never builds Route 53 clients, whatever it used before.
            $dns = $config->dnsCloudflare ? null : $this->dnsClients($config, $input);
            $deployer = ($this->deployerFactory)($aws, $dns, false, $cloudflare);

            // The CloudFront stack (or a certificate) may outlive the function, e.g. after a
            // half-finished remove or after domain() was deleted from arco.php.
            $domainResources = $deployer->hasDomainResources($config);
            if ($deployer->describe($config) === null && ! $domainResources) {
                $io->note($this->t('remove.nothing', ['function' => $config->functionName()]));
                $stream?->note('remove.nothing', ['function' => $config->functionName()]);
                $stream?->result('removed', $config->app, $config->environment);
                return self::SUCCESS;
            }

            // The DNS records go first in a remove: without a working token nothing is deleted.
            if ($config->dnsCloudflare && $domainResources) {
                if ($cloudflare === null) {
                    throw DeployException::make('cloudflare.token_missing', ['env' => CloudflareApi::TOKEN_ENV, 'permissions' => CloudflareApi::PERMISSIONS]);
                }
                $cloudflare->verify([]);
            }

            // What the tags say belongs to the environment, before anything is asked or deleted.
            $this->listResources($deployer, $config, $io, $stream);

            // Interactive: the typed confirmation is the protected() confirmation too.
            if (! $input->getOption('yes')) {
                if (! $input->isInteractive()) {
                    throw $config->isProtected() ? $this->protectedNeedsYes($config, 'remove') : $this->userError('remove.yes_required');
                }
                $typed = $io->ask($this->t('remove.type_to_confirm', [
                    'function' => $config->functionName(),
                    'account' => $aws->accountId(),
                    'environment' => $config->environment,
                ]));
                if ($typed !== $config->environment) {
                    $io->writeln($this->t('common.aborted'));
                    return self::FAILURE;
                }
            }

            if ($dns !== null && $domainResources) {
                $this->checkDnsCredentials($dns);
            }
            $deployer->remove($config, function (string $key, array $params = []) use ($io, $stream): void {
                if ($stream !== null) {
                    // The steps are announced before what they delete: from the first one on, AWS changes.
                    $this->deleting = $this->deleting || ! in_array($key, StepPrinter::WARNINGS, true);
                    $stream($key, $params);
                    return;
                }
                // A leftover AWS cannot be checked (e.g. the scheduler rule): a warning block, not a bullet.
                if (in_array($key, StepPrinter::WARNINGS, true)) {
                    $io->warning($this->t($key, $params));
                    return;
                }
                $io->writeln('  • ' . $this->t($key, $params));
            });
            $io->success($this->t('remove.done', ['function' => $config->functionName()]));
            $stream?->result('removed', $config->app, $config->environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $message = $this->describeError($e) ?? throw $e;
            $io->error($message);
            if ($stream !== null) {
                $this->jsonFailure($stream, $input, $e, $this->describeErrorKey($e), $message);
            }
            return self::FAILURE;
        }
    }

    private function listResources(Deployer $deployer, AppConfig $config, SymfonyStyle $io, ?JsonStream $stream): void
    {
        try {
            $resources = $deployer->resources($config);
        } catch (Throwable $e) {
            // Only a list: without tag:GetResources the remove still goes on.
            $io->note($this->t('remove.resources_unlisted', ['error' => self::awsMessage($e)]));
            $stream?->note('remove.resources_unlisted', ['error' => self::awsMessage($e)]);
            return;
        }
        if ($resources === []) {
            return;
        }
        $params = ['app' => $config->app, 'environment' => $config->environment, 'count' => count($resources)];
        $io->writeln($this->t('remove.resources', $params));
        $stream?->note('remove.resources', $params);
        foreach ($resources as $resource) {
            $key = $resource['retained'] ? 'remove.resource_kept' : 'remove.resource_deleted';
            $io->writeln('  • ' . $this->t($key, ['type' => $resource['type'], 'name' => $resource['name']]));
            $stream?->note($key, ['type' => $resource['type'], 'name' => $resource['name']]);
        }
        $io->newLine();
    }

    private static function awsMessage(Throwable $e): string
    {
        $message = $e instanceof AwsException ? $e->getAwsErrorMessage() : null;
        return is_string($message) && $message !== '' ? $message : $e->getMessage();
    }
}
