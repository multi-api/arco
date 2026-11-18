<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\TenantRestore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'tenants:restore', description: 'Enable the tenant domains function again after an arco remove that stopped (deletes its reserved concurrency of 0)')]
final class TenantsRestoreCommand extends ArcoCommand
{
    use ResolvesTarget;

    /** @param (Closure(?string, string): AwsClients)|null $awsFactory */
    public function __construct(?Closure $awsFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print one JSON document (schema 1) on stdout (never asks: implies --no-interaction)')
            ->addOption(ConfigLayer::OPTION, null, InputOption::VALUE_REQUIRED, ConfigLayer::DESCRIPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        if ($json) {
            $input->setInteractive(false);
        }
        // With --json stdout carries the document only: config warnings and notes go nowhere.
        $io = new SymfonyStyle($input, $json ? new NullOutput() : $output);
        $config = null;
        try {
            $config = $this->loadTarget($input, $io);
            $aws = $this->awsClients($config, $input, $io);
            $result = (new TenantRestore($aws))->restore($config);
        } catch (Throwable $e) {
            $message = $this->describeError($e) ?? throw $e;
            if ($json) {
                $output->writeln((string) json_encode([
                    'schema' => 1,
                    'outcome' => self::refusal($e) ? 'refused' : 'failed',
                    'app' => $config?->app,
                    'environment' => $config?->environment,
                    'function' => $config?->tenantMediatorFunctionName(),
                    'error' => ['key' => $this->describeErrorKey($e), 'message' => $message]
                        + (($e instanceof UserError || $e instanceof DeployException) && $e->params !== [] ? ['params' => $e->params] : []),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $io->error($message);
            }
            return self::FAILURE;
        }

        $params = ['function' => $config->tenantMediatorFunctionName(), 'environment' => $config->environment];
        if ($json) {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'outcome' => $result['outcome'],
                'app' => $config->app,
                'environment' => $config->environment,
                'function' => $params['function'],
                'reservedConcurrency' => $result['reserved'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        match ($result['outcome']) {
            TenantRestore::RESTORED => $io->success($this->t('tenants.restored', $params)),
            TenantRestore::ALREADY_ENABLED => $io->note($this->t('tenants.already_enabled', $params)),
            TenantRestore::NOTHING_TO_RESTORE => $io->note($this->t('tenants.nothing_to_restore', $params)),
            default => $io->note($this->t('tenants.limit_kept', $params + ['limit' => (int) $result['reserved']])),
        };
        return self::SUCCESS;
    }

    /**
     * "refused": the run stopped on a check before AWS changed anything (config, credentials, ownership, a missing
     * function, a refused read). "failed": the delete was sent and its read back failed, or AWS itself failed.
     */
    private static function refusal(Throwable $e): bool
    {
        if ($e instanceof AwsException) {
            return false;
        }
        return ! ($e instanceof DeployException && $e->key === 'tenants.restore_unconfirmed');
    }
}
