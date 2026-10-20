<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

/**
 * Optional for a FrameworkPreset: paths whose responses never change for a given URL (fingerprinted
 * build output), which CloudFront may cache when the app has custom domains. Separate from
 * FrameworkPreset so presets written before custom domains keep working.
 *
 * @internal Not covered by the 1.x compatibility promise (see FrameworkPreset).
 */
interface CachesPaths
{
    /** @return list<string> CloudFront path patterns, e.g. "/build/*" */
    public function cachedPaths(): array;
}
