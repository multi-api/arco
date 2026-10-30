<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Closure;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\MultiSearchPrompt;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The picker's questions with laravel/prompts (owner's decision R7): real checkboxes, and a search box (`multisearch`)
 * when a list is longer than one screen. Only `arco resources` uses it, and only in a terminal. Where prompts cannot
 * draw (Windows, or a terminal stty cannot drive) every question falls back to a Symfony question, as Laravel's own
 * console configures it (Illuminate\Console\Concerns\ConfiguresPrompts).
 */
final class PromptsPickerUi implements PickerUi
{
    private const SCROLL = 15;

    /** @param string $none the label of the "nothing ticked" answer of a fallback checklist */
    public function __construct(InputInterface $input, OutputInterface $output, string $none = 'None')
    {
        Prompt::setOutput($output);
        $io = new SymfonyStyle($input, $output);
        // When `stty` fails, prompts prints its error and switches to the fallbacks registered below for the rest of
        // the run. On Windows it refuses before trying stty (checkEnvironment() throws), so the fallbacks start there
        // at once. Without a TTY on STDIN it neither draws nor falls back: it asks nothing and returns each default.
        Prompt::fallbackWhen(PHP_OS_FAMILY === 'Windows');
        ConfirmPrompt::fallbackUsing(static fn (ConfirmPrompt $prompt): bool => $io->confirm($prompt->label, $prompt->default));
        TextPrompt::fallbackUsing(static function (TextPrompt $prompt) use ($io): string {
            do {
                $answer = $io->ask($prompt->label, $prompt->default === '' ? null : $prompt->default);
                $answer = is_scalar($answer) ? trim((string) $answer) : '';
            } while ($answer === '' && $prompt->required !== false);
            return $answer;
        });
        SelectPrompt::fallbackUsing(static function (SelectPrompt $prompt) use ($io): string {
            $answer = $io->choice($prompt->label, $prompt->options, $prompt->default);
            return is_scalar($answer) ? (string) $answer : '';
        });
        MultiSelectPrompt::fallbackUsing(static fn (MultiSelectPrompt $prompt): array => self::checklist($io, $none, $prompt->label, $prompt->options, $prompt->default));
        // The whole list, its ticks kept: a search box has no meaning in a line-by-line question.
        MultiSearchPrompt::fallbackUsing(static fn (MultiSearchPrompt $prompt): array => self::checklist($io, $none, $prompt->label, ($prompt->options)(''), array_keys($prompt->values)));
    }

    public function confirm(string $label, bool $default): bool
    {
        return self::ask(static fn (): bool => confirm($label, $default));
    }

    public function multiselect(string $label, array $options, array $default): array
    {
        if (count($options) <= self::SCROLL) {
            return self::ids(self::ask(static fn (): array => multiselect($label, $options, $default, scroll: self::SCROLL)));
        }
        $search = static fn (string $query): array => $query === ''
            ? $options
            : array_filter($options, static fn (string $text, string $id): bool => mb_stripos($text . ' ' . $id, $query) !== false, ARRAY_FILTER_USE_BOTH);
        $prompt = new MultiSearchPrompt($label, $search, scroll: self::SCROLL);
        // The multisearch helper takes no default: what is in use starts ticked through the prompt's own values.
        $prompt->values = array_intersect_key($options, array_flip($default));
        $picked = self::ask(static fn (): mixed => $prompt->prompt());
        return is_array($picked) ? self::ids($picked) : [];
    }

    public function text(string $label, string $default): string
    {
        return self::ask(static fn (): string => text($label, default: $default, required: true));
    }

    public function select(string $label, array $options, string $default): string
    {
        return (string) self::ask(static fn (): int|string => select($label, $options, $default));
    }

    /**
     * What prompts throws when it can neither draw nor fall back becomes PromptsUnavailable, so the command can print
     * the listing instead of failing.
     *
     * @template T
     * @param Closure(): T $prompt
     * @return T
     */
    private static function ask(Closure $prompt): mixed
    {
        try {
            return $prompt();
        } catch (RuntimeException $e) {
            if (! str_contains(str_replace('\\', '/', $e->getFile()), '/laravel/prompts/')) {
                throw $e;
            }
            throw new PromptsUnavailable($e->getMessage(), 0, $e);
        }
    }

    /**
     * A Symfony multiple-choice question: ids separated by commas, Enter keeps the ticks, and a "none" answer
     * (key '') for unticking everything, as Laravel's fallback offers.
     *
     * @param array<int|string, string> $options
     * @param array<int|string> $default
     * @return list<string>
     */
    private static function checklist(SymfonyStyle $io, string $none, string $label, array $options, array $default): array
    {
        $answers = $io->choice($label, ['' => $none] + $options, $default === [] ? '' : implode(',', $default), true);
        return array_values(array_filter(self::ids(is_array($answers) ? $answers : [$answers]), static fn (string $id): bool => $id !== ''));
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function ids(array $values): array
    {
        return array_values(array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $values));
    }
}
