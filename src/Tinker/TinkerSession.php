<?php declare(strict_types=1);

namespace Multiapi\Arco\Tinker;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Console\Console;

/** One tinker session: each statement goes to the console function with the scope of the last answer. */
final class TinkerSession
{
    /** Must match Multiapi\Arco\Laravel\Console\TinkerCommand::MARKER (multiapi/arco-laravel). */
    public const MARKER = 'ARCO-TINKER:';
    /**
     * arco:tinker's exit code when the statement threw. Must match
     * Multiapi\Arco\Laravel\Console\TinkerCommand::STATEMENT_THREW. The exception is read from the
     * reply line; `arco tinker --execute` exits with 1 on it whatever this code is.
     */
    public const STATEMENT_THREW = 3;

    private ?string $context = null;

    public function __construct(private readonly Console $console, private readonly AppConfig $config)
    {
    }

    public function execute(string $code): TinkerReply
    {
        $reply = TinkerReply::parse($this->console->tinker($this->config, $code, $this->context));
        if ($reply->replied && ! self::failedBeforeRunning($reply)) {
            $this->context = $reply->context;
        }
        return $reply;
    }

    public function config(): AppConfig
    {
        return $this->config;
    }

    public function context(): ?string
    {
        return $this->context;
    }

    /**
     * arco:tinker refused the statement (bad input, psysh missing) and sent no scope back. A null
     * context otherwise means an empty scope, or variables that are reported as dropped.
     */
    private static function failedBeforeRunning(TinkerReply $reply): bool
    {
        return $reply->exception !== null && $reply->context === null && $reply->dropped === [];
    }
}
