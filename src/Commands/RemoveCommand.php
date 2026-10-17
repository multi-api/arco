<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\StepPrinter;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the typed confirmation')
            ->addOption('dns-profile', null, InputOption::VALUE_REQUIRED, 'AWS profile whose Route 53 holds the DNS zones of the custom domains');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
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

            if (! $input->getOption('force')) {
                if (! $input->isInteractive()) {
                    throw new UserError($this->t('remove.force_required'));
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
}
