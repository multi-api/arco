<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Deploy\Queues;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'queue:dlq', description: 'Show the failed jobs in a queue\'s dead-letter queue, or move them back (--redrive)')]
final class QueueDlqCommand extends ArcoCommand
{
    use ResolvesTarget;

    public const SAMPLE = 10;
    public const BODY_CHARS = 500;

    /** @param (Closure(?string, string): AwsClients)|null $awsFactory */
    public function __construct(?Closure $awsFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Queue name, as in arco.php')
            ->addOption('redrive', null, InputOption::VALUE_NONE, 'Move the messages back to the queue so the worker runs them again')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $config = $this->loadTarget($input, $io);
            $nameArgument = $input->getArgument('name');
            $name = is_string($nameArgument) ? $nameArgument : '';
            $queue = null;
            foreach ($config->queues as $candidate) {
                if ($candidate->name === $name) {
                    $queue = $candidate;
                }
            }
            if ($queue === null) {
                $names = array_map(fn ($q) => $q->name, $config->queues);
                throw new UserError($this->t('queue.unknown', ['queue' => $name, 'queues' => $names === [] ? $this->t('queue.none') : implode(', ', $names)]));
            }
            if ($queue->dlqName($config) === null) {
                throw new UserError($this->t('queue.no_dlq', ['queue' => $queue->name]));
            }

            $aws = $this->awsClients($config, $input, $io);
            $located = $config->account === null ? $config->withAccount($aws->accountId()) : $config;
            $dlq = (string) $queue->dlqName($located);
            $dlqUrl = (string) $queue->dlqUrl($located);
            $queues = new Queues($aws->sqs(), $aws->lambda());
            try {
                $count = $queues->counts($dlqUrl, ['ApproximateNumberOfMessages'])['ApproximateNumberOfMessages'];
            } catch (AwsException $e) {
                throw Queues::missing($e) ? new UserError($this->t('queue.dlq_missing', ['dlq' => $dlq])) : $e;
            }

            if ($input->getOption('redrive')) {
                if ($count === 0) {
                    $io->note($this->t('queue.dlq_empty', ['dlq' => $dlq]));
                    return self::SUCCESS;
                }
                $task = $queues->redrive((string) $queue->dlqArn($located), $queue->arn($located));
                $io->success($this->t('queue.redrive_started', ['count' => $count, 'dlq' => $dlq, 'queue' => $queue->physicalName($located), 'task' => $task]));
                return self::SUCCESS;
            }

            $messages = $queues->peek($dlqUrl, self::SAMPLE);
            if ($count === 0 && $messages === []) {
                $io->success($this->t('queue.dlq_empty', ['dlq' => $dlq]));
                return self::SUCCESS;
            }
            $io->writeln($this->t('queue.dlq_summary', ['dlq' => $dlq, 'count' => $count]));
            if ($messages !== []) {
                $io->writeln($this->t('queue.dlq_sample', ['shown' => count($messages)]));
                foreach ($messages as $message) {
                    $io->newLine();
                    $io->writeln('<info>' . $this->t('queue.dlq_message', [
                        'id' => $message['id'],
                        'receives' => $message['receives'] ?? '-',
                        'sent' => $message['sent'] === null ? '-' : date('Y-m-d H:i:s', intdiv($message['sent'], 1000)),
                    ]) . '</info>');
                    $io->writeln(self::truncate($message['body']), OutputInterface::OUTPUT_RAW);
                }
                $io->newLine();
            }
            $io->writeln($this->t('queue.dlq_redrive_hint', ['queue' => $queue->name]));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /**
     * The body cut to BODY_CHARS, without C0/C1 control characters other than newline and tab: a
     * payload carries user input, and escape sequences must not reach the developer's terminal.
     */
    private static function truncate(string $body): string
    {
        $body = preg_replace('/[\x{0}-\x{8}\x{B}-\x{1F}\x{7F}-\x{9F}]/u', '', $body)
            // Not valid UTF-8: strip the bytes instead.
            ?? (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F-\x9F]/', '', $body);
        return mb_strlen($body) > self::BODY_CHARS ? mb_substr($body, 0, self::BODY_CHARS) . '…' : $body;
    }
}
