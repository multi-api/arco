<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Multiapi\Arco\Deploy\Domains\Aws;

/** Reading AWS tag lists. */
final class Tags
{
    /**
     * @param mixed $tags a `Tags` list: [{Key, Value}, ...]
     * @return array<string, string>
     */
    public static function map(mixed $tags): array
    {
        return Aws::tagMap($tags);
    }

    /** @param array<string, string> $tags */
    public static function name(array $tags): ?string
    {
        $name = $tags['Name'] ?? '';
        return $name === '' ? null : $name;
    }
}
