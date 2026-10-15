<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

final readonly class AwsProfile
{
    /** @param 'sso'|'keys'|'other' $type */
    public function __construct(public string $name, public string $type, public ?string $accountId)
    {
    }
}
