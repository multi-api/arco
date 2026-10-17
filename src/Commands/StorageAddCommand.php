<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\EditsStorage;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'storage:add', description: 'Add an S3 bucket to arco.php')]
final class StorageAddCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsStorage;

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Storage name (lowercase letters, digits and hyphens)')
            ->addOption('existing', null, InputOption::VALUE_REQUIRED, 'Name of an S3 bucket Arco does not manage')
            ->addOption('default', null, InputOption::VALUE_NONE, 'Make this the default disk (FILESYSTEM_DISK)');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            return $this->addNamed($input, $io, static function () use ($input): array {
                $options = [];
                $existing = $input->getOption('existing');
                if (is_string($existing) && $existing !== '') {
                    $options['existing'] = $existing;
                }
                if ($input->getOption('default')) {
                    $options['default'] = true;
                }
                return $options;
            });
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
