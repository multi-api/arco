<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Aws\DnsZoneFinder;
use Multiapi\Arco\Aws\LocalState;
use Multiapi\Arco\Aws\ProfileResolver;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ChoosesDns;
use Multiapi\Arco\Commands\Concerns\EditsDomains;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Validator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'domain:add', description: 'Add custom domains to an environment in arco.php')]
final class DomainAddCommand extends ArcoCommand
{
    use ResolvesTarget;
    use ChoosesDns;
    use EditsDomains;

    public function __construct(?Closure $awsFactory = null, ?DnsZoneFinder $zoneFinder = null, ?Closure $cloudflareFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->zoneFinder = $zoneFinder ?? DnsZoneFinder::default();
        $this->cloudflareFactory = $cloudflareFactory;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('hosts', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Domains to add, e.g. app.example.com')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile used to deploy (its zones need no dns() call)')
            ->addOption('dns-profile', null, InputOption::VALUE_REQUIRED, 'AWS profile whose Route 53 holds the DNS zones')
            ->addOption('dns', null, InputOption::VALUE_REQUIRED, '"manual" when the DNS is not in Route 53, "cloudflare" when the zones are on Cloudflare');
        $this->addCloudflareTokenOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            /** @var list<string> $hosts */
            $hosts = (array) $input->getArgument('hosts');
            $added = $this->parseDomains($hosts);
            $flagDns = $this->dnsFromFlags($input);
            $config = $this->loadTarget($input, $io);
            if ($config->auth === 'iam') {
                throw new UserError($this->t('validation.domain_iam'));
            }

            $domains = array_values(array_unique([...$config->domains, ...$added]));
            if (count($domains) > Validator::MAX_DOMAINS) {
                throw new UserError($this->t('validation.domain_count', ['count' => count($domains), 'max' => Validator::MAX_DOMAINS]));
            }
            $current = self::currentDns($config);
            $dns = $flagDns !== null || ($current === null)
                ? $this->chooseDns($input, $io, $domains, $this->deployProfile($input, $config))
                : $this->keepDns($input, $io, $config, $domains, $current);

            if ($domains === $config->domains && $dns === $current) {
                $io->note($this->t('domain.unchanged'));
            } else {
                $this->writeDomains($config, $domains, $dns, $dns !== $current);
                $io->success($this->t('domain.added', ['domains' => implode(', ', $added), 'environment' => $config->environment]));
            }
            $this->printResult($io, $config, $domains, $dns);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /**
     * An explicit dns() in arco.php only changes on a flag or the user's answer: dns(false) stays
     * without lookups; dns('X') (or dns('cloudflare')) stays when X holds every zone, else
     * non-interactive fails and interactive asks where the DNS is, with X preselected.
     *
     * @param list<string> $domains
     */
    private function keepDns(InputInterface $input, SymfonyStyle $io, AppConfig $config, array $domains, string|false $current): string|false|null
    {
        if ($current === false) {
            return false;
        }
        $problem = $current === ConfigResolver::CLOUDFLARE ? $this->checkCloudflare($input, $io, $domains) : $this->checkProfile($io, $domains, $current);
        if ($problem === null) {
            return $current;
        }
        if (! $input->isInteractive()) {
            throw new UserError($problem . "\n" . $this->t('dns.use_flags'));
        }
        $io->warning($problem);
        return $this->chooseDns($input, $io, $domains, $this->deployProfile($input, $config), $current);
    }

    /** The profile deploy would use, when it is known without asking. */
    private function deployProfile(InputInterface $input, AppConfig $config): ?string
    {
        /** @var array<string, string> $env */
        $env = getenv();
        $flag = $input->getOption('profile');
        return ProfileResolver::resolve(is_string($flag) ? $flag : null, $env, $config, new LocalState($this->projectDir()))?->profile;
    }
}
