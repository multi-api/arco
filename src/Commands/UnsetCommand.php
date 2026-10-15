<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'unset', description: 'Remove a setting from arco.php so the inherited or default value applies')]
final class UnsetCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsSettings;

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'concurrency, max-instances, rate-limit, timeout or log-level');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $method = $this->method($input);
            $key = $this->argument($input, 'key');
            [$raw, $environment] = $this->target($input, $io);

            if (self::levelValue($raw, $environment, $method) === null) {
                $io->note($this->t('settings.not_set', ['key' => $key, 'where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            $this->writeSetting($environment, $method, null);
            $io->success($this->t('settings.removed', ['method' => $method, 'where' => $this->where($environment)]));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
