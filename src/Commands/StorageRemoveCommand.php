<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\EditsStorage;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'storage:remove', description: 'Remove a storage from arco.php (the S3 bucket and its files stay in AWS)')]
final class StorageRemoveCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsStorage;

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Storage name');
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
