<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Console\Console;
use Multiapi\Arco\Console\LambdaConsole;
use Multiapi\Arco\Logs\LogText;
use Multiapi\Arco\Tinker\RemoteShell;
use Multiapi\Arco\Tinker\TinkerReply;
use Multiapi\Arco\Tinker\TinkerSession;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'tinker', description: 'Open a tinker shell whose statements run on the console function')]
final class TinkerCommand extends ArcoCommand
{
    use ResolvesTarget;

    /** @var Closure(AwsClients, AppConfig): Console */
    private Closure $consoleFactory;
    private PresetRegistry $presets;
    /** @var Closure(TinkerSession, Closure(TinkerReply): void): int */
    private Closure $shell;

    public function __construct(?Closure $awsFactory = null, ?Closure $consoleFactory = null, ?PresetRegistry $presets = null, ?Closure $shell = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->consoleFactory = $consoleFactory ?? fn (AwsClients $aws, AppConfig $config) => new LambdaConsole($aws->lambda(LambdaConsole::clientOptions($config)));
        $this->presets = $presets ?? PresetRegistry::default();
        $this->shell = $shell ?? static fn (TinkerSession $session, Closure $render): int => RemoteShell::start($session, $render);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('execute', null, InputOption::VALUE_REQUIRED, 'Run one statement and exit (exit code 1 on an exception)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Do not ask for confirmation on protected environments');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $config = $this->loadTarget($input, $io);
            if (! $config->consoleEnabled()) {
                throw new UserError($this->t('console.disabled', ['environment' => $config->environment]));
            }
            if ($this->presets->detect($this->projectDir())->name() !== 'laravel') {
                throw new UserError($this->t('tinker.laravel_only'));
            }
            $code = $input->getOption('execute');
            if (! is_string($code) && ! class_exists(\Psy\Shell::class)) {
                throw new UserError($this->t('tinker.psysh_missing'));
            }
            $aws = $this->awsClients($config, $input, $io, checkCredentials: false);
            if ($config->isProtected() && ! $input->getOption('force')) {
                $params = ['environment' => $config->environment, 'account' => $aws->accountId(), 'function' => $config->consoleFunctionName()];
                if (! $input->isInteractive()) {
                    throw new UserError($this->t('tinker.force_required', $params));
                }
                if (! $io->confirm($this->t('tinker.confirm_protected', $params), false)) {
                    $io->writeln($this->t('common.aborted'));
                    return self::FAILURE;
                }
            }

            $session = new TinkerSession(($this->consoleFactory)($aws, $config), $config);
            $render = function (TinkerReply $reply) use ($output): void {
                $this->render($reply, $output);
            };
            if (is_string($code)) {
                $reply = $session->execute($code);
                $render($reply);
                return $reply->exception === null ? self::SUCCESS : self::FAILURE;
            }
            $io->writeln($this->t('tinker.connected', ['function' => $config->consoleFunctionName(), 'environment' => $config->environment]));
            return ($this->shell)($session, $render);
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    private function render(TinkerReply $reply, OutputInterface $output): void
    {
        if ($reply->extra !== '') {
            $output->writeln($reply->extra, OutputInterface::OUTPUT_RAW);
        }
        // Server-side truncation markers ("[truncated]\n") are part of the text and shown as they are.
        $this->writeText($reply->output, $output);
        if ($reply->presented !== null) {
            $this->writeText('= ' . $reply->presented, $output);
        }
        if ($reply->exception !== null) {
            if ($reply->exception['class'] === TinkerReply::NO_REPLY) {
                // No result line (e.g. the scope could not be restored): the raw output and exit code.
                $this->writeText($reply->exception['message'], $output);
                $output->writeln('<error>' . OutputFormatter::escape($this->t('tinker.no_reply_exit', ['code' => $reply->exitCode])) . '</error>');
            } else {
                $output->writeln('<error>' . OutputFormatter::escape($reply->exception['class'] . ': ' . $reply->exception['message']) . '</error>');
                // A crash (timeout, out of memory): the end of the function log, as arco command shows it.
                foreach ($reply->logTail as $line) {
                    $output->writeln('  ' . LogText::escape($line));
                }
            }
            $hint = match ($reply->exception['class']) {
                TinkerReply::NO_REPLY => $this->t('tinker.no_reply'),
                'Arco.TinkerMissing' => $this->t('tinker.remote_tinker_missing'),
                default => null,
            };
            if ($hint !== null) {
                $output->writeln('<comment>' . OutputFormatter::escape($hint) . '</comment>');
            }
        }
        if ($reply->dropped !== []) {
            $names = implode(', ', array_map(fn (string $name) => '$' . $name, $reply->dropped));
            $output->writeln('<comment>' . OutputFormatter::escape($this->t('tinker.dropped', ['variables' => $names])) . '</comment>');
        }
    }

    /** Writes $text as is, ending it with a newline when it has none. */
    private function writeText(string $text, OutputInterface $output): void
    {
        if ($text === '') {
            return;
        }
        $output->write($text, false, OutputInterface::OUTPUT_RAW);
        if (! str_ends_with($text, "\n")) {
            $output->writeln('');
        }
    }
}
