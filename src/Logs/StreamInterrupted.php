<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

/** A FullReadStream read ended early: a stop was requested, or no byte arrived before the idle deadline. */
final class StreamInterrupted extends \RuntimeException
{
    private function __construct(string $message, public readonly bool $stopped)
    {
        parent::__construct($message);
    }

    public static function stopped(): self
    {
        return new self('Reading the stream was stopped.', true);
    }

    public static function stalled(float $seconds): self
    {
        return new self(sprintf('The stream sent no data for %s s.', $seconds), false);
    }
}
