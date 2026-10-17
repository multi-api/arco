<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsTables;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'queue:failed-table', description: 'Turn the DynamoDB table for failed jobs on or off in arco.php')]
final class QueueFailedTableCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsTables;

    protected function configure(): void
    {
        $this->addOption('existing', null, InputOption::VALUE_REQUIRED, 'Name of a DynamoDB table Arco does not manage')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Turn the table off (it stays in AWS)');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            if (! $input->getOption('remove')) {
                return $this->addTable($input, $io, 'failedJobsTable');
            }
            if (! in_array($input->getOption('existing'), [null, ''], true)) {
                throw new UserError($this->t('table.existing_with_remove'));
            }
            return $this->removeTable($input, $io, 'failedJobsTable');
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
