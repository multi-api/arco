<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

use RuntimeException;

final class AccountMismatch extends RuntimeException
{
    public function __construct(public readonly string $expected, public readonly string $actual, public readonly ?string $profile)
    {
        parent::__construct("Account mismatch: expected $expected, got $actual");
    }
}
