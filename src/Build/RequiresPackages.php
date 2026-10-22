<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

/**
 * Optional for a FrameworkPreset: Composer packages the app must require for the preset to run it (Laravel
 * needs laravel/octane). `arco init` lists the missing ones; the builder refuses before `composer install`.
 *
 * @internal Not covered by the 1.x compatibility promise (see FrameworkPreset).
 */
interface RequiresPackages
{
    /** @return list<string> package names, e.g. "laravel/octane" */
    public function requiredPackages(): array;
}
