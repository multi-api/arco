<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'console:add', description: 'Turn the console function on in arco.php')]
final class ConsoleAddCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsSettings;

    protected function configure(): void
    {
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            [$raw, $environment] = $this->target($input, $io);
            $on = $environment === null
                ? self::levelValue($raw, null, 'console') === true
                : ConfigResolver::resolve($raw, $environment)->consoleEnabled();
            if ($on) {
                $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            $this->writeSetting($environment, 'console', true);
            $io->success($this->t('console.changed', ['environment' => $this->levelLabel($environment), 'from' => $this->t('console.off'), 'to' => $this->t('console.on')]));
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            $io->writeln('    ' . ArcoFileEditor::settingLine('console', true));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
