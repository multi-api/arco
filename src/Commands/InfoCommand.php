<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Binary\BinaryException;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\Aws\LocalState;
use Multiapi\Arco\Aws\ProfileResolver;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\AppStatus;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\Deployer;
use Multiapi\Arco\Secrets\SecretReference;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'info', description: 'Show the resolved configuration and deployment status')]
final class InfoCommand extends ArcoCommand
{
    use ResolvesTarget;

    /** @var Closure(AwsClients): Deployer */
    private Closure $deployerFactory;

    public function __construct(?Closure $awsFactory = null, ?Closure $deployerFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->deployerFactory = $deployerFactory ?? fn (AwsClients $aws) => new AwsDeployer($aws);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            // Config warnings would corrupt machine-readable output, so --json suppresses them.
            $config = $this->loadTarget($input, $input->getOption('json') ? new SymfonyStyle($input, new NullOutput()) : $io);
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }

        [$status, $remoteError] = $this->remoteStatus($config);
        $settings = $this->settings($config);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'function' => $config->functionName(),
                'url' => $status?->domainName !== null ? 'https://' . $status->domainName : null,
                'revision' => $status?->activeRevisionId,
                'domains' => $config->domains,
                'distribution' => $status?->distributionDomain !== null ? ['domain' => $status->distributionDomain, 'status' => $status->distributionStatus] : null,
                'console' => $config->consoleEnabled()
                    ? ['function' => $config->consoleFunctionName(), 'version' => $status?->consoleVersion]
                        + ($status?->consoleVersionError !== null ? ['error' => $status->consoleVersionError] : [])
                        + ['enabled' => true]
                    : ['enabled' => false, 'function' => $config->consoleFunctionName()],
                'deployCommands' => $config->deployCommands,
                'settings' => $this->jsonSettings($settings, $status),
            ] + ($remoteError !== null ? ['error' => $remoteError] : []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $remoteError === null ? self::SUCCESS : self::FAILURE;
        }

        $io->title($config->functionName());
        $io->table(
            [$this->t('info.setting'), $this->t('info.value'), $this->t('info.source')],
            array_map(fn (string $key, array $row) => [$key, $this->display($key, $row[0], $status), $row[1]], array_keys($settings), $settings),
        );

        $io->section($this->t('info.variables'));
        $rows = [];
        foreach ($config->variables as $name => $value) {
            $rows[] = [$name, SecretReference::isReference($value) || ! preg_match('/(_KEY|_SECRET|_PASSWORD|_TOKEN)$/', $name) ? $value : '••••••'];
        }
        $io->table([$this->t('info.variable'), $this->t('info.value')], $rows);

        if ($remoteError !== null) {
            $io->error($remoteError);
            return self::FAILURE;
        }
        if ($status === null) {
            $io->note($this->t('info.no_remote'));
        } else {
            $io->definitionList(
                ['URL' => $status->domainName !== null ? 'https://' . $status->domainName : '-'],
                [$this->t('info.state') => $status->state],
                [$this->t('info.revision') => $status->activeRevisionId ?? '-'],
                [$this->t('info.console') => $config->consoleEnabled() ? $this->consoleStatus($status) : $this->t('info.console_off')],
                ...($status->distributionDomain !== null ? [[$this->t('info.distribution') => "{$status->distributionDomain} ({$status->distributionStatus})"]] : []),
            );
        }
        return self::SUCCESS;
    }

    private function consoleStatus(AppStatus $status): string
    {
        return match (true) {
            $status->consoleVersion !== null => $this->t('info.console_version', ['function' => (string) $status->consoleFunction, 'version' => $status->consoleVersion]),
            $status->consoleVersionError === null => $this->t('info.console_missing'),
            str_contains($status->consoleVersionError, 'AccessDenied') => $this->t('info.console_denied'),
            default => $this->t('info.console_unknown', ['error' => $status->consoleVersionError]),
        };
    }

    /**
     * Remote status only when a profile resolves without prompting, plus the error to show when
     * the credentials are missing or expired (anything else still reads as "not deployed").
     *
     * @return array{0: ?AppStatus, 1: ?string}
     */
    private function remoteStatus(AppConfig $config): array
    {
        /** @var array<string, string> $env */
        $env = getenv();
        $choice = ProfileResolver::resolve(null, $env, $config, new LocalState($this->projectDir()));
        if ($choice === null) {
            return [null, null];
        }
        try {
            return [($this->deployerFactory)(($this->awsFactory)($choice->profile, (string) $config->region))->describe($config), null];
        } catch (Throwable $e) {
            return [null, $this->credentialProblem($e, $choice->profile)];
        }
    }

    private function runtimeBuildLabel(AppConfig $config): ?string
    {
        if ($config->runtime->binary !== null) {
            return null;
        }
        try {
            $build = RuntimeManifest::load()->resolve($config->runtime->build, $config->runtime->frankenphp);
        } catch (BinaryException) {
            return null;
        }
        return "{$build->id} (FrankenPHP {$build->frankenphp}, PHP {$build->php})";
    }

    private function runtimeBuildSource(AppConfig $config): string
    {
        $s = $config->sources;
        return match (true) {
            $s['runtime.build'] !== 'default' => $s['runtime.build'],
            $s['runtime.frankenphp'] !== 'default' => $s['runtime.frankenphp'],
            default => 'default',
        };
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    private function settings(AppConfig $config): array
    {
        $s = $config->sources;
        return [
            'region' => [$config->region, 'app'],
            'account' => [$config->account, $s['account']],
            'profile' => [$config->profile, $s['profile']],
            'concurrency' => [$config->concurrency, $s['concurrency']],
            'timeout' => [$config->timeout, $s['timeout']],
            'maxInstances' => [$config->maxInstances, $s['maxInstances']],
            'rateLimit' => [$config->rateLimit, $s['rateLimit']],
            'auth' => [$config->auth, $s['auth']],
            'logLevel' => [$config->logLevel, $s['logLevel']],
            // Machine values, as arco.php writes them (--json); display() words them for the table.
            'console' => [$config->consoleEnabled() ? true : $config->console, $s['console']],
            'scheduler' => [$config->scheduler, $s['scheduler']],
            'logRetention' => [$config->logRetention, $s['logRetention']],
            'consoleTimeout' => [$config->consoleTimeout, $s['consoleTimeout']],
            'protected' => [$config->isProtected(), $s['protected']],
            'deployCommands' => [$config->deployCommands === [] ? null : implode('; ', $config->deployCommands), $s['deployCommands']],
            'permissions' => [count($config->permissions), $config->permissions === [] ? 'default' : 'app/environment'],
            'domains' => [$config->domains === [] ? null : implode(', ', $config->domains), $s['domains']],
            'dns' => [$config->domains === [] ? null : $this->dnsLabel($config), $s['dns']],
            'certificate' => [$config->certificate, $s['certificate']],
            'runtime.build' => [$this->runtimeBuildLabel($config), $this->runtimeBuildSource($config)],
            'runtime.binary' => [$config->runtime->binary, $s['runtime.binary']],
            'runtime.workers' => ["{$config->runtime->workersMin}-{$config->runtime->workersMax}", $s['runtime.workers']],
            'runtime.threads' => [$config->runtime->threads, $s['runtime.threads']],
            'runtime.mode' => [$config->runtime->mode, $s['runtime.mode']],
        ];
    }

    private function dnsLabel(AppConfig $config): string
    {
        return match (true) {
            $config->dnsManual => $this->t('info.dns_manual'),
            $config->dnsProfile !== null => $config->dnsProfile,
            default => $this->t('info.dns_deploy_account'),
        };
    }

    /**
     * The machine values, with schedulerPaused after scheduler: true/false from the rule, null when
     * there is no scheduler, no rule or it could not be read.
     *
     * @param array<string, array{0: mixed, 1: string}> $settings
     * @return array<string, mixed>
     */
    private function jsonSettings(array $settings, ?AppStatus $status): array
    {
        $json = [];
        foreach ($settings as $key => $row) {
            $json[$key] = $row[0];
            if ($key === 'scheduler') {
                $json['schedulerPaused'] = $row[0] === null ? null : $status?->schedulerPaused;
            }
        }
        return $json;
    }

    private function display(string $key, mixed $value, ?AppStatus $status = null): string
    {
        return match (true) {
            $key === 'scheduler' && is_int($value) && $status?->schedulerPaused === true => $this->t('scheduler.every_paused', ['minutes' => $value]),
            $key === 'console' => $this->t(match ($value) {
                true => 'console.on',
                false => 'console.off',
                default => 'info.console_not_set',
            }),
            $key === 'scheduler' => is_int($value) ? $this->t('scheduler.every', ['minutes' => $value]) : $this->t('scheduler.off'),
            $key === 'logRetention' => match (true) {
                $value === null => $this->t('info.retention_not_managed'),
                $value === false => $this->t('logs.retention_never'),
                $value === 1 => $this->t('logs.retention_one_day'),
                default => $this->t('logs.retention_days', ['days' => is_int($value) ? $value : 0]),
            },
            is_bool($value) => $this->t($value ? 'common.yes' : 'common.no'),
            is_scalar($value) => (string) $value,
            default => '-',
        };
    }
}
