<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Build\ScheduleInspector;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\AppliesScheduler;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Commands\Concerns\SuggestsSchedulerInterval;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Validator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'scheduler:set', description: 'Run the Laravel scheduler every N minutes (turns the console function on)')]
final class SchedulerSetCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsSettings;
    use AppliesScheduler;
    use SuggestsSchedulerInterval;

    /** @var Closure(string): (list<array{task: string, minutes: list<int>}>|null) the app's scheduled tasks, read in the project dir */
    private readonly Closure $scheduleInspector;

    /**
     * @param (Closure(string): (list<array{task: string, minutes: list<int>}>|null))|null $scheduleInspector default: ScheduleInspector with this PHP
     * @param (Closure(?string, string): \Multiapi\Arco\Aws\AwsClients)|null $awsFactory
     * @param (Closure(\Multiapi\Arco\Aws\AwsClients): \Multiapi\Arco\Deploy\Deployer)|null $deployerFactory
     */
    public function __construct(?Closure $scheduleInspector = null, ?Closure $awsFactory = null, ?Closure $deployerFactory = null)
    {
        $this->scheduleInspector = $scheduleInspector ?? static fn (string $dir): ?array => (new ScheduleInspector())->tasks($dir, PHP_BINARY);
        $this->initApply($awsFactory, $deployerFactory);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('minutes', InputArgument::OPTIONAL, 'Minutes between schedule:run invocations (' . implode(', ', Validator::SCHEDULER_INTERVALS) . ')');
        $this->addTargetOptions();
        $this->addApplyOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $given = $input->getArgument('minutes');
            $minutes = is_string($given) && $given !== ''
                ? self::intOrText($given)
                : $this->askMinutes($input, $io);
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
                // arco.php already says so (edited by hand, say): --apply still puts it in AWS.
                if ($environment !== null && $input->getOption('apply')) {
                    return $this->applyScheduler($input, $io) ? self::SUCCESS : self::FAILURE;
                }
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
            if ($this->wantsApply($input, $io, $environment)) {
                return $this->applyScheduler($input, $io) ? self::SUCCESS : self::FAILURE;
            } else {
                $this->printDeployNext($io, $raw, $environment);
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /** Asks for the interval, suggesting the one the app's schedule needs (1 when it cannot be read). */
    private function askMinutes(InputInterface $input, SymfonyStyle $io): int
    {
        if (! $input->isInteractive()) {
            throw new UserError($this->t('scheduler.minutes_required', ['intervals' => implode(', ', Validator::SCHEDULER_INTERVALS)]));
        }
        try {
            $tasks = ($this->scheduleInspector)($this->projectDir());
        } catch (Throwable) {
            $tasks = null;
        }
        return $this->askInterval($io, $this->suggestInterval($io, $tasks ?? []));
    }
}
