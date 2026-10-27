<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use GuzzleHttp\Client;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Aws\Endpoint;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\JsonStream;
use Multiapi\Arco\Cli\StepPrinter;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Commands\Concerns\StreamsJson;
use Multiapi\Arco\Commands\Concerns\UsesCloudflare;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\PermissionsBoundary;
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Console\Console;
use Multiapi\Arco\Console\LambdaConsole;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\AwsVerifier;
use Multiapi\Arco\Deploy\EndpointSigner;
use Multiapi\Arco\Deploy\RevisionFactory;
use Multiapi\Arco\Deploy\ChangeTrackingDeployer;
use Multiapi\Arco\Deploy\CommandPlan;
use Multiapi\Arco\Deploy\DeployCommandFailed;
use Multiapi\Arco\Deploy\DeployCommands;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Deployer;
use Multiapi\Arco\Deploy\Domains\CloudflareDns;
use Multiapi\Arco\Deploy\Domains\DnsProvider;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Deploy\Sleeper;
use Multiapi\Arco\Deploy\Verifier;
use Multiapi\Arco\Package\PackageReader;
use Multiapi\Arco\Secrets\SecretResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'deploy', description: 'Build and deploy the application to AWS Lambda Web Functions')]
final class DeployCommand extends ArcoCommand
{
    use ResolvesTarget;
    use StreamsJson;
    use UsesCloudflare;

    private DeployPipeline $pipeline;
    /** @var Closure(AwsClients, ?AwsClients, bool, bool, ?DnsProvider): Deployer */
    private Closure $deployerFactory;
    /** @var Closure(AwsClients): Verifier */
    private Closure $verifierFactory;
    private PresetRegistry $presets;
    /** @var Closure(AwsClients, AppConfig): Console */
    private Closure $consoleFactory;
    /** --json: the deployer of the running deploy, which knows whether AWS changed */
    private ?ChangeTrackingDeployer $tracked = null;

    public function __construct(
        ?Closure $awsFactory = null,
        ?DeployPipeline $pipeline = null,
        ?Closure $deployerFactory = null,
        ?Closure $verifierFactory = null,
        ?PresetRegistry $presets = null,
        ?Closure $consoleFactory = null,
        ?Closure $cloudflareFactory = null,
        ?Closure $clock = null,
    ) {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->pipeline = $pipeline ?? DeployPipeline::default();
        $this->deployerFactory = $deployerFactory ?? fn (AwsClients $aws, ?AwsClients $dns = null, bool $forceDns = false, bool $force = false, ?DnsProvider $dnsProvider = null) => new AwsDeployer($aws, new Sleeper(), $dns, $forceDns, $force, $dnsProvider);
        $this->verifierFactory = $verifierFactory ?? fn (AwsClients $aws) => new AwsVerifier($aws->logs(), new Client(), new Sleeper(), signer: EndpointSigner::for($aws));
        $this->presets = $presets ?? PresetRegistry::default();
        $this->consoleFactory = $consoleFactory ?? fn (AwsClients $aws, AppConfig $config) => new LambdaConsole($aws->lambda(LambdaConsole::clientOptions($config)));
        $this->cloudflareFactory = $cloudflareFactory;
        $this->clock = $clock ?? fn (): float => microtime(true);
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
            ->addOption('no-deploy-commands', null, InputOption::VALUE_NONE, 'Skip the deploy commands of arco.php')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print NDJSON step events and a final result line on stdout (never asks: implies --no-interaction)')
            ->addOption(PermissionsBoundary::OPTION, null, InputOption::VALUE_REQUIRED, 'IAM policy ARN for the execution role\'s permissions boundary (overrides ARCO_PERMISSIONS_BOUNDARY and arco.php)');
        $this->addCloudflareTokenOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->tracked = null;
        if (! $input->getOption('json')) {
            return $this->deploy($input, $output, new SymfonyStyle($input, $output), null);
        }
        // Deploy command output goes to stderr with the other diagnostics.
        return $this->runJson($input, $output, fn (OutputInterface $stderr, JsonStream $stream): int => $this->deploy($input, $stderr, new SymfonyStyle($input, new NullOutput()), $stream));
    }

    protected function jsonChanged(): bool
    {
        return $this->tracked?->changed() === true;
    }

    protected function jsonRolledBack(): bool
    {
        return $this->tracked?->rolledBack() === true;
    }

    protected function jsonSucceeded(): string
    {
        return 'deployed';
    }

    /**
     * The deploy. With --json, $stream gets the steps and the result, $io writes nowhere and $output
     * (deploy command output) is stderr.
     */
    private function deploy(InputInterface $input, OutputInterface $output, SymfonyStyle $io, ?JsonStream $stream): int
    {
        $steps = $stream ?? new StepPrinter($io, $this->t(...), $input->isInteractive() && $output->isDecorated());
        /** @var list<string> $ran deploy commands that ran, for the messages of a later failure */
        $ran = [];
        $config = null;
        try {
            $config = $this->loadTarget($input, $io, $stream === null ? null : $stream->warning(...));
            $this->jsonTarget($stream, $config);
            $preset = $this->presets->detect($this->projectDir());

            $migrate = (bool) $input->getOption('migrate');
            $skipCommands = (bool) $input->getOption('no-deploy-commands');
            if ($migrate && $skipCommands) {
                throw $this->userError('deploy.migrate_conflict');
            }
            if ($migrate && $preset->name() !== 'laravel') {
                throw $this->userError('deploy.migrate_laravel_only');
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
                    $stream?->warning($key, $params);
                }
            }

            // Before the client setup, so the note shows even when the profile or STS fails.
            if (($endpoint = Endpoint::override()) !== null) {
                $io->writeln($this->t('deploy.endpoint_override', ['endpoint' => $endpoint]));
                $stream?->note('deploy.endpoint_override', ['endpoint' => $endpoint]);
            } elseif (($raw = Endpoint::unrecognised()) !== null) {
                $io->warning($this->t('deploy.endpoint_unrecognised', ['value' => $raw]));
                $stream?->warning('deploy.endpoint_unrecognised', ['value' => $raw]);
            }

            // Before any AWS call: dns('cloudflare') without a token fails here, and never falls back to Route 53.
            $cloudflare = $this->cloudflareDns($config, $input);

            $aws = $this->awsClients($config, $input, $io);

            $params = [
                'app' => $config->app,
                'environment' => $config->environment,
                'account' => $aws->accountId(),
                'profile' => $aws->profile ?? 'environment',
                'region' => (string) $config->region,
            ];
            $io->writeln($this->t('deploy.target', $params));
            $stream?->note('deploy.target', $params);

            $dns = $config->domains === [] || $cloudflare !== null ? null : $this->dnsClients($config, $input);
            if ($dns !== null) {
                $params = ['account' => $this->checkDnsCredentials($dns), 'profile' => (string) $dns->profile];
                $io->writeln($this->t('deploy.dns_target', $params));
                $stream?->note('deploy.dns_target', $params);
            }
            if ($cloudflare !== null) {
                // Token, permissions and zones, before anything in AWS changes.
                $params = ['zones' => implode(', ', $cloudflare->verify($config->domains))];
                $io->writeln($this->t('deploy.dns_cloudflare', $params));
                $stream?->note('deploy.dns_cloudflare', $params);
            }

            $deployer = ($this->deployerFactory)($aws, $dns, (bool) $input->getOption('force-dns'), (bool) $input->getOption('force'), $cloudflare);
            if ($stream !== null) {
                $deployer = $this->tracked = new ChangeTrackingDeployer($deployer);
            }
            if ($deployer->describe($config) === null && ! $input->getOption('yes')) {
                if (! $input->isInteractive()) {
                    throw $this->userError('deploy.confirm_required');
                }
                if (! $io->confirm($this->t('deploy.confirm_create', ['function' => $config->functionName()]), false)) {
                    $io->writeln($this->t('common.aborted'));
                    return self::FAILURE;
                }
            }

            $variables = DeployPipeline::variables($config, $preset);
            ['resolved' => $resolved, 'missing' => $missing] = (new SecretResolver($aws->ssm(), $aws->secretsManager()))->resolve($variables);
            $stream?->redact(...array_values($resolved));
            if ($missing !== []) {
                throw $this->userError('deploy.secrets_missing', ['references' => implode(', ', $missing)]);
            }

            $verifier = ($this->verifierFactory)($aws);
            $plan = $commands === [] ? null : new CommandPlan($commands, ($this->consoleFactory)($aws, $config), function (string $command, CommandResult $result) use ($output, $steps, $stream, &$ran): void {
                $ran[] = $command;
                $steps->finish();
                $output->write($stream === null ? $result->output : $stream->scrubbed($result->output), false, OutputInterface::OUTPUT_RAW);
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
                $failed = $this->t('deploy.verify_failed', [
                    'status' => $v->httpStatus ?? ($v->httpError ?? '-'),
                    'init' => $v->initStatus === null ? '-' : $v->initStatus . ($v->initErrorType !== null ? " ($v->initErrorType)" : ''),
                ]);
                $io->error($failed);
                $stream?->result('failed', $config->app, $config->environment, $outcome->result->revisionId, $outcome->result->url(),
                    $outcome->result->domains, $outcome->rolledBack, $v->initDurationMs, 'deploy.verify_failed', $failed);
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
                // With domain() only CloudFront may invoke the endpoint: its URL is shown as such, the domains follow.
                'url' => $outcome->result->url() . (RevisionFactory::protectsEndpoint($config) ? ' ' . $this->t('deploy.endpoint_cloudfront_only') : ''),
                'revision' => $outcome->result->revisionId,
                'cold_start' => $outcome->verification->initDurationMs === null
                    ? $this->t('deploy.cold_start_unknown')
                    : $this->t('deploy.cold_start', ['ms' => $outcome->verification->initDurationMs]),
            ]);
            if ($outcome->result->domains !== []) {
                $success .= "\n" . $this->t('deploy.success_domains', ['urls' => implode("\n", array_map(fn (string $d) => "https://$d", $outcome->result->domains))]);
            }
            $io->success($success);
            $stream?->result('deployed', $config->app, $config->environment, $outcome->result->revisionId, $outcome->result->url(),
                $outcome->result->domains, false, $outcome->verification->initDurationMs);

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
                $unknown = $this->t('deploy.command_unknown', [
                    'command' => $e->command,
                    'error' => $previous === null ? '' : ($this->describeError($previous) ?? $previous->getMessage()),
                ]);
                $io->error($unknown);
                if ($stream !== null) {
                    $this->jsonFailure($stream, $input, $e, 'deploy.command_unknown', $unknown);
                }
                $io->writeln($this->t('deploy.command_traffic_unchanged'));
                $this->commandsNotUndone($io, $e->ran, $config, 'deploy.commands_may_have_run');
                return self::FAILURE;
            }
            $key = $result->crashed() ? 'deploy.command_crashed' : 'deploy.command_failed';
            $message = $result->crashed()
                ? $this->t($key, ['command' => $e->command, 'type' => (string) $result->errorType, 'message' => (string) $result->errorMessage])
                : $this->t($key, ['command' => $e->command, 'code' => $result->exitCode]);
            $io->error($message);
            if ($stream !== null) {
                $this->jsonFailure($stream, $input, $e, $key, $message);
                // The command's log tail is a diagnostic: stderr with --json.
                foreach ($result->logTail as $line) {
                    $output->writeln($stream->scrubbed('  ' . $line), OutputInterface::OUTPUT_RAW);
                }
            }
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
                if ($stream !== null) {
                    $this->jsonFailure($stream, $input, $e, $this->describeErrorKey($e), $message);
                }
            }
            if ($config !== null) {
                $this->commandsNotUndone($io, $ran, $config);
            }
            return $message === null ? throw $e : self::FAILURE;
        }
    }

    /**
     * The Cloudflare provider of a dns('cloudflare') environment with domains; null otherwise.
     *
     * @throws DeployException cloudflare.token_missing
     */
    private function cloudflareDns(AppConfig $config, InputInterface $input): ?CloudflareDns
    {
        if (! $config->dnsCloudflare || $config->domains === []) {
            return null;
        }
        $profile = $input->getOption('dns-profile');
        if (is_string($profile) && $profile !== '') {
            throw $this->userError('cloudflare.dns_profile_conflict');
        }
        return new CloudflareDns($this->cloudflareApi($input), (bool) $input->getOption('force-dns'));
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
