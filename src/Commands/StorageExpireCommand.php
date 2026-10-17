<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsStorage;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'storage:expire', description: 'Delete the files under a prefix of a storage after some days')]
final class StorageExpireCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsStorage;

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Storage name')
            ->addArgument('prefix', InputArgument::REQUIRED, 'Key prefix, ending in / (for example tmp/)')
            ->addArgument('days', InputArgument::OPTIONAL, 'Days before a file under the prefix is deleted')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the expiry rule of the prefix');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $prefix = $this->argument($input, 'prefix');
            $days = $this->argument($input, 'days');
            if ($input->getOption('remove')) {
                if ($days !== '') {
                    throw new UserError($this->t('storage.days_with_remove'));
                }
                return $this->changeStorageOption($input, $io, 'expire', $prefix, static function (array $expire) use ($prefix): ?array {
                    if (! array_key_exists($prefix, $expire)) {
                        return null;
                    }
                    unset($expire[$prefix]);
                    return $expire;
                });
            }
            if ($days === '') {
                throw new UserError($this->t('storage.days_required', ['storage' => $this->argument($input, 'name'), 'prefix' => $prefix]));
            }
            if (preg_match('/^-?\d{1,9}$/', $days) !== 1) {
                throw new UserError($this->t('storage.not_a_number', ['value' => $days]));
            }
            return $this->changeStorageOption($input, $io, 'expire', $prefix, static fn (array $expire): array => [...$expire, $prefix => (int) $days]);
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
