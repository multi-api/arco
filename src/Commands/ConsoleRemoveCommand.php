<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'console:remove', description: 'Turn the console function off in arco.php')]
final class ConsoleRemoveCommand extends ArcoCommand
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
            $affected = $environment === null ? ConfigResolver::environments($raw) : [$environment];
            $enabled = false;
            foreach ($affected as $env) {
                $config = ConfigResolver::resolve($raw, $env);
                if ($config->scheduler !== null) {
                    throw new UserError($this->t('console.scheduler_needs_console', ['environment' => $env]));
                }
                $enabled = $enabled || $config->consoleEnabled();
            }
            if (! $enabled && ($environment !== null || self::levelValue($raw, null, 'console') === false)) {
                $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            $this->writeSetting($environment, 'console', false);
            $io->success($this->t('console.changed', ['environment' => $this->levelLabel($environment), 'from' => $this->t('console.on'), 'to' => $this->t('console.off')]));
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            $io->writeln('    ' . ArcoFileEditor::settingLine('console', false));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
