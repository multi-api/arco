<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Sleeper;
use Multiapi\Arco\Logs\FallbackFollower;
use Multiapi\Arco\Logs\Follower;
use Multiapi\Arco\Logs\FullReadStream;
use Multiapi\Arco\Logs\Interrupt;
use Multiapi\Arco\Logs\LogEvent;
use Multiapi\Arco\Logs\LogFilter;
use Multiapi\Arco\Logs\LiveTailFollower;
use Multiapi\Arco\Logs\LogGroups;
use Multiapi\Arco\Logs\LogLine;
use Multiapi\Arco\Logs\LogReader;
use Multiapi\Arco\Logs\LogRenderer;
use Multiapi\Arco\Logs\PollingFollower;
use Multiapi\Arco\Logs\TimeExpression;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'logs', description: 'Show or follow the logs of an environment (web, console and queue workers)')]
final class LogsCommand extends ArcoCommand
{
    use ResolvesTarget;

    private const SOURCES = ['all', 'web', 'console', 'queues'];

    /** @var Closure(AwsClients, array<string, mixed>): CloudWatchLogsClient extra client config as 2nd argument (Live Tail's http_handler) */
    private Closure $logsFactory;
    /** @var Closure(AwsClients, AppConfig, list<string>, Closure(string): void): Follower */
    private Closure $followerFactory;
    private Sleeper $sleeper;

    /** @var Closure(): bool */
    private Closure $liveTailAvailable;

    /**
     * @param (Closure(): bool)|null $stop ends --follow (tests); default: Ctrl+C
     * @param (Closure(): bool)|null $liveTailAvailable default: allow_url_fopen is on. Without it Guzzle
     *        sends through curl, which buffers the whole Live Tail stream, so --follow polls instead.
     */
    public function __construct(
        ?Closure $awsFactory = null,
        ?Closure $logsFactory = null,
        ?Closure $followerFactory = null,
        ?Sleeper $sleeper = null,
        private readonly ?Closure $stop = null,
        ?Closure $liveTailAvailable = null,
    ) {
        $this->liveTailAvailable = $liveTailAvailable ?? static fn (): bool => filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL);
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        $this->logsFactory = $logsFactory ?? self::logsClient(...);
        $this->sleeper = $sleeper ?? new Sleeper();
        $this->followerFactory = $followerFactory ?? $this->defaultFollower(...);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('follow', 'f', InputOption::VALUE_NONE, 'Keep printing new events (Ctrl+C to stop)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Start: 30s, 10m, 2h, 1d or a date', '10m')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'End: 30s, 10m, 2h, 1d or a date')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Minimum level: debug, info, warning or error')
            ->addOption('request', null, InputOption::VALUE_REQUIRED, 'Only lines of this request id')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only events containing this text')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'all, web, console or queues (every queue worker)', 'all')
            ->addOption('console', null, InputOption::VALUE_NONE, 'Only the console function (same as --source=console)')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Only the worker of this queue')
            ->addOption('platform', null, InputOption::VALUE_NONE, 'Show every Lambda platform event')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print raw events as JSON, one per line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $io->getErrorStyle();
        try {
            $config = $this->loadTarget($input, $errors);
            [$source, $queue] = $this->source($input, $config);
            $levelOption = $input->getOption('level');
            $level = is_string($levelOption) ? strtolower($levelOption) : null;
            if ($levelOption !== null && ! in_array($level, LogFilter::LEVELS, true)) {
                throw new UserError($this->t('logs.invalid_level', ['value' => is_scalar($levelOption) ? (string) $levelOption : '', 'levels' => implode(', ', LogFilter::LEVELS)]));
            }
            $follow = (bool) $input->getOption('follow');
            $until = $input->getOption('until');
            if ($follow && $until !== null) {
                throw new UserError($this->t('logs.until_with_follow'));
            }
            $nowMs = (int) floor($this->sleeper->now() * 1000);
            $sinceMs = $this->time($input->getOption('since'), $nowMs);
            $untilMs = $until === null ? null : $this->time($until, $nowMs);
            $request = self::stringOption($input->getOption('request'));
            $filter = new LogFilter($level, $request);
            $pattern = LogFilter::pattern(self::stringOption($input->getOption('filter')), $request);
            $renderer = new LogRenderer($this->t(...), $output->isVerbose(), (bool) $input->getOption('platform'));
            $json = (bool) $input->getOption('json');
            $print = static function (LogEvent $event) use ($output, $filter, $renderer, $json): void {
                $line = LogLine::parse($event->message);
                if (! $filter->accepts($line)) {
                    return;
                }
                if ($json) {
                    $output->writeln((string) json_encode(
                        ['schema' => 1, 'timestamp' => $event->timestamp, 'logGroup' => $event->group, 'logStream' => $event->stream, 'message' => $event->message],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                    ), OutputInterface::OUTPUT_RAW);
                    return;
                }
                foreach ($renderer->render($event, $line) as $text) {
                    $output->writeln($text);
                }
            };

            $aws = $this->awsClients($config, $input, $errors);
            $groups = LogGroups::for($config, $source, $queue);
            $read = (new LogReader(($this->logsFactory)($aws, [])))->read($groups, $sinceMs, $untilMs, $pattern);
            foreach ($read['events'] as $event) {
                $print($event);
            }
            if ($read['truncated']) {
                $errors->note($this->t('logs.truncated', ['max' => LogReader::MAX_EVENTS]));
            }
            if ($read['existing'] === []) {
                $errors->note($this->t('logs.no_groups', ['groups' => implode(', ', $groups)]));
            }
            if (! $follow) {
                return self::SUCCESS;
            }

            $interrupt = new Interrupt();
            $interrupt->listen();
            $stop = $this->stop ?? static fn (): bool => $interrupt->requested();
            $notify = function (string $key) use ($errors): void {
                $errors->writeln('<comment>' . $this->t($key) . '</comment>');
            };
            $follower = ($this->followerFactory)($aws, $config, $read['existing'], $notify);
            $live = $follower instanceof FallbackFollower && $follower->usesLiveTail($groups);
            if ($follower instanceof FallbackFollower && $follower->liveGroups($groups) > LiveTailFollower::MAX_GROUPS) {
                $errors->note($this->t('logs.live_tail_too_many', ['max' => LiveTailFollower::MAX_GROUPS, 'count' => $follower->liveGroups($groups)]));
            }
            $errors->writeln($this->t($live ? 'logs.following' : 'logs.following_polling', ['groups' => implode(', ', $groups)]));
            // Events already printed from the history are not printed again. By content, not eventId:
            // Live Tail events have none, so the same event would get a different id from each source.
            $history = array_fill_keys(array_map(fn (LogEvent $event) => $event->contentKey(), $read['events']), true);
            $follower->follow(
                $groups,
                $sinceMs,
                $pattern,
                static function (LogEvent $event) use ($history, $print): void {
                    if (! isset($history[$event->contentKey()])) {
                        $print($event);
                    }
                },
                $stop,
            );
            return self::SUCCESS;
        } catch (Throwable $e) {
            $errors->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /**
     * The LogGroups source and queue from --source, --console and --queue (only one of them).
     *
     * @return array{0: string, 1: ?string}
     */
    private function source(InputInterface $input, AppConfig $config): array
    {
        $sourceOption = $input->getOption('source');
        $source = is_string($sourceOption) ? $sourceOption : '';
        if (! in_array($source, self::SOURCES, true)) {
            throw new UserError($this->t('logs.invalid_source', ['value' => $source]));
        }
        $console = (bool) $input->getOption('console');
        $queue = self::stringOption($input->getOption('queue'));
        if (count(array_filter([$source !== 'all', $console, $queue !== null])) > 1) {
            throw new UserError($this->t('logs.source_conflict'));
        }
        if ($queue === null) {
            return [$console ? 'console' : $source, null];
        }
        $names = array_map(fn ($q) => $q->name, $config->queues);
        if (! in_array($queue, $names, true)) {
            throw new UserError($this->t('queue.unknown', ['queue' => $queue, 'queues' => $names === [] ? $this->t('queue.none') : implode(', ', $names)]));
        }
        return ['queue', $queue];
    }

    /** @param array<string, mixed> $overrides */
    private static function logsClient(AwsClients $aws, array $overrides = []): CloudWatchLogsClient
    {
        return $aws->logs($overrides);
    }

    /**
     * @param list<string> $existing
     * @param Closure(string): void $notify
     */
    private function defaultFollower(AwsClients $aws, AppConfig $config, array $existing, Closure $notify): Follower
    {
        if (! ($this->liveTailAvailable)()) {
            return new PollingFollower(($this->logsFactory)($aws, []), $this->sleeper);
        }
        $live = ($this->logsFactory)($aws, ['http_handler' => FullReadStream::wrap(\Aws\default_http_handler())]);
        return new FallbackFollower(
            new LiveTailFollower($live, (string) $config->region, $aws->accountId(), $existing),
            new PollingFollower(($this->logsFactory)($aws, []), $this->sleeper),
            $existing,
            $notify,
        );
    }

    private function time(mixed $value, int $nowMs): int
    {
        try {
            return TimeExpression::parse(is_scalar($value) ? (string) $value : '', $nowMs);
        } catch (\InvalidArgumentException) {
            throw new UserError($this->t('logs.invalid_time', ['value' => is_scalar($value) ? (string) $value : '']));
        }
    }

    private static function stringOption(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
