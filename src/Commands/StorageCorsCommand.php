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

#[AsCommand(name: 'storage:cors', description: 'Allow origins to call a storage from the browser (CORS)')]
final class StorageCorsCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsStorage;

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Storage name')
            ->addArgument('origins', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Origins such as https://example.com, or *')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove these origins');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $value = $input->getArgument('origins');
            $origins = array_values(array_unique(array_filter(is_array($value) ? $value : [], 'is_string')));
            $remove = (bool) $input->getOption('remove');
            return $this->changeStorageOption($input, $io, 'cors', implode(', ', $origins), static function (array $cors) use ($origins, $remove): ?array {
                if (! $remove) {
                    return [...$cors, ...array_values(array_diff($origins, $cors))];
                }
                return array_intersect($origins, $cors) === [] ? null : array_values(array_diff($cors, $origins));
            });
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
