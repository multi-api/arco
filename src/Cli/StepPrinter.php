<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

use Closure;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the onStep callbacks of the build and deploy pipeline. `package.progress` (param
 * `percent`) becomes a progress bar redrawn in place on a terminal, and plain 25/50/75/100%
 * lines otherwise (CI logs, pipes), so long phases never look hung. Keys in WARNINGS are printed
 * as warning blocks instead of bullets.
 */
final class StepPrinter
{
    public const PROGRESS = 'package.progress';
    /** Steps that report something the user should act on, not progress. */
    public const WARNINGS = ['deploy.console_downloads_binary', 'deploy.console_skipped_size', 'deploy.console_not_activated', 'deploy.console_retries_not_disabled', 'deploy.console_remove_failed', 'deploy.prune_versions_failed', 'deploy.prune_packages_failed', 'deploy.prune_runtime_failed', 'deploy.scheduler_too_slow', 'deploy.scheduler_skipped', 'deploy.scheduler_failed', 'deploy.scheduler_unreadable', 'deploy.log_retention_failed', 'deploy.queue_kept', 'deploy.worker_failed', 'deploy.workers_sync_failed', 'deploy.worker_scan_failed', 'remove.worker_scan_failed', 'deploy.queue_other_consumer', 'remove.queue_kept', 'remove.scheduler_unreadable'];
    /** Codes of a WARNINGS step's `reason` that are a deliberate choice with nothing to act on: printed as bullets. */
    private const BULLET_REASONS = ['deploy.console_downloads_binary' => ['custom_binary']];
    private const MARKS = [25, 50, 75, 100];
    /**
     * Params holding a code that is translated with the given key prefix before the step is printed;
     * the code's text gets the step's other params (e.g. {url} and {message}).
     */
    private const TRANSLATED_PARAMS = [
        'deploy.console_downloads_binary' => ['reason' => 'deploy.console_reason.'],
        'deploy.log_retention' => ['groups' => 'deploy.log_groups.'],
        'deploy.log_retention_one_day' => ['groups' => 'deploy.log_groups.'],
        'deploy.log_retention_never' => ['groups' => 'deploy.log_groups.'],
    ];

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
        $warning = in_array($key, self::WARNINGS, true) && ! in_array($params['reason'] ?? null, self::BULLET_REASONS[$key] ?? [], true);
        foreach (self::TRANSLATED_PARAMS[$key] ?? [] as $param => $prefix) {
            if (isset($params[$param])) {
                $params[$param] = ($this->translate)($prefix . $params[$param], $params);
            }
        }
        if ($warning) {
            $this->io->warning(($this->translate)($key, $params));
            return;
        }
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
