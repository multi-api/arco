<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

final readonly class ProfileChoice
{
    public function __construct(public ?string $profile, public ProfileSource $source)
    {
    }
}
