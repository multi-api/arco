<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsQueues;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'queue:remove', description: 'Remove a queue from arco.php (the SQS queue and its messages stay in AWS)')]
final class QueueRemoveCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsQueues;

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Queue name');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $name = $this->queueName($input);
            [$raw, $environment] = $this->target($input, $io);
            $level = self::levelQueues($raw, $environment);
            $inherited = $environment !== null && is_array(self::levelQueues($raw, null)[$name] ?? null);
            if ($inherited) {
                // The app level defines it: only an environment-level false takes it out of this environment.
                if (($level[$name] ?? null) === false) {
                    $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
                    return self::SUCCESS;
                }
                $change = false;
            } elseif (is_array($level[$name] ?? null)) {
                $change = null;
            } else {
                throw new UserError($this->t('queue.not_found', ['queue' => $name, 'where' => $this->where($environment)]));
            }
            $this->assertValid(self::withQueue($raw, $environment, $name, $change), $environment);
            $this->writeQueue($environment, $name, $change);
            $io->success($this->t('queue.removed', ['queue' => $name, 'environment' => $this->levelLabel($environment)]));
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            $io->writeln('    ' . ($change === false ? ArcoFileEditor::queueLine($name, false) : $this->t('settings.remove_call', ['method' => 'queue'])));
            $io->writeln($this->t('queue.retained_note', ['queue' => $name]));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
