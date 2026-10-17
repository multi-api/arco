<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\EditsQueues;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
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
            return $this->removeNamed($input, $io);
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
