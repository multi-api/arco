<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Closure;
use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Cli\JsonStream;
use Multiapi\Arco\Cli\SdkNoticeFilter;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Deploy\DeployException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The --json mode of deploy and remove (spec G2): stdout carries only the NDJSON lines of a JsonStream,
 * nothing prompts, diagnostics (PHP warnings and deprecations included) go to stderr, and exactly one
 * result line is written whatever happens, with the exit code the human output would have.
 *
 * @phpstan-require-extends \Multiapi\Arco\Cli\ArcoCommand
 */
trait StreamsJson
{
    /** @var Closure(): float seconds since the epoch, for the --json timestamps */
    protected Closure $clock;
    /** --json: the target once arco.php is loaded */
    protected ?AppConfig $jsonTarget = null;

    /** Whether AWS may have changed: a stop after that is "failed", never "refused". */
    abstract protected function jsonChanged(): bool;

    /** Whether traffic went back to the previous revision. */
    abstract protected function jsonRolledBack(): bool;

    /** @return 'deployed'|'removed' the outcome of a run that succeeded */
    abstract protected function jsonSucceeded(): string;

    abstract protected function cloudflareToken(InputInterface $input): ?string;

    /**
     * Runs $run(stderr, stream) in --json mode and returns its exit code. An exception the human output
     * leaves to the application is caught: the result says it and the application's exit code is returned.
     *
     * @param Closure(OutputInterface, JsonStream): int $run
     */
    protected function runJson(InputInterface $input, OutputInterface $output, Closure $run): int
    {
        $this->jsonTarget = null;
        $input->setInteractive(false);
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : new NullOutput();
        $stream = new JsonStream($output, $this->t(...), $this->clock);
        $stream->redact($this->cloudflareToken($input), self::jsonEnv('AWS_SECRET_ACCESS_KEY'), self::jsonEnv('AWS_SESSION_TOKEN'));

        // PHP's own messages (a vendor deprecation, a warning) would land on stdout with php-cli's defaults.
        $display = ini_set('display_errors', 'stderr');
        set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use ($stderr, $stream): bool {
            if (SdkNoticeFilter::handle($level, $message, $file)) {
                return true;
            }
            if ((error_reporting() & $level) === 0) {
                return false;
            }
            $stderr->writeln($stream->scrubbed(sprintf('PHP %s: %s in %s:%d', self::jsonErrorLabel($level), $message, $file, $line)), OutputInterface::OUTPUT_RAW);
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);
        try {
            $code = $run($stderr, $stream);
        } catch (Throwable $e) {
            $this->jsonFailure($stream, $input, $e, 'error.unexpected', $e->getMessage());
            $stderr->writeln($stream->scrubbed($e::class . ': ' . $e->getMessage()), OutputInterface::OUTPUT_RAW);
            // As Symfony's Application renders an uncaught exception.
            $exception = $e->getCode();
            $code = is_int($exception) && $exception > 0 ? min($exception, 255) : self::FAILURE;
        } finally {
            restore_error_handler();
            if ($display !== false) {
                ini_set('display_errors', $display);
            }
        }

        // Exactly one result line, by construction: a path that returned without one still gets it.
        if (! $stream->hasResult()) {
            if ($code === self::SUCCESS) {
                $stream->result($this->jsonSucceeded(), $this->jsonTarget?->app, $this->jsonEnvironment($input));
            } else {
                $this->jsonFailure($stream, $input, null, 'common.aborted', $this->t('common.aborted'));
            }
        }
        return $code;
    }

    /** Called once arco.php is loaded: its app and environment names are never redacted. */
    protected function jsonTarget(?JsonStream $stream, AppConfig $config): void
    {
        $this->jsonTarget = $config;
        // The custom domains come from arco.php too: a short secret (Floci's "test" key) must not mangle them.
        $stream?->identity($config->app, $config->environment, ...$config->domains);
    }

    /**
     * The result of a run that stopped: "refused" for a guard or refusal before AWS changed (a
     * UserError: missing confirmation, profile, credentials or account lock; a config or validation
     * error; a DeployException guard such as ownership or a missing or rejected Cloudflare token or zone; a prebuilt package
     * that does not fit), "failed" for anything else (a build, download or AWS failure) or after a change.
     */
    protected function jsonFailure(JsonStream $stream, InputInterface $input, ?Throwable $e, string $key, string $message): void
    {
        $stream->result(
            ! $this->jsonChanged() && self::isRefusal($e) ? 'refused' : 'failed',
            $this->jsonTarget?->app,
            $this->jsonEnvironment($input),
            rolledBack: $this->jsonRolledBack(),
            errorKey: $key,
            errorMessage: $message,
        );
    }

    private static function isRefusal(?Throwable $e): bool
    {
        return $e === null
            || $e instanceof UserError
            || $e instanceof ConfigException
            || ($e instanceof DeployException && ! in_array($e->key, self::jsonTransientKeys(), true))
            || ($e instanceof BuildException && in_array($e->key, self::jsonPackageRefusals(), true));
    }

    /** @return list<string> Cloudflare network or outage errors: like an AWS error before any change, they are failures, not refusals */
    private static function jsonTransientKeys(): array
    {
        return ['cloudflare.gave_up', 'cloudflare.request_failed'];
    }

    /** @return list<string> PackageReader's refusals of a --package zip that does not fit this deploy */
    private static function jsonPackageRefusals(): array
    {
        return ['package.corrupt', 'package.not_arco', 'package.wrong_target', 'package.wrong_preset', 'package.no_console_runtime'];
    }

    private function jsonEnvironment(InputInterface $input): ?string
    {
        $environment = $input->getOption('env');
        return $this->jsonTarget->environment ?? (is_string($environment) && $environment !== '' ? $environment : null);
    }

    private static function jsonEnv(string $name): ?string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function jsonErrorLabel(int $level): string
    {
        return match ($level) {
            E_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            default => 'Deprecated',
        };
    }
}
