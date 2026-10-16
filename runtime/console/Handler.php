<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/**
 * One invocation of the console function: {"command": "<args>", "timeout"?: int} runs
 * `<php> <entry> <args>`; {"tinker": {"code": base64, "context": base64|null}} runs
 * `<php> <entry> arco:tinker` with the request on stdin. Returns {"exitCode", "output"}.
 */
final class Handler
{
    public const TINKER_COMMAND = 'arco:tinker';
    /**
     * arco:tinker's exit code when the statement threw (the reply line carries the exception). Must
     * match Multiapi\Arco\Laravel\Console\TinkerCommand::STATEMENT_THREW (multiapi/arco-laravel).
     */
    public const TINKER_STATEMENT_THREW = 3;
    /** Must match Multiapi\Arco\Laravel\Console\TinkerCommand::MARKER. */
    public const TINKER_MARKER = 'ARCO-TINKER:';

    /**
     * @param list<string> $php how to run PHP: [<frankenphp>, 'php-cli'] on Lambda
     * @param string|null $entry script every command goes through ('artisan'), null when the first argument is the script
     * @param array<string, string> $env environment of the child process, secrets resolved
     * @param \Closure(array<string, mixed>): void $log writes one JSON line to the function log
     */
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly array $php,
        private readonly string $appDir,
        private readonly ?string $entry,
        private readonly array $env,
        private readonly \Closure $log,
    ) {
    }

    /**
     * @return array{exitCode: int, output: string}
     * @throws InvalidEvent
     */
    public function handle(string $body, int $remainingMs): array
    {
        $event = json_decode($body, true);
        if (! is_array($event) || array_is_list($event)) {
            throw new InvalidEvent('The event must be a JSON object such as {"command": "about"}.');
        }
        // 3 s kept back: the 2 s SIGTERM grace and the response still fit before the Lambda deadline.
        $timeout = max(1.0, $remainingMs / 1000 - 3);
        $stdin = null;
        $tinker = array_key_exists('tinker', $event);
        if ($tinker) {
            [$arguments, $stdin] = $this->tinker($event['tinker']);
        } else {
            $command = $event['command'] ?? null;
            if (! is_string($command) || trim($command) === '') {
                throw new InvalidEvent('The event needs a "command" string, e.g. {"command": "migrate:status"}.');
            }
            ($this->log)(['arco' => 'command', 'command' => $command]);
            try {
                $arguments = ArgumentSplitter::split($command);
            } catch (\InvalidArgumentException $e) {
                return ['exitCode' => 2, 'output' => $e->getMessage() . "\n"];
            }
            $limit = $event['timeout'] ?? null;
            if (is_int($limit) && $limit > 0) {
                $timeout = min($timeout, (float) $limit);
            }
        }

        $argv = [...$this->php, ...($this->entry === null ? [] : [$this->entry]), ...$arguments];
        $started = hrtime(true);
        try {
            // A tinker reply carries the presented result and the serialized scope (secrets, PII):
            // it never goes to the function log, only to the invoker.
            $result = $this->runner->run($argv, $this->appDir, $this->env, $timeout, $stdin, echo: ! $tinker);
        } catch (\ValueError) {
            // proc_open refuses NUL bytes in arguments and environment values.
            $result = new ProcessResult(2, "The command cannot be run: its arguments or environment contain a NUL byte.\n", false);
        }
        // `ms` on every log line is the time since the runtime started; this is the command's own.
        $durationMs = intdiv(hrtime(true) - $started, 1_000_000);
        $output = $result->output;
        if ($result->timedOut) {
            $output .= sprintf("\n[arco] Stopped after %d s: the command reached its time limit.\n", (int) round($timeout));
        }
        if ($tinker) {
            ($this->log)(['arco' => 'tinker_done', 'exitCode' => $result->exitCode, 'durationMs' => $durationMs]);
        }
        // A tinker statement that throws is normal REPL behaviour, not a failed command: arco:tinker
        // exits with its own code and still prints the reply line. Anything else that is not 0 (Laravel
        // failing to boot, a refused request, a fatal error, a signal, a timeout) still is.
        $statementThrew = $tinker
            && $result->exitCode === self::TINKER_STATEMENT_THREW
            && ! $result->timedOut
            && preg_match('/^' . preg_quote(self::TINKER_MARKER, '/') . '/m', $result->output) === 1;
        if ($result->exitCode !== 0 && ! $statementThrew) {
            ($this->log)(['arco' => 'command_failed', 'arguments' => $arguments, 'exitCode' => $result->exitCode, 'timedOut' => $result->timedOut, 'durationMs' => $durationMs]);
        }
        return ['exitCode' => $result->exitCode, 'output' => $output];
    }

    /**
     * @return array{0: list<string>, 1: string} arguments and stdin
     * @throws InvalidEvent
     */
    private function tinker(mixed $request): array
    {
        if (! is_array($request) || ! is_string($request['code'] ?? null)) {
            throw new InvalidEvent('"tinker" must be {"code": base64, "context": base64|null}.');
        }
        $code = base64_decode($request['code'], true);
        $context = $request['context'] ?? null;
        if ($code === false || ($context !== null && ! is_string($context))) {
            throw new InvalidEvent('"tinker" must be {"code": base64, "context": base64|null}.');
        }
        if ($this->entry === null) {
            throw new InvalidEvent('arco tinker needs the Laravel preset (artisan).');
        }
        // Audit trail: every statement is written to the function log before it runs. Long code is
        // cut at 4000 bytes; the hash and length of the whole statement show what ran past the cut.
        ($this->log)(['arco' => 'tinker', 'code' => substr($code, 0, 4000), 'sha256' => hash('sha256', $code), 'length' => strlen($code)]);
        return [[self::TINKER_COMMAND], json_encode(['code' => $request['code'], 'context' => $context], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
    }
}
