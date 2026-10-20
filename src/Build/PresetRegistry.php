<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

use Composer\Autoload\ClassLoader;
use ReflectionClass;

/**
 * @internal Not covered by the 1.x compatibility promise.
 */
final class PresetRegistry
{
    /** First-party presets known even when installed from the monorepo (replaced packages). */
    private const KNOWN = ['Multiapi\\Arco\\Laravel\\LaravelPreset'];

    /** @param list<FrameworkPreset> $presets */
    public function __construct(private readonly array $presets)
    {
    }

    public static function default(): self
    {
        $classes = self::KNOWN;
        $installed = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName()) . '/installed.json';
        if (is_file($installed)) {
            /** @var array{packages?: list<array{extra?: array{arco?: array{presets?: list<string>}}}>} $data */
            $data = json_decode((string) file_get_contents($installed), true) ?: [];
            foreach ($data['packages'] ?? [] as $package) {
                array_push($classes, ...($package['extra']['arco']['presets'] ?? []));
            }
        }

        $presets = [];
        foreach (array_unique($classes) as $class) {
            if (class_exists($class) && is_subclass_of($class, FrameworkPreset::class)) {
                $presets[] = new $class();
            }
        }
        return new self($presets);
    }

    public function detect(string $projectDir): FrameworkPreset
    {
        foreach ($this->presets as $preset) {
            if ($preset->detects($projectDir)) {
                return $preset;
            }
        }
        return new PlainPhpPreset();
    }
}
