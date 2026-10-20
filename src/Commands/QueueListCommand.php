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
use Symfony\Component\Console\Output\NullOutput;
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
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        if ($json) {
            // No profile prompt or "profile saved" text in the JSON stream: it needs a profile that resolves.
            $input->setInteractive(false);
        }
        try {
            // Notes and warnings would corrupt machine-readable output, so --json sends them nowhere.
            $config = $this->loadTarget($input, $json ? new SymfonyStyle($input, new NullOutput()) : $io);
            if ($config->queues === [] && $json) {
                $this->printJson($output, $config, []);
                return self::SUCCESS;
            }
            if ($config->queues === []) {
                $io->note($this->t('queue.none_configured', ['environment' => $config->environment]));
                return self::SUCCESS;
            }
            $aws = $this->awsClients($config, $input, $io);
            $located = $config->account === null ? $config->withAccount($aws->accountId()) : $config;
            $queues = new Queues($aws->sqs(), $aws->lambda());
            $workers = new LambdaFunction($aws->lambda(), new Sleeper(), 'deploy.worker_missing', 'deploy.worker_update_failed');
            if ($json) {
                $this->printJson($output, $config, array_map(fn (QueueConfig $queue) => $this->jsonQueue($queues, $workers, $config, $located, $queue), $config->queues));
                return self::SUCCESS;
            }
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
                        'instances' => $queue->maxInstances ?? $this->t('queue.no_limit'),
                        'tries' => $queue->tries,
                    ])],
                );
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            $message = $this->describeError($e) ?? throw $e;
            if ($json) {
                $output->writeln((string) json_encode(['schema' => 1, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $io->error($message);
            }
            return self::FAILURE;
        }
    }

    /** @param list<array<string, mixed>> $queues */
    private function printJson(OutputInterface $output, AppConfig $config, array $queues): void
    {
        $output->writeln((string) json_encode(['schema' => 1, 'environment' => $config->environment, 'queues' => $queues], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * `state` is ok, not_created, denied or unreadable (with `error`, the AWS error code).
     *
     * @return array<string, mixed>
     */
    private function jsonQueue(Queues $queues, LambdaFunction $workers, AppConfig $config, AppConfig $located, QueueConfig $queue): array
    {
        $url = $queue->url($located);
        $messages = ['state' => 'ok', 'visible' => null, 'inFlight' => null];
        try {
            $counts = $queues->counts($url, ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible']);
            $messages['visible'] = $counts['ApproximateNumberOfMessages'];
            $messages['inFlight'] = $counts['ApproximateNumberOfMessagesNotVisible'];
        } catch (AwsException $e) {
            $messages = ['state' => Queues::missing($e) ? 'not_created' : 'unreadable', 'visible' => null, 'inFlight' => null]
                + (Queues::missing($e) ? [] : ['error' => (string) ($e->getAwsErrorCode() ?? 'UnknownError')]);
        }

        $dlq = null;
        $dlqName = $queue->dlqName($located);
        $dlqUrl = $queue->dlqUrl($located);
        if ($dlqName !== null && $dlqUrl !== null) {
            $dlq = ['name' => $dlqName, 'state' => 'ok', 'messages' => null];
            try {
                $dlq['messages'] = $queues->counts($dlqUrl, ['ApproximateNumberOfMessages'])['ApproximateNumberOfMessages'];
            } catch (AwsException $e) {
                $dlq['state'] = Queues::missing($e) ? 'not_created' : 'unreadable';
                if (! Queues::missing($e)) {
                    $dlq['error'] = (string) ($e->getAwsErrorCode() ?? 'UnknownError');
                }
            }
        }

        $function = $config->workerFunctionName($queue->name);
        $worker = ['function' => $function, 'state' => 'ok', 'version' => null];
        try {
            $version = $workers->liveVersion($function);
            $worker['version'] = $version;
            $worker['state'] = $version === null ? 'not_created' : 'ok';
        } catch (AwsException $e) {
            $code = (string) ($e->getAwsErrorCode() ?? 'UnknownError');
            $worker['state'] = str_contains($code, 'AccessDenied') ? 'denied' : 'unreadable';
            if ($worker['state'] === 'unreadable') {
                $worker['error'] = $code;
            }
        }

        return [
            'name' => $queue->name,
            'url' => $url,
            'existing' => $queue->existing !== null,
            'messages' => $messages,
            'dlq' => $dlq,
            'worker' => $worker,
            'settings' => ['timeout' => $queue->timeout, 'memory' => $queue->memory, 'batchSize' => $queue->batchSize, 'maxInstances' => $queue->maxInstances, 'tries' => $queue->tries],
        ];
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
