<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Aws\DnsZoneFinder;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ChoosesDns;
use Multiapi\Arco\Commands\Concerns\EditsDomains;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'domain:remove', description: 'Remove custom domains from an environment in arco.php')]
final class DomainRemoveCommand extends ArcoCommand
{
    use ResolvesTarget;
    use ChoosesDns;
    use EditsDomains;

    public function __construct(?Closure $awsFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->zoneFinder = DnsZoneFinder::default();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('hosts', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Domains to remove')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            /** @var list<string> $hosts */
            $hosts = (array) $input->getArgument('hosts');
            $removed = $this->parseDomains($hosts);
            $config = $this->loadTarget($input, $io);

            foreach (array_diff($removed, $config->domains) as $unknown) {
                $io->warning($this->t('domain.not_configured', ['domain' => $unknown, 'environment' => $config->environment]));
            }
            $domains = array_values(array_diff($config->domains, $removed));
            if ($domains === $config->domains) {
                throw new UserError($this->t('domain.nothing_removed', ['environment' => $config->environment]));
            }
            // dns() stays when the list becomes empty: harmless, and remove still finds the zone.
            $dns = self::currentDns($config);
            $this->writeDomains($config, $domains, $dns, false);

            $io->success($this->t('domain.removed', ['domains' => implode(', ', array_values(array_intersect($removed, $config->domains))), 'environment' => $config->environment]));
            $this->printResult($io, $config, $domains, $dns);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
