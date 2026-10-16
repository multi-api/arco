<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Console\Console;
use Multiapi\Arco\Console\LambdaConsole;
use Multiapi\Arco\ConsoleRuntime\ArgumentSplitter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'command', description: 'Run an artisan (or PHP) command on the console function')]
final class ConsoleCommand extends ArcoCommand
{
    use ResolvesTarget;

    /** @var Closure(AwsClients, AppConfig): Console */
    private Closure $consoleFactory;

    public function __construct(?Closure $awsFactory = null, ?Closure $consoleFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->consoleFactory = $consoleFactory ?? fn (AwsClients $aws, AppConfig $config) => new LambdaConsole($aws->lambda(LambdaConsole::clientOptions($config)));
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('args', InputArgument::REQUIRED, 'The command and its arguments as one quoted string, e.g. "migrate:status"')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Start the command and return at once; follow it with arco logs')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Time limit in seconds (up to the consoleTimeout)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $io->getErrorStyle();
        try {
            // Everything except the command's own output goes to stderr, so scripts can use stdout.
            $config = $this->loadTarget($input, $errors);
            $argument = $input->getArgument('args');
            $command = is_string($argument) ? $argument : '';
            if (trim($command) === '') {
                throw new UserError($this->t('command.empty'));
            }
            try {
                ArgumentSplitter::split($command);
            } catch (\InvalidArgumentException $e) {
                throw new UserError($this->t('command.invalid', ['reason' => $e->getMessage()]));
            }
            $timeout = $this->timeout($input->getOption('timeout'), $config);

            $console = ($this->consoleFactory)($this->awsClients($config, $input, $errors, checkCredentials: false), $config);
            if ($input->getOption('async')) {
                $console->start($config, $command, $timeout);
                // stderr: stdout carries only the command's own output, for scripts.
                $errors->writeln($this->t('command.started', ['command' => $command, 'function' => $config->consoleFunctionName(), 'environment' => $config->environment]));
                return self::SUCCESS;
            }

            $result = $console->run($config, $command, $timeout);
            if ($result->crashed()) {
                $errors->error($this->t('command.crashed', ['type' => (string) $result->errorType, 'message' => (string) $result->errorMessage]));
                foreach ($result->logTail as $line) {
                    $errors->writeln('  ' . OutputFormatter::escape($line));
                }
                return self::FAILURE;
            }
            $output->write($result->output, false, OutputInterface::OUTPUT_RAW);
            return $result->exitCode;
        } catch (Throwable $e) {
            $errors->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    private function timeout(mixed $value, AppConfig $config): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1 || (int) $value > $config->consoleTimeout) {
            throw new UserError($this->t('command.invalid_timeout', ['value' => is_scalar($value) ? (string) $value : '', 'max' => $config->consoleTimeout]));
        }
        return (int) $value;
    }
}
