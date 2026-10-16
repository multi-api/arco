<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/** The invocation event is not a console request; reported to Lambda as an invocation error. */
final class InvalidEvent extends \InvalidArgumentException
{
}
