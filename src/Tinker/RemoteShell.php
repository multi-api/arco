<?php declare(strict_types=1);

namespace Multiapi\Arco\Tinker;

use Multiapi\Arco\Config\AppConfig;
use Psy\ConfigPaths;
use Psy\Configuration;
use Psy\Shell;
use Symfony\Component\Console\Command\Command;

/** PsySH on the user's machine (history, multi-line input, completion) whose statements run remotely. */
final class RemoteShell extends Shell
{
    /**
     * The only PsySH commands kept: none of them evaluates code. The others (timeit, ls, doc, show,
     * dump, copy, config, sudo, edit, trace, wtf...) call Shell::execute() themselves, which would
     * send hidden statements to the console function (timeit would loop forever); typed, they are
     * now plain statements and run remotely once.
     */
    public const COMMANDS = ['help', 'exit', 'clear', 'history', 'buffer'];

    public function __construct(Configuration $config, private readonly RemoteExecution $remote)
    {
        // The promoted property is set before Shell's constructor reads the loop listeners.
        parent::__construct($config);
    }

    /**
     * PsySH settings for a remote session. Applied after PsySH has read the user's global config, so
     * that config cannot bring back local includes, the fork, or the shared history.
     *
     * @param string|null $dataDir where the history goes; PsySH's data dir by default
     */
    public static function configuration(AppConfig $app, ?string $dataDir = null): Configuration
    {
        // trustProject "never": no project .psysh.php and no project autoload run locally.
        $config = new Configuration(['updateCheck' => 'never', 'trustProject' => 'never']);
        // Nothing runs locally.
        $config->setDefaultIncludes([]);
        // The loop stays in this process: no fork whose savegame could resume with an older scope.
        $config->setUsePcntl(false);
        // One history per app and environment: up-arrow never recalls a statement from another one.
        $dataDir ??= (new ConfigPaths())->currentDataDir() ?? $config->getRuntimeDir();
        $config->setHistoryFile($dataDir . "/arco-{$app->app}-{$app->environment}_history");
        return $config;
    }

    /** @return array<mixed> PsySH's own commands (untyped upstream) that never evaluate code */
    protected function getDefaultCommands(): array
    {
        return array_values(array_filter(parent::getDefaultCommands(), self::allowed(...)));
    }

    /**
     * Commands added later (the buffer command, commands from the user's PsySH config) obey the same list.
     *
     * @param Command|callable $command
     */
    public function addCommand($command): ?Command
    {
        return self::allowed($command) ? parent::addCommand($command) : null;
    }

    private static function allowed(mixed $command): bool
    {
        return $command instanceof Command && in_array($command->getName(), self::COMMANDS, true);
    }

    /** @return array<mixed> PsySH's own listeners (untyped upstream), then the remote execution */
    protected function getDefaultLoopListeners(): array
    {
        return [...parent::getDefaultLoopListeners(), $this->remote];
    }

    /** @param \Closure(TinkerReply): void $render */
    public static function start(TinkerSession $session, \Closure $render): int
    {
        return (new self(self::configuration($session->config()), new RemoteExecution($session, $render)))->run();
    }
}
