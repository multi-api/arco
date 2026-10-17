<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Deploy\SchedulerRule;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Shared by `arco scheduler:pause` and `arco scheduler:resume`: disables or enables the environment's
 * EventBridge rule in AWS at once, without editing arco.php (deploys keep the state, see SchedulerRule::put()).
 */
trait ChangesSchedulerState
{
    use ResolvesTarget;

    private function addSchedulerStateOptions(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use');
    }

    private function changeSchedulerState(InputInterface $input, OutputInterface $output, bool $pause): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $config = $this->loadTarget($input, $io);
            $aws = $this->awsClients($config, $input, $io);
            $rule = new SchedulerRule($aws->eventBridge(), $aws->lambda());
            $params = ['environment' => $config->environment, 'rule' => SchedulerRule::name($config)];
            $state = $rule->state($config);
            if ($state === null) {
                throw new UserError($this->t('scheduler.no_rule', $params));
            }
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
}
