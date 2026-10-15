<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

final class AccountGuard
{
    public static function check(?string $expected, string $actual, ?string $profile): void
    {
        if ($expected !== null && $expected !== $actual) {
            throw new AccountMismatch($expected, $actual, $profile);
        }
    }
}
