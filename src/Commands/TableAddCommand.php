<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\EditsGenericTables;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'table:add', description: 'Add a DynamoDB table (PK/SK, on demand) to arco.php')]
final class TableAddCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsGenericTables;

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Table name (lowercase letters, digits and hyphens; not cache or failed-jobs)')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Attribute holding each item\'s expiry (epoch seconds) for DynamoDB TTL')
            ->addOption('existing', null, InputOption::VALUE_REQUIRED, 'Name or ARN of a DynamoDB table Arco does not manage');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            return $this->addNamed($input, $io, static function () use ($input): array {
                $options = [];
                foreach (['existing', 'ttl'] as $option) {
                    $value = $input->getOption($option);
                    if (is_string($value) && $value !== '') {
                        $options[$option] = $value;
                    }
                }
                return $options;
            });
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
