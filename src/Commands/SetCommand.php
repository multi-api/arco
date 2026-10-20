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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'set', description: 'Set concurrency, max-instances, rate-limit, timeout, log-level, console-timeout or console-memory in arco.php')]
final class SetCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsSettings;

    private const STRING_KEYS = ['logLevel'];

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'concurrency, max-instances, rate-limit, timeout, log-level, console-timeout or console-memory')
            ->addArgument('value', InputArgument::REQUIRED, 'New value');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $method = $this->method($input);
            $value = self::parse($method, $this->argument($input, 'value'));
            $error = Validator::settingError($method, $value);
            if ($error !== null) {
                throw new UserError($this->t($error['key'], $error['params']));
            }
            [$raw, $environment] = $this->target($input, $io);

            $before = (string) @file_get_contents($this->arcoPhp());
            $unchanged = self::levelValue($raw, $environment, $method) === $value;
            if (! $unchanged) {
                $this->writeSetting($environment, $method, $value);
                $unchanged = (string) @file_get_contents($this->arcoPhp()) === $before;
            }
            $unchanged
                ? $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]))
                : $io->success($this->t('settings.set', ['key' => $this->argument($input, 'key'), 'value' => $value, 'where' => $this->where($environment)]));
            if ($environment === null) {
                foreach (ConfigResolver::environments($raw) as $env) {
                    if (self::levelValue($raw, $env, $method) !== null) {
                        $io->warning($this->t('settings.overridden', ['environment' => $env, 'method' => self::callLabel($method)]));
                    }
                }
            }
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            $io->writeln('    ' . ArcoFileEditor::settingLine($method, $value));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /** Integer settings take a plain integer; anything else is passed on as text for the validator to reject. */
    private static function parse(string $method, string $value): int|string
    {
        if (in_array($method, self::STRING_KEYS, true)) {
            return $value;
        }
        return self::intOrText($value);
    }
}
