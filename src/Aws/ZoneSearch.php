<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

use Throwable;

/** The zones one profile's Route 53 holds for a set of domains, or why the profile could not be checked. */
final readonly class ZoneSearch
{
    /** @param array<string, ZoneMatch> $found per domain */
    public function __construct(public string $profile, public array $found, public ?Throwable $error = null)
    {
    }

    /**
     * @param list<string> $domains
     * @return list<string>
     */
    public function missing(array $domains): array
    {
        return array_values(array_filter($domains, fn (string $d) => ! isset($this->found[$d])));
    }
}
