<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

/**
 * Optional for a FrameworkPreset: empty directories the framework needs while building (Composer scripts,
 * build steps) that its exclude rules keep out of the staged copy. The builder creates them in the staged copy
 * before `composer install` and removes them, with anything the build wrote there, before packaging.
 *
 * @internal Not covered by the 1.x compatibility promise (see FrameworkPreset).
 */
interface StagesDirectories
{
    /** @return list<string> paths relative to the app directory, e.g. "storage/framework/views" */
    public function buildDirectories(): array;
}
