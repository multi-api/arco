<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\GenericTableConfig;

/** Shared by table:add and table:remove: EditsNamedResources for the table() calls. */
trait EditsGenericTables
{
    use EditsNamedResources;

    /** @return array{method: 'table', key: string, errors: list<string>} */
    private static function resource(): array
    {
        return ['method' => 'table', 'key' => 'tables', 'errors' => ['validation.generic_table']];
    }

    /** @return list<GenericTableConfig> */
    private static function resolvedEntries(AppConfig $config): array
    {
        return $config->tables;
    }
}
