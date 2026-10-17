<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsQueues;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\QueueConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'queue:set', description: 'Change an option of a queue in arco.php')]
final class QueueSetCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsQueues;

    /** CLI option => queue() argument. */
    private const OPTIONS = ['timeout' => 'timeout', 'memory' => 'memory', 'concurrency' => 'concurrency', 'batch-size' => 'batchSize', 'tries' => 'tries', 'dlq' => 'dlq', 'default' => 'default'];
    private const FLAGS = ['dlq', 'default'];
    private const TRUE = ['true', 'on', 'yes', '1'];
    private const FALSE = ['false', 'off', 'no', '0'];

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Queue name')
            ->addArgument('option', InputArgument::REQUIRED, 'Option to change (' . implode(', ', array_keys(self::OPTIONS)) . ')')
            ->addArgument('value', InputArgument::REQUIRED, 'New value');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $name = $this->queueName($input);
            $option = $this->argument($input, 'option');
            if (in_array($option, ['fifo', 'existing'], true)) {
                throw new UserError($this->t('queue.option_fixed', ['option' => $option, 'queue' => $name]));
            }
            $key = self::OPTIONS[$option] ?? throw new UserError($this->t('queue.unknown_option', ['option' => $option, 'options' => implode(', ', array_keys(self::OPTIONS))]));
            $value = $this->parse($option, $this->argument($input, 'value'));
            [$raw, $environment] = $this->target($input, $io);

            $before = $environment === null ? self::appLevelQueue($raw, $name) : self::resolvedQueue($raw, $environment, $name);
            if ($before === null) {
                throw new UserError($this->t('queue.not_found', ['queue' => $name, 'where' => $this->where($environment)]));
            }
            $levelOptions = self::levelQueues($raw, $environment)[$name] ?? [];
            $options = is_array($levelOptions) ? $levelOptions : [];
            if ($value === null) {
                unset($options[$key]);
            } else {
                $options[$key] = $value;
            }
            $changed = self::withQueue($raw, $environment, $name, $options);
            $after = ($environment === null ? self::appLevelQueue($changed, $name) : self::resolvedQueue($changed, $environment, $name))
                ?? throw new UserError($this->t('queue.not_found', ['queue' => $name, 'where' => $this->where($environment)]));
            $from = $this->show($before, $key);
            $to = $this->show($after, $key);
            if ($from === $to) {
                $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            $this->assertValid($changed, $environment);
            $this->writeQueue($environment, $name, $options);
            $io->success($this->t('queue.changed', ['queue' => $name, 'environment' => $this->levelLabel($environment), 'option' => $option, 'from' => $from, 'to' => $to]));
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            $io->writeln('    ' . ArcoFileEditor::queueLine($name, $options));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    private function parse(string $option, string $value): int|bool|null
    {
        if ($option === 'concurrency' && strtolower($value) === 'none') {
            return null;
        }
        if (in_array($option, self::FLAGS, true)) {
            $lower = strtolower($value);
            return match (true) {
                in_array($lower, self::TRUE, true) => true,
                in_array($lower, self::FALSE, true) => false,
                default => throw new UserError($this->t('queue.not_a_bool', ['option' => $option, 'value' => $value])),
            };
        }
        if (preg_match('/^-?\d{1,9}$/', $value) !== 1) {
            throw new UserError($this->t('queue.not_a_number', ['option' => $option, 'value' => $value]));
        }
        return (int) $value;
    }

    private function show(QueueConfig $queue, string $key): string
    {
        $value = match ($key) {
            'timeout' => $queue->timeout,
            'memory' => $queue->memory,
            'concurrency' => $queue->concurrency,
            'batchSize' => $queue->batchSize,
            'tries' => $queue->tries,
            'dlq' => $queue->dlq,
            default => $queue->default,
        };
        return match (true) {
            $value === null => $this->t('queue.none'),
            is_bool($value) => $this->t($value ? 'console.on' : 'console.off'),
            default => (string) $value,
        };
    }
}
