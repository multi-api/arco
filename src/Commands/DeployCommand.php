<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use GuzzleHttp\Client;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\StepPrinter;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\AwsVerifier;
use Multiapi\Arco\Deploy\Deployer;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Deploy\Sleeper;
use Multiapi\Arco\Deploy\Verifier;
use Multiapi\Arco\Package\PackageReader;
use Multiapi\Arco\Secrets\SecretResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'deploy', description: 'Build and deploy the application to AWS Lambda Web Functions')]
final class DeployCommand extends ArcoCommand
{
    use ResolvesTarget;

    private DeployPipeline $pipeline;
    /** @var Closure(AwsClients, ?AwsClients, bool): Deployer */
    private Closure $deployerFactory;
    /** @var Closure(AwsClients): Verifier */
    private Closure $verifierFactory;
    private PresetRegistry $presets;

    public function __construct(
        ?Closure $awsFactory = null,
        ?DeployPipeline $pipeline = null,
        ?Closure $deployerFactory = null,
        ?Closure $verifierFactory = null,
        ?PresetRegistry $presets = null,
    ) {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->pipeline = $pipeline ?? DeployPipeline::default();
        $this->deployerFactory = $deployerFactory ?? fn (AwsClients $aws, ?AwsClients $dns = null, bool $forceDns = false) => new AwsDeployer($aws, new Sleeper(), $dns, $forceDns);
        $this->verifierFactory = $verifierFactory ?? fn (AwsClients $aws) => new AwsVerifier($aws->logs(), new Client(), new Sleeper());
        $this->presets = $presets ?? PresetRegistry::default();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment to deploy')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation')
            ->addOption('dns-profile', null, InputOption::VALUE_REQUIRED, 'AWS profile whose Route 53 holds the DNS zones of the custom domains')
            ->addOption('force-dns', null, InputOption::VALUE_NONE, 'Replace existing DNS records of the custom domains that point elsewhere')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Deploy a zip written by "arco package" instead of building');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $steps = new StepPrinter($io, $this->t(...), $input->isInteractive() && $output->isDecorated());
        try {
            $config = $this->loadTarget($input, $io);
            $preset = $this->presets->detect($this->projectDir());

            // A prebuilt package is checked before any AWS call, so a wrong file fails fast.
            $package = null;
            $packagePath = $input->getOption('package');
            if (is_string($packagePath)) {
                $package = PackageReader::open($packagePath);
                foreach (PackageReader::check($package, $config, $preset, $this->pipeline->runtimeBuild($config)) as [$key, $params]) {
                    $io->warning($this->t($key, $params));
                }
            }

            $aws = $this->awsClients($config, $input, $io);

            $io->writeln($this->t('deploy.target', [
                'app' => $config->app,
                'environment' => $config->environment,
                'account' => $aws->accountId(),
                'profile' => $aws->profile ?? 'environment',
                'region' => (string) $config->region,
            ]));

            $dns = $config->domains === [] ? null : $this->dnsClients($config, $input);
            if ($dns !== null) {
                $io->writeln($this->t('deploy.dns_target', ['account' => $this->checkDnsCredentials($dns), 'profile' => (string) $dns->profile]));
            }

            $deployer = ($this->deployerFactory)($aws, $dns, (bool) $input->getOption('force-dns'));
            if ($deployer->describe($config) === null && ! $input->getOption('yes')) {
                if (! $input->isInteractive()) {
                    throw new UserError($this->t('deploy.confirm_required'));
                }
                if (! $io->confirm($this->t('deploy.confirm_create', ['function' => $config->functionName()]), false)) {
                    $io->writeln($this->t('common.aborted'));
                    return self::FAILURE;
                }
            }

            $variables = DeployPipeline::variables($config, $preset);
            $missing = (new SecretResolver($aws->ssm(), $aws->secretsManager()))->resolve($variables)['missing'];
            if ($missing !== []) {
                throw new UserError($this->t('deploy.secrets_missing', ['references' => implode(', ', $missing)]));
            }

            $verifier = ($this->verifierFactory)($aws);
            if ($package?->manifest !== null) {
                $steps('deploy.using_package', [
                    'path' => $package->path,
                    'created' => $package->manifest->createdAt,
                    'hash' => substr($package->hash, 0, 12),
                ]);
                $outcome = $this->pipeline->deployPackage($config, $preset, $package, $deployer, $verifier, $steps);
            } else {
                $outcome = $this->pipeline->deploy($config, $preset, $this->projectDir(), $deployer, $verifier, $steps);
            }

            if (! $outcome->verification->ok) {
                $v = $outcome->verification;
                $io->error($this->t('deploy.verify_failed', [
                    'status' => $v->httpStatus ?? ($v->httpError ?? '-'),
                    'init' => $v->initStatus === null ? '-' : $v->initStatus . ($v->initErrorType !== null ? " ($v->initErrorType)" : ''),
                ]));
                if ($v->reason !== null) {
                    $io->writeln($this->t($v->reason, $v->reasonParams));
                }
                foreach ($v->logTail as $line) {
                    $io->writeln("  $line");
                }
                $io->writeln($outcome->rolledBack
                    ? $this->t('deploy.rolled_back', ['revision' => (string) $outcome->result->previousRevisionId])
                    : $this->t('deploy.not_rolled_back'));
                return self::FAILURE;
            }

            $success = $this->t('deploy.success', [
                'url' => $outcome->result->url(),
                'revision' => $outcome->result->revisionId,
                'cold_start' => $outcome->verification->initDurationMs === null
                    ? $this->t('deploy.cold_start_unknown')
                    : $this->t('deploy.cold_start', ['ms' => $outcome->verification->initDurationMs]),
            ]);
            if ($outcome->result->domains !== []) {
                $success .= "\n" . $this->t('deploy.success_domains', ['urls' => implode("\n", array_map(fn (string $d) => "https://$d", $outcome->result->domains))]);
            }
            $io->success($success);

            if ($config->account === null && $input->isInteractive()
                && $io->confirm($this->t('aws.add_account_lock', ['environment' => $config->environment, 'account' => $aws->accountId()]), true)) {
                $params = ['environment' => $config->environment, 'account' => $aws->accountId()];
                ArcoFileEditor::addAccount($this->projectDir() . '/arco.php', $config->environment, $aws->accountId())
                    ? $io->writeln('<info>' . $this->t('aws.account_locked', $params) . '</info>')
                    : $io->warning($this->t('aws.account_lock_manual', $params));
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            $steps->finish();
            $message = $this->describeError($e);
            if ($message === null) {
                throw $e;
            }
            $io->error($message);
            return self::FAILURE;
        }
    }
}
