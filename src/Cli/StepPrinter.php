<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

use Closure;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the onStep callbacks of the build and deploy pipeline. `package.progress` (param
 * `percent`) becomes a progress bar redrawn in place on a terminal, and plain 25/50/75/100%
 * lines otherwise (CI logs, pipes), so long phases never look hung.
 */
final class StepPrinter
{
    public const PROGRESS = 'package.progress';
    private const MARKS = [25, 50, 75, 100];

    private ?ProgressBar $bar = null;
    private int $printed = 0;

    /** @param Closure(string, array<string, string|int>): string $translate */
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly Closure $translate,
        private readonly bool $live,
    ) {
    }

    /** @param array<string, string|int> $params */
    public function __invoke(string $key, array $params = []): void
    {
        if ($key === self::PROGRESS) {
            $this->progress((int) ($params['percent'] ?? 0));
            return;
        }
        $this->closeBar();
        $this->printed = 0;
        $this->io->writeln('  • ' . ($this->translate)($key, $params));
    }

    private function progress(int $percent): void
    {
        $percent = max(0, min(100, $percent));
        if ($this->live) {
            if ($this->bar === null) {
                $this->bar = $this->io->createProgressBar(100);
                $this->bar->setFormat('    [%bar%] %percent:3s%%');
                $this->bar->setRedrawFrequency(1);
                $this->bar->minSecondsBetweenRedraws(0);
                $this->bar->start();
            }
            $this->bar->setProgress($percent);
            return;
        }
        foreach (self::MARKS as $mark) {
            if ($percent >= $mark && $this->printed < $mark) {
                $this->printed = $mark;
                $this->io->writeln("    $mark%");
            }
        }
    }

    /** Ends a progress bar still on screen, e.g. before an error is printed. */
    public function finish(): void
    {
        $this->closeBar();
    }

    private function closeBar(): void
    {
        if ($this->bar !== null) {
            $this->bar->finish();
            $this->io->newLine();
            $this->bar = null;
        }
    }
}
