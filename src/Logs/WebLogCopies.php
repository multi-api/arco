<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

/**
 * Lambda Web Functions write every line to the in-Region "/aws/lambda/web/<function>.local" group (stream
 * "<stream>") and then centralize it, without removing it, into "/aws/lambda/web/<function>" in the home Region
 * (stream "<stream>-<account>-<region>"): https://docs.aws.amazon.com/lambda/latest/dg/web-functions-logging.html
 * Both groups are read (the .local copy arrives seconds earlier), so the second copy of a line is dropped: same
 * function, stream, timestamp and message.
 */
final class WebLogCopies
{
    private const PREFIX = '/aws/lambda/web/';
    // Copies arrive seconds apart; keys older than this behind the newest event seen are forgotten.
    private const WINDOW_MS = 600_000;
    private const PRUNE_AT = 20_000;

    /** @var array<string, array{0: int, 1: int, 2: int}> key => [copies from the group, copies from .local, timestamp] */
    private array $seen = [];
    private int $newest = 0;

    /** Whether $event is a line not printed yet from the other group. Events of other log groups always are. */
    public function first(LogEvent $event): bool
    {
        if (! str_starts_with($event->group, self::PREFIX)) {
            return true;
        }
        $local = str_ends_with($event->group, '.local');
        $group = $local ? substr($event->group, 0, -strlen('.local')) : $event->group;
        $stream = (string) preg_replace('/-\d{12}-[a-z]{2}(?:-[a-z]+)+-\d+$/', '', $event->stream);
        $key = hash('sha256', "$group\0$stream\0{$event->timestamp}\0{$event->message}");
        // Counted per group: a line written twice in the same millisecond is in each group twice, and printed twice.
        $counts = $this->seen[$key] ?? [0, 0, $event->timestamp];
        $counts[$local ? 1 : 0]++;
        $this->seen[$key] = $counts;
        $this->newest = max($this->newest, $event->timestamp);
        if (count($this->seen) > self::PRUNE_AT) {
            $this->seen = array_filter($this->seen, fn (array $c) => $c[2] >= $this->newest - self::WINDOW_MS);
        }
        return $counts[$local ? 1 : 0] > $counts[$local ? 0 : 1];
    }
}
