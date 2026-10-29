<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

use Closure;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the onStep callbacks of the build and deploy pipeline. `package.progress` (param
 * `percent`, and `sent`/`total` in MB for an upload) becomes a progress bar redrawn in place on a
 * terminal, and plain 25/50/75/100% lines otherwise (CI logs, pipes), so long phases never look hung.
 * tick() (called after each wait of the deploy) adds the time the running step has taken, for the
 * waits that have no progress. Keys in WARNINGS are printed as warning blocks instead of bullets.
 */
final class StepPrinter
{
    public const PROGRESS = 'package.progress';
    /** Steps that report something the user should act on, not progress. */
    public const WARNINGS = ['deploy.console_downloads_binary', 'deploy.console_skipped_size', 'deploy.console_not_activated', 'deploy.console_retries_not_disabled', 'deploy.console_remove_failed', 'deploy.prune_versions_failed', 'deploy.prune_packages_failed', 'deploy.prune_runtime_failed', 'deploy.scheduler_too_slow', 'deploy.scheduler_skipped', 'deploy.scheduler_failed', 'deploy.scheduler_unreadable', 'deploy.log_retention_failed', 'deploy.queue_kept', 'deploy.worker_failed', 'deploy.workers_sync_failed', 'deploy.worker_scan_failed', 'remove.worker_scan_failed', 'deploy.queue_other_consumer', 'remove.queue_kept', 'remove.scheduler_unreadable', 'deploy.storage_kept', 'deploy.table_kept', 'deploy.table_kept_unknown', 'remove.storage_kept', 'remove.table_kept', 'remove.table_kept_unknown', 'deploy.ownership_forced', 'deploy.tags_not_added', 'deploy.domain_stack_unreadable', 'deploy.endpoint_still_public', 'deploy.network_prune_failed', 'deploy.network_replacing', 'deploy.network_cleanup_failed', 'deploy.network_foreign_kept', 'remove.network_failed', 'remove.network_foreign_kept', 'deploy.vpc_interfaces_pending', 'deploy.vpc_interfaces_failed', 'remove.vpc_interfaces_pending', 'remove.vpc_interfaces_failed', 'deploy.endpoint_skipped', 'deploy.endpoint_cleanup_failed', 'remove.endpoint_cleanup_failed', 'deploy.endpoint_record_failed'];
    /**
     * Informational lines that are not a step of their own: printed as bullets, streamed by `--json` as
     * `note` (they do not end the running step).
     */
    public const NOTES = ['deploy.boundary_kept', 'deploy.endpoint_unprotected_emulator', 'deploy.vpc_unsupported_emulator', 'deploy.upload_retry'];
    /** Codes of a WARNINGS step's `reason` that are a deliberate choice with nothing to act on: printed as bullets. */
    private const BULLET_REASONS = ['deploy.console_downloads_binary' => ['custom_binary']];
    private const MARKS = [25, 50, 75, 100];
    /** Seconds a step runs before tick() shows its elapsed time. */
    private const ELAPSED_AFTER = 10;
    /**
     * Params holding a code that is translated with the given key prefix before the step is printed;
     * the code's text gets the step's other params (e.g. {url} and {message}).
     */
    private const TRANSLATED_PARAMS = [
        'deploy.console_downloads_binary' => ['reason' => 'deploy.console_reason.'],
        'deploy.log_retention' => ['groups' => 'deploy.log_groups.'],
        'deploy.log_retention_one_day' => ['groups' => 'deploy.log_groups.'],
        'deploy.log_retention_never' => ['groups' => 'deploy.log_groups.'],
        'deploy.log_retention_default' => ['groups' => 'deploy.log_groups.'],
        'deploy.storage_kept' => ['objects' => 'deploy.storage_objects.'],
        'remove.storage_kept' => ['objects' => 'deploy.storage_objects.'],
        'deploy.table_created' => ['kind' => 'deploy.table_kind.'],
        'deploy.table_updated' => ['kind' => 'deploy.table_kind.'],
        'deploy.table_changed' => ['kind' => 'deploy.table_kind.'],
        'deploy.table_now_existing' => ['as' => 'deploy.table_kind.'],
    ];

    private ?ProgressBar $bar = null;
    /** The live "elapsed" line under the running step. */
    private ?ProgressBar $elapsedBar = null;
    private int $printed = 0;
    private ?int $percent = null;
    /** When the running step started; null before the first one. */
    private ?float $startedAt = null;
    /** Whole minutes already printed for the running step (plain output). */
    private int $minutesPrinted = 0;
    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param Closure(string, array<string, string|int>): string $translate
     * @param (Closure(): float)|null $clock seconds, for the elapsed time (microtime by default)
     */
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly Closure $translate,
        private readonly bool $live,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** @param array<string, string|int> $params */
    public function __invoke(string $key, array $params = []): void
    {
        if ($key === self::PROGRESS) {
            $mb = isset($params['sent'], $params['total']) ? ($this->translate)('step.progress_mb', ['sent' => $params['sent'], 'total' => $params['total']]) : null;
            $this->progress((int) ($params['percent'] ?? 0), $mb);
            return;
        }
        $this->closeBar();
        $kind = self::kind($key, $params);
        // A note (e.g. deploy.upload_retry) is not a step of its own: the running step keeps its progress and start.
        if ($kind !== 'note') {
            $this->printed = 0;
            $this->percent = null;
            $this->startedAt = ($this->clock)();
            $this->minutesPrinted = 0;
        }
        $message = self::message($this->translate, $key, $params);
        if ($kind === 'warning') {
            $this->io->warning($message);
            return;
        }
        $this->io->writeln('  • ' . $message);
    }

    /**
     * How a step is shown: `warning` (a warning block), `note` (a NOTES key, or a WARNINGS key whose
     * reason is a deliberate choice, printed as a bullet) or `step` (a bullet).
     *
     * @param array<string, string|int> $params
     * @return 'warning'|'note'|'step'
     */
    public static function kind(string $key, array $params): string
    {
        if (in_array($key, self::NOTES, true)) {
            return 'note';
        }
        if (! in_array($key, self::WARNINGS, true)) {
            return 'step';
        }
        return in_array($params['reason'] ?? null, self::BULLET_REASONS[$key] ?? [], true) ? 'note' : 'warning';
    }

    /**
     * The step's text, with its TRANSLATED_PARAMS codes translated first.
     *
     * @param Closure(string, array<string, string|int>): string $translate
     * @param array<string, string|int> $params
     */
    public static function message(Closure $translate, string $key, array $params): string
    {
        foreach (self::TRANSLATED_PARAMS[$key] ?? [] as $param => $prefix) {
            if (isset($params[$param])) {
                $params[$param] = $translate($prefix . $params[$param], $params);
            }
        }
        return $translate($key, $params);
    }

    /**
     * Shows the running step's elapsed time, for a long wait with no progress of its own: redrawn in
     * place on a terminal, one line per whole minute otherwise. Nothing in a step's first ten seconds,
     * while a progress bar runs, or after finish(); a bar at 100% is ended first.
     */
    public function tick(): void
    {
        if ($this->startedAt === null || ($this->percent !== null && $this->percent < 100)) {
            return;
        }
        $elapsed = (int) floor(($this->clock)() - $this->startedAt);
        if ($elapsed < self::ELAPSED_AFTER) {
            return;
        }
        if ($this->live) {
            $this->closePercentBar();
            if ($this->elapsedBar === null) {
                $this->elapsedBar = $this->io->createProgressBar(0);
                $this->elapsedBar->setFormat('    %message%');
                $this->elapsedBar->setRedrawFrequency(1);
                $this->elapsedBar->minSecondsBetweenRedraws(0);
                $this->elapsedBar->setMessage($this->elapsed(self::duration($elapsed)));
                $this->elapsedBar->start();
                return;
            }
            $this->elapsedBar->setMessage($this->elapsed(self::duration($elapsed)));
            $this->elapsedBar->display();
            return;
        }
        $minutes = intdiv($elapsed, 60);
        if ($minutes > $this->minutesPrinted) {
            $this->minutesPrinted = $minutes;
            $this->io->writeln('    ' . $this->elapsed("$minutes min"));
        }
    }

    private function elapsed(string $time): string
    {
        return ($this->translate)('step.elapsed', ['time' => $time]);
    }

    /** "45 s" under a minute, "3 min 05 s" after (the units read the same in every language). */
    private static function duration(int $seconds): string
    {
        return $seconds < 60 ? "$seconds s" : sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
    }

    private function progress(int $percent, ?string $mb): void
    {
        $percent = max(0, min(100, $percent));
        $this->percent = $percent;
        if ($this->live) {
            $this->closeElapsed();
            if ($this->bar === null) {
                $this->bar = $this->io->createProgressBar(100);
                $this->bar->setFormat($mb === null ? '    [%bar%] %percent:3s%%' : '    [%bar%] %percent:3s%% %message%');
                $this->bar->setMessage((string) $mb);
                $this->bar->setRedrawFrequency(1);
                $this->bar->minSecondsBetweenRedraws(0);
                $this->bar->start(null, $percent);
                return;
            }
            $this->bar->setMessage((string) $mb);
            $this->bar->setProgress($percent);
            return;
        }
        // Only the highest mark reached: a jump (a file already in the bucket counts as sent at once) prints one line,
        // not the marks in between with the bytes of the end.
        $reached = null;
        foreach (self::MARKS as $mark) {
            if ($percent >= $mark && $this->printed < $mark) {
                $reached = $mark;
            }
        }
        if ($reached !== null) {
            $this->printed = $reached;
            $this->io->writeln("    $reached%" . ($mb === null ? '' : " ($mb)"));
        }
    }

    /** Ends a progress bar still on screen, e.g. before an error is printed. */
    public function finish(): void
    {
        $this->closeBar();
        // What follows (a deploy command's output, an error) is not the step's: no elapsed line under it.
        $this->startedAt = null;
    }

    private function closeBar(): void
    {
        $this->closePercentBar();
        $this->closeElapsed();
    }

    private function closePercentBar(): void
    {
        if ($this->bar !== null) {
            $this->bar->finish();
            $this->io->newLine();
            $this->bar = null;
        }
    }

    private function closeElapsed(): void
    {
        if ($this->elapsedBar !== null) {
            $this->elapsedBar->finish();
            $this->io->newLine();
            $this->elapsedBar = null;
        }
    }
}
