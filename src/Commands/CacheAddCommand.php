<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\EditsTables;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'cache:add', description: 'Turn the DynamoDB cache table on in arco.php')]
final class CacheAddCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsTables;

    protected function configure(): void
    {
        $this->addOption('existing', null, InputOption::VALUE_REQUIRED, 'Name of a DynamoDB table Arco does not manage');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            return $this->addTable($input, $io, 'cache');
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
