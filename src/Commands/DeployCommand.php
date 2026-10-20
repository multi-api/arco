<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use GuzzleHttp\Client;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Aws\Endpoint;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\StepPrinter;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Console\Console;
use Multiapi\Arco\Console\LambdaConsole;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\AwsVerifier;
use Multiapi\Arco\Deploy\CommandPlan;
use Multiapi\Arco\Deploy\DeployCommandFailed;
use Multiapi\Arco\Deploy\DeployCommands;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Deployer;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Deploy\Sleeper;
use Multiapi\Arco\Deploy\Verifier;
use Multiapi\Arco\Package\PackageReader;
use Multiapi\Arco\Secrets\SecretResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
    /** @var Closure(AwsClients, ?AwsClients, bool, bool): Deployer */
    private Closure $deployerFactory;
    /** @var Closure(AwsClients): Verifier */
    private Closure $verifierFactory;
    private PresetRegistry $presets;
    /** @var Closure(AwsClients, AppConfig): Console */
    private Closure $consoleFactory;

    public function __construct(
        ?Closure $awsFactory = null,
        ?DeployPipeline $pipeline = null,
        ?Closure $deployerFactory = null,
        ?Closure $verifierFactory = null,
        ?PresetRegistry $presets = null,
        ?Closure $consoleFactory = null,
    ) {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->pipeline = $pipeline ?? DeployPipeline::default();
        $this->deployerFactory = $deployerFactory ?? fn (AwsClients $aws, ?AwsClients $dns = null, bool $forceDns = false, bool $force = false) => new AwsDeployer($aws, new Sleeper(), $dns, $forceDns, $force);
        $this->verifierFactory = $verifierFactory ?? fn (AwsClients $aws) => new AwsVerifier($aws->logs(), new Client(), new Sleeper());
        $this->presets = $presets ?? PresetRegistry::default();
        $this->consoleFactory = $consoleFactory ?? fn (AwsClients $aws, AppConfig $config) => new LambdaConsole($aws->lambda(LambdaConsole::clientOptions($config)));
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment to deploy')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Answer yes to every confirmation')
            ->addOption('dns-profile', null, InputOption::VALUE_REQUIRED, 'AWS profile whose Route 53 holds the DNS zones of the custom domains')
            ->addOption('force-dns', null, InputOption::VALUE_NONE, 'Replace existing DNS records of the custom domains that point elsewhere')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Dangerous: deploy over a function, role or scheduler rule tagged for another app or environment, or carrying no Arco tags, and tag it for this one. Only when you are sure it is yours')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Deploy a zip written by "arco package" instead of building')
            ->addOption('migrate', null, InputOption::VALUE_NONE, 'Run "migrate --force" before traffic moves (added to the deploy commands)')
            ->addOption('no-deploy-commands', null, InputOption::VALUE_NONE, 'Skip the deploy commands of arco.php');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $steps = new StepPrinter($io, $this->t(...), $input->isInteractive() && $output->isDecorated());
        /** @var list<string> $ran deploy commands that ran, for the messages of a later failure */
        $ran = [];
        $config = null;
        try {
            $config = $this->loadTarget($input, $io);
            $preset = $this->presets->detect($this->projectDir());

            $migrate = (bool) $input->getOption('migrate');
            $skipCommands = (bool) $input->getOption('no-deploy-commands');
            if ($migrate && $skipCommands) {
                throw new UserError($this->t('deploy.migrate_conflict'));
            }
            if ($migrate && $preset->name() !== 'laravel') {
                throw new UserError($this->t('deploy.migrate_laravel_only'));
            }
            $commands = DeployCommands::resolve($config->deployCommands, $migrate, $skipCommands);
            // Before any AWS call: deploy commands run on the console function (the pipeline checks it too).
            if ($commands !== [] && ! $config->consoleEnabled()) {
                throw DeployException::make('deploy.commands_need_console', ['environment' => $config->environment]);
            }

            // A prebuilt package is checked before any AWS call, so a wrong file fails fast.
            $package = null;
            $packagePath = $input->getOption('package');
            if (is_string($packagePath)) {
                $package = PackageReader::open($packagePath);
                foreach (PackageReader::check($package, $config, $preset, $this->pipeline->runtimeBuild($config), $this->pipeline->canonicalBuild(...)) as [$key, $params]) {
                    $io->warning($this->t($key, $params));
                }
            }

            // Before the client setup, so the note shows even when the profile or STS fails.
            if (($endpoint = Endpoint::override()) !== null) {
                $io->writeln($this->t('deploy.endpoint_override', ['endpoint' => $endpoint]));
            } elseif (($raw = Endpoint::unrecognised()) !== null) {
                $io->warning($this->t('deploy.endpoint_unrecognised', ['value' => $raw]));
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

            $deployer = ($this->deployerFactory)($aws, $dns, (bool) $input->getOption('force-dns'), (bool) $input->getOption('force'));
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
            $plan = $commands === [] ? null : new CommandPlan($commands, ($this->consoleFactory)($aws, $config), function (string $command, CommandResult $result) use ($output, $steps, &$ran): void {
                $ran[] = $command;
                $steps->finish();
                $output->write($result->output, false, OutputInterface::OUTPUT_RAW);
                if ($result->output !== '' && ! str_ends_with($result->output, "\n")) {
                    $output->writeln('');
                }
            });
            // Queue URLs carry the account: when arco.php does not lock one, the credentials' account is it.
            $target = $config->account === null ? $config->withAccount($aws->accountId()) : $config;
            if ($package?->manifest !== null) {
                $steps('deploy.using_package', [
                    'path' => $package->path,
                    'created' => $package->manifest->createdAt,
                    'hash' => substr($package->hash, 0, 12),
                ]);
                $outcome = $this->pipeline->deployPackage($target, $preset, $package, $deployer, $verifier, $steps, $plan, $this->projectDir());
            } else {
                $outcome = $this->pipeline->deploy($target, $preset, $this->projectDir(), $deployer, $verifier, $steps, $plan);
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
                $this->commandsNotUndone($io, $outcome->commandsRan, $config);
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

            // Behind an endpoint override the account is the emulator's (Floci: 000000000000), not one to lock.
            if ($config->account === null && $input->isInteractive() && Endpoint::override() === null
                && $io->confirm($this->t('aws.add_account_lock', ['environment' => $config->environment, 'account' => $aws->accountId()]), true)) {
                $params = ['environment' => $config->environment, 'account' => $aws->accountId()];
                ArcoFileEditor::addAccount($this->projectDir() . '/arco.php', $config->environment, $aws->accountId())
                    ? $io->writeln('<info>' . $this->t('aws.account_locked', $params) . '</info>')
                    : $io->warning($this->t('aws.account_lock_manual', $params));
            }
            return self::SUCCESS;
        } catch (DeployCommandFailed $e) {
            $steps->finish();
            $result = $e->result;
            if ($result === null) {
                // The invoke failed: the command may have partly run, so it is listed with the others.
                $previous = $e->getPrevious();
                $io->error($this->t('deploy.command_unknown', [
                    'command' => $e->command,
                    'error' => $previous === null ? '' : ($this->describeError($previous) ?? $previous->getMessage()),
                ]));
                $io->writeln($this->t('deploy.command_traffic_unchanged'));
                $this->commandsNotUndone($io, $e->ran, $config, 'deploy.commands_may_have_run');
                return self::FAILURE;
            }
            $io->error($result->crashed()
                ? $this->t('deploy.command_crashed', ['command' => $e->command, 'type' => (string) $result->errorType, 'message' => (string) $result->errorMessage])
                : $this->t('deploy.command_failed', ['command' => $e->command, 'code' => $result->exitCode]));
            foreach ($result->logTail as $line) {
                $io->writeln('  ' . OutputFormatter::escape($line));
            }
            $io->writeln($this->t('deploy.command_traffic_unchanged'));
            // The failing command is reported above; a migrate that failed may have rolled itself back.
            $this->commandsNotUndone($io, array_slice($e->ran, 0, -1), $config);
            // R12: the command's own exit code; 1 when it has none (a Lambda error, or 0 on a crash).
            return ! $result->crashed() && $result->exitCode > 0 ? min($result->exitCode, 255) : self::FAILURE;
        } catch (Throwable $e) {
            $steps->finish();
            $message = $this->describeError($e);
            if ($message !== null) {
                $io->error($message);
            }
            if ($config !== null) {
                $this->commandsNotUndone($io, $ran, $config);
            }
            return $message === null ? throw $e : self::FAILURE;
        }
    }

    /**
     * After deploy commands ran and the deploy then failed: their changes stay (spec §5).
     *
     * @param list<string> $ran
     */
    private function commandsNotUndone(SymfonyStyle $io, array $ran, AppConfig $config, string $key = 'deploy.commands_not_undone'): void
    {
        if ($ran === []) {
            return;
        }
        $io->writeln($this->t($key, ['commands' => implode(', ', $ran)]));
        if (DeployCommands::hasMigrate($ran)) {
            $io->writeln($this->t('deploy.migrate_rollback_hint', ['environment' => $config->environment, 'yes' => $config->isProtected() ? ' --yes' : '']));
        }
    }
}
