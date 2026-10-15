<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

use Throwable;

final class SsoHint
{
    public static function command(Throwable $e, ?string $profile): ?string
    {
        if ($profile === null) {
            return null;
        }
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'sso') && (str_contains($message, 'expired') || str_contains($message, 'token'))) {
                return "aws sso login --profile $profile";
            }
        }
        return null;
    }
}
