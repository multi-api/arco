<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\StepPrinter;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ConfirmsProtected;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\Deployer;
use Multiapi\Arco\Deploy\Sleeper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'remove', description: 'Delete an environment (Web Function, endpoint and execution role)')]
final class RemoveCommand extends ArcoCommand
{
    use ConfirmsProtected;
    use ResolvesTarget;

    /** @var Closure(AwsClients, ?AwsClients, bool): Deployer */
    private Closure $deployerFactory;

    public function __construct(?Closure $awsFactory = null, ?Closure $deployerFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->deployerFactory = $deployerFactory ?? fn (AwsClients $aws, ?AwsClients $dns = null, bool $forceDns = false) => new AwsDeployer($aws, new Sleeper(), $dns, $forceDns);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment to remove')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Answer yes to every confirmation (no typed confirmation)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Deprecated: use --yes')
            ->addOption('dns-profile', null, InputOption::VALUE_REQUIRED, 'AWS profile whose Route 53 holds the DNS zones of the custom domains');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            if ($input->getOption('force')) {
                $io->warning($this->t('deprecated.force_remove'));
                $input->setOption('yes', true);
            }
            $config = $this->loadTarget($input, $io);
            $aws = $this->awsClients($config, $input, $io);
            $dns = $this->dnsClients($config, $input);
            $deployer = ($this->deployerFactory)($aws, $dns, false);

            // The CloudFront stack (or a certificate) may outlive the function, e.g. after a
            // half-finished remove or after domain() was deleted from arco.php.
            $domainResources = $deployer->hasDomainResources($config);
            if ($deployer->describe($config) === null && ! $domainResources) {
                $io->note($this->t('remove.nothing', ['function' => $config->functionName()]));
                return self::SUCCESS;
            }

            // What the tags say belongs to the environment, before anything is asked or deleted.
            $this->listResources($deployer, $config, $io);

            // Interactive: the typed confirmation is the protected() confirmation too.
            if (! $input->getOption('yes')) {
                if (! $input->isInteractive()) {
                    throw $config->isProtected() ? $this->protectedNeedsYes($config, 'remove') : new UserError($this->t('remove.yes_required'));
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
            $deployer->remove($config, function (string $key, array $params = []) use ($io): void {
                // A leftover AWS cannot be checked (e.g. the scheduler rule): a warning block, not a bullet.
                if (in_array($key, StepPrinter::WARNINGS, true)) {
                    $io->warning($this->t($key, $params));
                    return;
                }
                $io->writeln('  • ' . $this->t($key, $params));
            });
            $io->success($this->t('remove.done', ['function' => $config->functionName()]));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    private function listResources(Deployer $deployer, AppConfig $config, SymfonyStyle $io): void
    {
        try {
            $resources = $deployer->resources($config);
        } catch (Throwable $e) {
            // Only a list: without tag:GetResources the remove still goes on.
            $io->note($this->t('remove.resources_unlisted', ['error' => self::awsMessage($e)]));
            return;
        }
        if ($resources === []) {
            return;
        }
        $io->writeln($this->t('remove.resources', ['app' => $config->app, 'environment' => $config->environment, 'count' => count($resources)]));
        foreach ($resources as $resource) {
            $io->writeln('  • ' . $this->t($resource['retained'] ? 'remove.resource_kept' : 'remove.resource_deleted', ['type' => $resource['type'], 'name' => $resource['name']]));
        }
        $io->newLine();
    }

    private static function awsMessage(Throwable $e): string
    {
        $message = $e instanceof AwsException ? $e->getAwsErrorMessage() : null;
        return is_string($message) && $message !== '' ? $message : $e->getMessage();
    }
}
