<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

final readonly class HostedZone
{
    public function __construct(public string $id, public string $name, public bool $private)
    {
    }

    /**
     * The longest public zone whose name is the record name or a parent of it.
     *
     * @param list<self> $zones
     */
    public static function select(array $zones, string $recordName): ?self
    {
        $record = Hostname::normalize($recordName);
        $best = null;
        foreach ($zones as $zone) {
            $name = Hostname::normalize($zone->name);
            if ($zone->private || $name === '' || ($record !== $name && ! str_ends_with($record, '.' . $name))) {
                continue;
            }
            if ($best === null || strlen($name) > strlen(Hostname::normalize($best->name))) {
                $best = $zone;
            }
        }
        return $best;
    }
}
