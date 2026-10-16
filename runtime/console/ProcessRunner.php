<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/**
 * Runs a command as a child process without a shell (proc_open with an argument list), stdout and
 * stderr interleaved in one pipe, each chunk echoed as it arrives (to the function log) unless the
 * caller opts out, with a time limit after which the process is terminated (SIGTERM, then SIGKILL
 * after 2 s).
 */
final class ProcessRunner
{
    /** Same code as coreutils timeout(1). */
    public const TIMEOUT_EXIT_CODE = 124;

    /** @param \Closure(string): void $echo */
    public function __construct(private readonly \Closure $echo, private readonly int $bufferLimit = OutputBuffer::LIMIT)
    {
    }

    /**
     * @param list<string> $command executable and arguments
     * @param array<string, string> $env
     * @param bool $echo false keeps the output out of the function log (it is still returned)
     */
    public function run(array $command, string $cwd, array $env, float $timeoutSeconds, ?string $stdin = null, bool $echo = true): ProcessResult
    {
        $pipes = [];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $env);
        if (! is_resource($process)) {
            return new ProcessResult(127, 'Could not start ' . ($command[0] ?? 'the command') . ".\n", false);
        }
        $input = $pipes[0];
        $output = $pipes[1];
        stream_set_blocking($output, false);
        $pending = $stdin ?? '';
        if ($pending === '') {
            fclose($input);
            $input = null;
        } else {
            stream_set_blocking($input, false);
        }

        $buffer = new OutputBuffer($this->bufferLimit);
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }
            $read = [$output];
            $write = $input === null ? [] : [$input];
            $except = [];
            $wait = min(1.0, $remaining);
            if (@stream_select($read, $write, $except, (int) $wait, (int) (($wait - floor($wait)) * 1_000_000)) === false) {
                continue; // interrupted by a signal
            }
            if ($input !== null && in_array($input, $write, true)) {
                $written = @fwrite($input, $pending);
                $pending = $written === false ? '' : substr($pending, $written);
                if ($pending === '') {
                    fclose($input);
                    $input = null;
                }
            }
            if (in_array($output, $read, true)) {
                $chunk = fread($output, 65536);
                if ($chunk === false || ($chunk === '' && feof($output))) {
                    break;
                }
                if ($chunk !== '') {
                    $buffer->append($chunk);
                    if ($echo) {
                        ($this->echo)($chunk);
                    }
                }
            }
        }
        if ($input !== null) {
            fclose($input);
        }

        // Output closed: wait for the exit status, still within the time limit.
        $status = proc_get_status($process);
        while ($status['running'] && ! $timedOut) {
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
            usleep(10_000);
            $status = proc_get_status($process);
        }
        if ($timedOut) {
            self::stop($process);
        }
        fclose($output);
        proc_close($process);

        $exitCode = match (true) {
            $timedOut => self::TIMEOUT_EXIT_CODE,
            $status['signaled'] => 128 + $status['termsig'],
            default => $status['exitcode'],
        };
        return new ProcessResult($exitCode, $buffer->contents(), $timedOut);
    }

    /** @param resource $process */
    private static function stop($process): void
    {
        proc_terminate($process, 15);
        $until = microtime(true) + 2;
        while (proc_get_status($process)['running'] && microtime(true) < $until) {
            usleep(50_000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
    }
}
