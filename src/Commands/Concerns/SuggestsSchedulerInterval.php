<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Config\SchedulerInterval;
use Multiapi\Arco\Config\Validator;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The scheduler interval an app's schedule needs, and the question that picks one (scheduler:set, init). */
trait SuggestsSchedulerInterval
{
    /**
     * The interval the app's schedule needs (1 when there are no tasks), naming the tasks that the next
     * longer interval would skip.
     *
     * @param list<array{task: string, minutes: list<int>}> $tasks
     */
    private function suggestInterval(SymfonyStyle $io, array $tasks): int
    {
        if ($tasks === []) {
            return 1;
        }
        $required = $this->requiredInterval($tasks);
        // The tasks the next longer interval would skip; at 60 (the longest) none would, so all are named.
        $longer = array_values(array_filter(SchedulerInterval::ALLOWED, static fn (int $every): bool => $every > $required));
        $names = $longer === [] ? array_column($tasks, 'task') : SchedulerInterval::missed($longer[0], $tasks);
        $io->note($this->t('scheduler.suggested', ['minutes' => $required, 'tasks' => implode(', ', $names)]));
        return $required;
    }

    /**
     * The longest allowed interval that runs every task on time; 1 when there are no tasks (they could not be read).
     *
     * @param list<array{task: string, minutes: list<int>}> $tasks
     */
    private function requiredInterval(array $tasks): int
    {
        return $tasks === [] ? 1 : SchedulerInterval::required(array_merge([], ...array_map(static fn (array $task): array => $task['minutes'], $tasks)));
    }

    /** Asks for one of the allowed intervals; $default is the one taken on a plain Enter. */
    private function askInterval(SymfonyStyle $io, int $default): int
    {
        $choices = [];
        foreach (Validator::SCHEDULER_INTERVALS as $interval) {
            $choices[$interval] = (string) $interval;
        }
        $question = new ChoiceQuestion($this->t('scheduler.ask'), $choices, (string) $default);
        $answer = $io->askQuestion($question);
        return is_numeric($answer) ? (int) $answer : $default;
    }
}
