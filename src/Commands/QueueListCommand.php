<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Deploy\LambdaFunction;
use Multiapi\Arco\Deploy\Queues;
use Multiapi\Arco\Deploy\Sleeper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'queue:list', description: 'List the queues with their messages, dead-letter queues and workers in AWS')]
final class QueueListCommand extends ArcoCommand
{
    use ResolvesTarget;

    /** @param (Closure(?string, string): AwsClients)|null $awsFactory */
    public function __construct(?Closure $awsFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $config = $this->loadTarget($input, $io);
            if ($config->queues === []) {
                $io->note($this->t('queue.none_configured', ['environment' => $config->environment]));
                return self::SUCCESS;
            }
            $aws = $this->awsClients($config, $input, $io);
            $located = $config->account === null ? $config->withAccount($aws->accountId()) : $config;
            $queues = new Queues($aws->sqs(), $aws->lambda());
            $workers = new LambdaFunction($aws->lambda(), new Sleeper(), 'deploy.worker_missing', 'deploy.worker_update_failed');
            foreach ($config->queues as $queue) {
                $io->section($queue->name);
                $io->definitionList(
                    [$this->t('queue.list_url') => $queue->url($located)],
                    [$this->t('queue.list_kind') => $this->t($queue->existing === null ? 'queue.kind_created' : 'queue.kind_existing')],
                    [$this->t('queue.list_messages') => $this->messages($queues, $queue->url($located))],
                    [$this->t('queue.list_dlq') => $this->dlq($queues, $located, $queue)],
                    [$this->t('queue.list_worker') => $this->worker($workers, $config->workerFunctionName($queue->name))],
                    [$this->t('queue.list_settings') => $this->t('queue.settings', [
                        'timeout' => $queue->timeout,
                        'memory' => $queue->memory,
                        'batch' => $queue->batchSize,
                        'concurrency' => $queue->concurrency ?? $this->t('queue.no_limit'),
                        'tries' => $queue->tries,
                    ])],
                );
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    private function messages(Queues $queues, string $url): string
    {
        try {
            $counts = $queues->counts($url, ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible']);
        } catch (AwsException $e) {
            return Queues::missing($e) ? $this->t('queue.not_created') : $this->t('queue.unreadable', ['error' => (string) ($e->getAwsErrorCode() ?? 'UnknownError')]);
        }
        return $this->t('queue.messages', ['visible' => $counts['ApproximateNumberOfMessages'], 'in_flight' => $counts['ApproximateNumberOfMessagesNotVisible']]);
    }

    private function dlq(Queues $queues, AppConfig $located, QueueConfig $queue): string
    {
        $name = $queue->dlqName($located);
        $url = $queue->dlqUrl($located);
        if ($name === null || $url === null) {
            return $this->t('queue.none');
        }
        try {
            $count = $queues->counts($url, ['ApproximateNumberOfMessages'])['ApproximateNumberOfMessages'];
        } catch (AwsException $e) {
            return Queues::missing($e)
                ? $this->t('queue.dlq_not_created', ['name' => $name])
                : $this->t('queue.dlq_unreadable', ['name' => $name, 'error' => (string) ($e->getAwsErrorCode() ?? 'UnknownError')]);
        }
        return $this->t('queue.dlq_count', ['name' => $name, 'count' => $count]);
    }

    private function worker(LambdaFunction $workers, string $function): string
    {
        try {
            $version = $workers->liveVersion($function);
        } catch (AwsException $e) {
            $error = (string) ($e->getAwsErrorCode() ?? 'UnknownError');
            return str_contains($error, 'AccessDenied') ? $this->t('queue.worker_denied') : $this->t('queue.worker_unknown', ['error' => $error]);
        }
        return $version === null ? $this->t('queue.worker_missing') : $this->t('queue.worker_version', ['function' => $function, 'version' => $version]);
    }
}
