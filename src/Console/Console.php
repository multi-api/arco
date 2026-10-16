<?php declare(strict_types=1);

namespace Multiapi\Arco\Console;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\ConsoleFunction;

/** Runs commands on an environment's console function. */
interface Console
{
    /**
     * Runs a command and waits for its exit code and output.
     *
     * @param int|null $timeout seconds, lower than the function timeout
     * @param string $qualifier version or alias to invoke; deploy commands pass the version just published
     */
    public function run(AppConfig $config, string $command, ?int $timeout = null, string $qualifier = ConsoleFunction::ALIAS): CommandResult;

    /** Starts a command on the live version without waiting for it. */
    public function start(AppConfig $config, string $command, ?int $timeout = null): void;

    /** Runs one tinker statement; arco:tinker's answer is in the output. */
    public function tinker(AppConfig $config, string $code, ?string $context): CommandResult;
}
