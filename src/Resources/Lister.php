<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Multiapi\Arco\Aws\AwsClients;

/** Reads the existing resources of one type. Read-only: List*, Describe* and Get* calls only. */
interface Lister
{
    public function type(): ResourceType;

    /** @return list<ResourceItem> */
    public function list(AwsClients $aws): array;
}
