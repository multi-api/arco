<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

use RuntimeException;
use Throwable;

/** An error whose message is already translated and safe to show as is. */
final class UserError extends RuntimeException
{
    /** @param string|null $key the message key it was translated from, for machine-readable output (--json) */
    public function __construct(string $message = '', public readonly ?string $key = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
