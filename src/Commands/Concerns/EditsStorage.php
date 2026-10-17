<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\StorageConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared by the storage:* commands: EditsNamedResources for the storage() calls, and the edit of one
 * option (public, expire or cors) of an existing storage at the target level.
 */
trait EditsStorage
{
    use EditsNamedResources;

    /** @return array{method: 'storage', key: string, errors: list<string>} */
    private static function resource(): array
    {
        return ['method' => 'storage', 'key' => 'storage', 'errors' => ['validation.storage', 'validation.bucket_name_length']];
    }

    /** @return list<StorageConfig> */
    private static function resolvedEntries(AppConfig $config): array
    {
        return $config->storage;
    }

    /** @param array<string, mixed> $raw */
    private static function storageAt(array $raw, ?string $environment, string $name): ?StorageConfig
    {
        $storage = $environment === null ? self::appLevelNamed($raw, $name) : self::resolvedNamed($raw, $environment, $name);
        return $storage instanceof StorageConfig ? $storage : null;
    }

    /**
     * Changes one option of an existing storage at the target level. $change receives the option's
     * current value (as the target level sees it) and returns the new one, or null when the entry to
     * remove is not there. An environment inheriting the storage gets an override with the whole new
     * value, since an environment's option replaces the app's.
     *
     * @param 'public'|'expire'|'cors' $option
     * @param callable(array<array-key, int|string>): (array<array-key, int|string>|null) $change
     */
    private function changeStorageOption(InputInterface $input, SymfonyStyle $io, string $option, string $entry, callable $change): int
    {
        $name = $this->argument($input, 'name');
        [$raw, $environment] = $this->target($input, $io);
        $before = self::storageAt($raw, $environment, $name)
            ?? throw new UserError($this->t('storage.not_found', ['storage' => $name, 'where' => $this->where($environment)]));
        $current = $before->{$option};
        $new = $change($current);
        if ($new === null) {
            $io->note($this->t('storage.entry_missing', ['storage' => $name, 'option' => $option, 'value' => $entry, 'where' => $this->where($environment)]));
            return self::SUCCESS;
        }
        if (self::sameValue($current, $new)) {
            $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
            return self::SUCCESS;
        }

        $level = self::levelNamed($raw, $environment)[$name] ?? [];
        $options = is_array($level) ? $level : [];
        unset($options[$option]);
        $after = self::storageAt(self::withNamed($raw, $environment, $name, $options), $environment, $name);
        if ($after === null || ! self::sameValue($after->{$option}, $new)) {
            $options[$option] = $new;
        }
        // An environment that only inherits the storage needs no call of its own.
        $write = $options === [] && $environment !== null && is_array(self::levelNamed($raw, null)[$name] ?? null) ? null : $options;
        $changed = self::withNamed($raw, $environment, $name, $write);
        $this->assertValid($changed, $environment);
        $this->writeNamed($environment, $name, $write);

        $io->success($this->t('storage.changed', [
            'storage' => $name,
            'environment' => $this->levelLabel($environment),
            'option' => $option,
            'from' => $this->showOption($current),
            'to' => $this->showOption($new),
        ]));
        $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
        $io->writeln('    ' . ($write === null ? $this->t('settings.remove_call', ['method' => 'storage']) : self::namedLine($name, $write)));
        $bucket = self::storageAt($changed, $environment, $name)?->existing;
        if ($bucket !== null && $new !== []) {
            // Arco manages no policy, lifecycle or CORS on a bucket it did not create.
            $io->warning($this->t('validation.storage_existing_options', ['storage' => $name, 'options' => $option, 'bucket' => $bucket]));
        }
        $this->printDeployNext($io, $raw, $environment);
        return self::SUCCESS;
    }

    /**
     * Whether two option values are the same, ignoring order: a list of prefixes or origins, or a map of prefix to days.
     *
     * @param array<array-key, int|string> $a
     * @param array<array-key, int|string> $b
     */
    private static function sameValue(array $a, array $b): bool
    {
        if (array_is_list($a) && array_is_list($b)) {
            sort($a);
            sort($b);
        } else {
            ksort($a);
            ksort($b);
        }
        return $a === $b;
    }

    /** @param array<array-key, int|string> $value */
    private function showOption(array $value): string
    {
        if ($value === []) {
            return $this->t('storage.none');
        }
        if (array_is_list($value)) {
            return implode(', ', array_map(strval(...), $value));
        }
        $rules = [];
        foreach ($value as $prefix => $days) {
            $rules[] = $this->expireRule((string) $prefix, (int) $days);
        }
        return implode(', ', $rules);
    }
}
