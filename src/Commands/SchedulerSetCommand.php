<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Build\ScheduleInspector;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\SchedulerInterval;
use Multiapi\Arco\Config\Validator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'scheduler:set', description: 'Run the Laravel scheduler every N minutes (turns the console function on)')]
final class SchedulerSetCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsSettings;

    /** @var Closure(string): (list<array{task: string, minutes: list<int>}>|null) the app's scheduled tasks, read in the project dir */
    private readonly Closure $scheduleInspector;

    /** @param (Closure(string): (list<array{task: string, minutes: list<int>}>|null))|null $scheduleInspector default: ScheduleInspector with this PHP */
    public function __construct(?Closure $scheduleInspector = null)
    {
        $this->scheduleInspector = $scheduleInspector ?? static fn (string $dir): ?array => (new ScheduleInspector())->tasks($dir, PHP_BINARY);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('minutes', InputArgument::OPTIONAL, 'Minutes between schedule:run invocations (' . implode(', ', Validator::SCHEDULER_INTERVALS) . ')');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $given = $input->getArgument('minutes');
            $minutes = is_string($given) && $given !== ''
                ? (preg_match('/^-?\d{1,9}$/', $given) === 1 ? (int) $given : $given)
                : $this->askInterval($input, $io, $input->isInteractive() ? $this->suggestedInterval($io) : 1);
            $error = Validator::settingError('scheduler', $minutes);
            if ($error !== null) {
                throw new UserError($this->t($error['key'], $error['params']));
            }
            [$raw, $environment] = $this->target($input, $io);
            $consoleOff = false;
            foreach ($environment === null ? ConfigResolver::environments($raw) : [$environment] as $env) {
                $consoleOff = $consoleOff || ConfigResolver::resolve($raw, $env)->console === false;
            }
            $from = $environment === null ? self::levelValue($raw, null, 'scheduler') : ConfigResolver::resolve($raw, $environment)->scheduler;
            // The scheduler needs the console function: replace console(false), here or inherited, at this
            // level, and write console() where it is unset so scheduler:remove later keeps the console.
            $writeConsole = $consoleOff || self::consoleUnset($raw, $environment);
            if ($from === $minutes && ! $writeConsole) {
                $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            if ($writeConsole) {
                $this->writeSetting($environment, 'console', true);
                if ($consoleOff) {
                    $io->success($this->t('console.changed', ['environment' => $this->levelLabel($environment), 'from' => $this->t('console.off'), 'to' => $this->t('console.on')]));
                }
            }
            if ($from !== $minutes) {
                $this->writeSetting($environment, 'scheduler', $minutes);
                $io->success($this->t('scheduler.changed', [
                    'environment' => $this->levelLabel($environment),
                    'from' => is_int($from) ? $this->t('scheduler.every', ['minutes' => $from]) : $this->t('scheduler.off'),
                    'to' => $this->t('scheduler.every', ['minutes' => $minutes]),
                ]));
            }
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            if ($writeConsole) {
                $io->writeln('    ' . ArcoFileEditor::settingLine('console', true));
            }
            $io->writeln('    ' . ArcoFileEditor::settingLine('scheduler', $minutes));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /**
     * The interval the app's schedule needs (1 when it cannot be read), naming the tasks that the next
     * longer interval would skip.
     */
    private function suggestedInterval(SymfonyStyle $io): int
    {
        try {
            $tasks = ($this->scheduleInspector)($this->projectDir());
        } catch (Throwable) {
            $tasks = null;
        }
        if ($tasks === null || $tasks === []) {
            return 1;
        }
        $required = SchedulerInterval::required(array_merge([], ...array_map(static fn (array $task): array => $task['minutes'], $tasks)));
        // The tasks the next longer interval would skip; at 60 (the longest) none would, so all are named.
        $longer = array_values(array_filter(SchedulerInterval::ALLOWED, static fn (int $every): bool => $every > $required));
        $names = $longer === [] ? array_column($tasks, 'task') : SchedulerInterval::missed($longer[0], $tasks);
        $io->note($this->t('scheduler.suggested', ['minutes' => $required, 'tasks' => implode(', ', $names)]));
        return $required;
    }

    /** Asks for one of the allowed intervals; $default is the one taken on a plain Enter. */
    private function askInterval(InputInterface $input, SymfonyStyle $io, int $default): int
    {
        if (! $input->isInteractive()) {
            throw new UserError($this->t('scheduler.minutes_required', ['intervals' => implode(', ', Validator::SCHEDULER_INTERVALS)]));
        }
        $choices = [];
        foreach (Validator::SCHEDULER_INTERVALS as $interval) {
            $choices[$interval] = (string) $interval;
        }
        $question = new ChoiceQuestion($this->t('scheduler.ask'), $choices, (string) $default);
        $answer = $io->askQuestion($question);
        return is_numeric($answer) ? (int) $answer : $default;
    }
}
