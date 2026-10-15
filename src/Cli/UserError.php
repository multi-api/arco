<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

use RuntimeException;

/** An error whose message is already translated and safe to show as is. */
final class UserError extends RuntimeException
{
}
