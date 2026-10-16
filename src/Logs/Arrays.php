<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

/** @internal */
final class Arrays
{
    /**
     * Decoded JSON objects and SDK results, with every key as a string (for typed access).
     *
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    public static function keyed(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }
        return $out;
    }
}
