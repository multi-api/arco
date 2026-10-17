<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'scheduler:remove', description: 'Turn the Laravel scheduler off (the console function stays as it is)')]
final class SchedulerRemoveCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsSettings;

    protected function configure(): void
    {
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            [$raw, $environment] = $this->target($input, $io);
            $from = $environment === null ? self::levelValue($raw, null, 'scheduler') : ConfigResolver::resolve($raw, $environment)->scheduler;
            if (! is_int($from)) {
                $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            // Spec §5: the scheduler goes off, the console stays. Make it explicit where it is unset, or
            // the next deploy would find a console with no setting (deploy.console_setting_missing).
            $writeConsole = self::consoleUnset($raw, $environment);
            if ($writeConsole) {
                $this->writeSetting($environment, 'console', true);
            }
            $this->writeSetting($environment, 'scheduler', false);
            $io->success($this->t('scheduler.changed', [
                'environment' => $this->levelLabel($environment),
                'from' => $this->t('scheduler.every', ['minutes' => $from]),
                'to' => $this->t('scheduler.off'),
            ]));
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            if ($writeConsole) {
                $io->writeln('    ' . ArcoFileEditor::settingLine('console', true));
            }
            $io->writeln('    ' . ArcoFileEditor::settingLine('scheduler', false));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
