<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Aws\AwsClientInterface;

/** Untyped access to SDK operations and results, like WebFunctions::call (payloads are built dynamically). */
final class Aws
{
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function call(AwsClientInterface $client, string $operation, array $args = []): array
    {
        return self::map($client->execute($client->getCommand($operation, $args))->toArray());
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[(string) $key] = $item;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function maps(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                $out[] = self::map($item);
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_string'));
    }

    public static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A tag list ([{Key, Value}, ...]) as a map.
     *
     * @return array<string, string>
     */
    public static function tagMap(mixed $value): array
    {
        $tags = [];
        foreach (self::maps($value) as $tag) {
            if (is_string($tag['Key'] ?? null) && is_string($tag['Value'] ?? null)) {
                $tags[$tag['Key']] = $tag['Value'];
            }
        }
        return $tags;
    }
}
