<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'vpc:remove', description: 'Take the functions out of the VPC: remove vpc() from arco.php')]
final class VpcRemoveCommand extends ArcoCommand
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
            $own = self::levelValue($raw, $environment, 'vpc');
            // An environment that inherits the app's vpc() leaves it only with ->vpc(false).
            $inherited = $environment !== null && is_array(self::levelValue($raw, null, 'vpc'));
            if ($inherited ? $own === false : $own === null) {
                $io->note($this->t('vpc.unchanged', ['where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            $change = $inherited ? false : null;
            $this->writeOrExplain(
                $environment,
                $change === false ? ArcoFileEditor::vpcLine(false) : $this->t('settings.remove_call', ['method' => 'vpc']),
                fn (): bool => ArcoFileEditor::setVpc($this->arcoPhp(), $environment, $change),
            );
            $io->success($this->t('vpc.removed', ['where' => $this->where($environment)]));
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            $io->writeln('    ' . ($change === false ? ArcoFileEditor::vpcLine(false) : $this->t('settings.remove_call', ['method' => 'vpc'])));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
