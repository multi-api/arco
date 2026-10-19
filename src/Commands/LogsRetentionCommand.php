<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Validator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'logs:retention', description: 'Set how many days CloudWatch keeps the logs (or never expire them)')]
final class LogsRetentionCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsSettings;

    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Days to keep the logs, or "never"')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove logRetention() so the default applies');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $remove = (bool) $input->getOption('remove');
            $given = $this->argument($input, 'days');
            if ($remove === ($given !== '')) {
                throw new UserError($this->t('logs.retention_usage'));
            }
            $value = null;
            if (! $remove) {
                $value = $given === 'never' ? false : self::intOrText($given);
                if ($value !== false) {
                    $error = Validator::settingError('logRetention', $value);
                    if ($error !== null) {
                        throw new UserError($this->t($error['key'], $error['params']));
                    }
                }
            }
            [$raw, $environment] = $this->target($input, $io);
            $from = $environment === null ? self::levelValue($raw, null, 'logRetention') : ConfigResolver::resolve($raw, $environment)->logRetention;
            if ($remove && self::levelValue($raw, $environment, 'logRetention') === null) {
                $io->note($this->t('settings.not_set', ['key' => 'logRetention', 'where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            if (! $remove && $from === $value && self::levelValue($raw, $environment, 'logRetention') === $value) {
                $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            $this->writeSetting($environment, 'logRetention', $value);
            $io->success($this->t('logs.retention_changed', [
                'environment' => $this->levelLabel($environment),
                'from' => $this->describe($from),
                'to' => $remove ? $this->t('logs.retention_default') : $this->describe($value),
            ]));
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            $io->writeln('    ' . ($value === null ? $this->t('settings.remove_call', ['method' => 'logRetention']) : ArcoFileEditor::settingLine('logRetention', $value)));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    private function describe(mixed $retention): string
    {
        return match (true) {
            $retention === false => $this->t('logs.retention_never'),
            $retention === 1 => $this->t('logs.retention_one_day'),
            is_int($retention) => $this->t('logs.retention_days', ['days' => $retention]),
            default => $this->t('logs.retention_default'),
        };
    }
}
