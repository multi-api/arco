<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

/** The live alias moved, but Lambda's asynchronous retries could not be turned off afterwards. */
final class ConsoleRetriesNotDisabled extends \RuntimeException
{
}
