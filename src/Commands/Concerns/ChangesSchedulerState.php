<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\OwnershipGuard;
use Multiapi\Arco\Deploy\ProcessSchedules;
use Multiapi\Arco\Deploy\SchedulerRule;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Shared by `arco scheduler:pause` and `arco scheduler:resume`: disables or enables the environment's
 * EventBridge rule in AWS at once, without editing arco.php (deploys keep the state, see SchedulerRule::put()).
 * In a process project, every schedule rule of the environment (found by tag, ProcessSchedules) instead.
 */
trait ChangesSchedulerState
{
    use ResolvesTarget;

    private function addSchedulerStateOptions(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption(ConfigLayer::OPTION, null, InputOption::VALUE_REQUIRED, ConfigLayer::DESCRIPTION);
    }

    private function changeSchedulerState(InputInterface $input, OutputInterface $output, bool $pause): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $config = $this->loadTarget($input, $io);
            $aws = $this->awsClients($config, $input, $io);
            if ($config->isProcess()) {
                return $this->changeScheduleRules($config, $aws, $io, $pause);
            }
            $rule = new SchedulerRule($aws->eventBridge(), $aws->lambda());
            $params = ['environment' => $config->environment, 'rule' => SchedulerRule::name($config)];
            $state = $rule->state($config);
            if ($state === null) {
                throw new UserError($this->t('scheduler.no_rule', $params));
            }
            // Before EventBridge changes it: a rule another app or environment owns is left alone.
            $guard = new OwnershipGuard($aws);
            $owned = $guard->schedulerRule(SchedulerRule::name($config));
            $guard->checkUse($config, $owned === null ? [] : [$owned]);
            if (($state === SchedulerRule::DISABLED) === $pause) {
                $io->note($this->t($pause ? 'scheduler.already_paused' : 'scheduler.already_active', $params));
                return self::SUCCESS;
            }
            $pause ? $rule->pause($config) : $rule->resume($config);
            $io->success($this->t($pause ? 'scheduler.paused_now' : 'scheduler.resumed_now', $params));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /**
     * A process app: disables or enables every schedule rule tagged for the environment (tags are the
     * ownership check: only this app and environment's rules are listed), one line per schedule.
     */
    private function changeScheduleRules(AppConfig $config, AwsClients $aws, SymfonyStyle $io, bool $pause): int
    {
        $schedules = new ProcessSchedules($aws->eventBridge(), $aws->lambda(), $aws->taggingApi());
        $params = ['environment' => $config->environment];
        $rules = $pause ? $schedules->pauseAll($config) : $schedules->resumeAll($config);
        if ($rules === []) {
            throw new UserError($this->t('scheduler.no_schedule_rules', $params));
        }
        foreach ($rules as $rule) {
            $key = match (true) {
                ! $rule['changed'] => $pause ? 'scheduler.schedule_already_paused' : 'scheduler.schedule_already_active',
                default => $pause ? 'scheduler.schedule_paused_now' : 'scheduler.schedule_resumed_now',
            };
            $io->writeln(' • ' . $this->t($key, ['schedule' => $rule['schedule'], 'rule' => $rule['rule']]));
        }
        $io->success($this->t($pause ? 'scheduler.schedules_paused_now' : 'scheduler.schedules_resumed_now', $params));
        return self::SUCCESS;
    }
}
