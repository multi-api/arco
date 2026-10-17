<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\StepPrinter;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\Deployer;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\SchedulerRule;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Shared by `arco scheduler:set` and `arco scheduler:remove`: after arco.php is edited, puts the
 * environment's scheduler rule in place (or deletes it) in AWS without a deploy, with --apply or when
 * the user says yes. Requires ResolvesTarget.
 */
trait AppliesScheduler
{
    /** @var Closure(AwsClients): Deployer */
    private Closure $deployerFactory;

    /**
     * @param (Closure(?string, string): AwsClients)|null $awsFactory
     * @param (Closure(AwsClients): Deployer)|null $deployerFactory
     */
    private function initApply(?Closure $awsFactory, ?Closure $deployerFactory): void
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->deployerFactory = $deployerFactory ?? fn (AwsClients $aws) => new AwsDeployer($aws);
    }

    private function addApplyOptions(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the change in AWS now, without a deploy')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use with --apply');
    }

    /** --apply, or a yes to the question when interactive. The app level names no environment to apply to. */
    private function wantsApply(InputInterface $input, SymfonyStyle $io, ?string $environment): bool
    {
        if ($environment === null) {
            if ($input->getOption('apply')) {
                $io->note($this->t('scheduler.apply_needs_env'));
            }
            return false;
        }
        if ($input->getOption('apply')) {
            return true;
        }
        return $input->isInteractive() && $io->confirm($this->t('scheduler.apply_ask'), false);
    }

    /**
     * Applies the environment's scheduler setting as arco.php now reads it: the rule on the console's
     * live alias (a paused rule stays paused), or no rule. Without a deployed console alias nothing in
     * AWS changes and the user is told to deploy. Success is only reported for a change that was made.
     *
     * @return bool false when the apply failed or could not be confirmed (the command then fails)
     */
    private function applyScheduler(InputInterface $input, SymfonyStyle $io): bool
    {
        $io->newLine();
        $config = $this->loadTarget($input, $io);
        $environment = $config->environment;
        try {
            $deployer = ($this->deployerFactory)($this->awsClients($config, $input, $io));
            $steps = new StepPrinter($io, $this->t(...), false);
            $seen = [];
            $onStep = function (string $key, array $params = []) use ($steps, &$seen): void {
                $seen[] = $key;
                /** @var array<string, string|int> $params */
                $steps($key, $params);
            };
            if ($config->scheduler === null) {
                $deployer->removeScheduler($config, $onStep);
                if (in_array('deploy.scheduler_unreadable', $seen, true)) {
                    $io->error($this->t('scheduler.apply_unconfirmed', ['environment' => $environment, 'rule' => SchedulerRule::name($config)]));
                    return false;
                }
                $io->success($this->t('scheduler.applied_off', ['environment' => $environment]));
                return true;
            }
            try {
                $deployer->syncScheduler($config, $onStep);
            } catch (DeployException $e) {
                // syncScheduler() looks the live alias up before any EventBridge call.
                if ($e->key !== 'deploy.console_missing') {
                    throw $e;
                }
                $io->warning($this->t('scheduler.apply_needs_deploy', ['environment' => $environment, 'function' => $config->consoleFunctionName()]));
                return true;
            }
        } catch (Throwable $e) {
            // arco.php was edited already: a deploy still applies it.
            $io->error($this->describeError($e) ?? throw $e);
            $io->writeln($this->t('domain.deploy_next', ['environment' => $environment]));
            return false;
        }
        $paused = in_array('deploy.scheduler_paused', $seen, true);
        $io->success($this->t($paused ? 'scheduler.applied_set_paused' : 'scheduler.applied_set', ['environment' => $environment, 'minutes' => $config->scheduler]));
        return true;
    }
}
