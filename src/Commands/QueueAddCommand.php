<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsQueues;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'queue:add', description: 'Add an SQS queue and its worker function to arco.php')]
final class QueueAddCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsQueues;

    /** CLI option => queue() argument, for the options that take a whole number. */
    private const NUMBERS = ['timeout' => 'timeout', 'memory' => 'memory', 'concurrency' => 'concurrency', 'batch-size' => 'batchSize', 'tries' => 'tries'];

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Queue name (lowercase letters, digits and hyphens)')
            ->addOption('existing', null, InputOption::VALUE_REQUIRED, 'Name, URL or ARN of an SQS queue Arco does not manage')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Worker timeout in seconds')
            ->addOption('memory', null, InputOption::VALUE_REQUIRED, 'Worker memory in MB')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Maximum concurrent worker invocations')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Messages per worker invocation')
            ->addOption('tries', null, InputOption::VALUE_REQUIRED, 'Attempts of a job without its own $tries (the dead-letter queue receives it after tries + 2 deliveries)')
            ->addOption('no-dlq', null, InputOption::VALUE_NONE, 'Do not create a dead-letter queue')
            ->addOption('fifo', null, InputOption::VALUE_NONE, 'Create a FIFO queue')
            ->addOption('default', null, InputOption::VALUE_NONE, 'Make this the default queue');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            return $this->addNamed($input, $io, function () use ($input): array {
                $options = [];
                $existing = $input->getOption('existing');
                if (is_string($existing) && $existing !== '') {
                    $options['existing'] = $existing;
                }
                if ($input->getOption('default')) {
                    $options['default'] = true;
                }
                foreach (self::NUMBERS as $cli => $key) {
                    $value = $input->getOption($cli);
                    if ($value === null) {
                        continue;
                    }
                    if (! is_string($value) || preg_match('/^-?\d{1,9}$/', $value) !== 1) {
                        throw new UserError($this->t('queue.not_a_number', ['option' => $cli, 'value' => is_string($value) ? $value : '']));
                    }
                    $options[$key] = (int) $value;
                }
                if ($input->getOption('no-dlq')) {
                    $options['dlq'] = false;
                }
                if ($input->getOption('fifo')) {
                    $options['fifo'] = true;
                }
                return $options;
            });
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
