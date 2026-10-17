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

#[AsCommand(name: 'storage:public', description: 'Let anyone read the files under a prefix of a storage')]
final class StoragePublicCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsStorage;

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Storage name')
            ->addArgument('prefix', InputArgument::REQUIRED, 'Key prefix, ending in / (for example avatars/)')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Make the prefix private again');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $prefix = $this->argument($input, 'prefix');
            $remove = (bool) $input->getOption('remove');
            return $this->changeStorageOption($input, $io, 'public', $prefix, static function (array $public) use ($prefix, $remove): ?array {
                if (! $remove) {
                    return in_array($prefix, $public, true) ? $public : [...$public, $prefix];
                }
                return in_array($prefix, $public, true) ? array_values(array_diff($public, [$prefix])) : null;
            });
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
