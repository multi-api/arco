<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\GenericTableConfig;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Config\StorageConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared by the commands that edit calls keyed by a name, such as ->queue('<name>', ...) and
 * ->storage('<name>', ...) or ->table('<name>', ...): the calls of one level, where a name is configured, validation and the
 * arco.php edit. The using trait names the call through resource().
 *
 * @phpstan-import-type Option from ArcoFileEditor
 */
trait EditsNamedResources
{
    use EditsSettings;

    /**
     * The call this command edits: its method, the raw config key it loads into, and the validation
     * error keys (or key prefixes) that concern it.
     *
     * @return array{method: 'queue'|'storage'|'table', key: string, errors: list<string>}
     */
    abstract private static function resource(): array;

    /**
     * The resolved entries of this call in one environment.
     *
     * @return list<QueueConfig|StorageConfig|GenericTableConfig>
     */
    abstract private static function resolvedEntries(AppConfig $config): array;

    /**
     * The calls written at exactly this level: options, or false for an entry dropped there.
     *
     * @param array<string, mixed> $raw
     * @return array<string, array<string, Option>|false>
     */
    private static function levelNamed(array $raw, ?string $environment): array
    {
        $level = $raw;
        if ($environment !== null) {
            $environments = $raw['environments'] ?? null;
            $level = is_array($environments) && is_array($environments[$environment] ?? null) ? $environments[$environment] : [];
        }
        $entries = $level[self::resource()['key']] ?? null;
        $result = [];
        foreach (is_array($entries) ? $entries : [] as $name => $options) {
            if ($options === false) {
                $result[(string) $name] = false;
            } elseif (is_array($options)) {
                $clean = [];
                foreach ($options as $key => $value) {
                    if (is_int($value) || is_bool($value) || is_string($value)) {
                        $clean[(string) $key] = $value;
                    } elseif (is_array($value)) {
                        $clean[(string) $key] = array_filter($value, static fn (mixed $item): bool => is_int($item) || is_string($item));
                    }
                }
                $result[(string) $name] = $clean;
            }
        }
        return $result;
    }

    /**
     * The raw config with the call at this level replaced (null removes it).
     *
     * @param array<string, mixed> $raw
     * @param array<string, Option>|false|null $options
     * @return array<string, mixed>
     */
    private static function withNamed(array $raw, ?string $environment, string $name, array|false|null $options): array
    {
        $key = self::resource()['key'];
        $entries = self::levelNamed($raw, $environment);
        if ($options === null) {
            unset($entries[$name]);
        } else {
            $entries[$name] = $options;
        }
        if ($environment === null) {
            $raw[$key] = $entries;
            return $raw;
        }
        $environments = is_array($raw['environments'] ?? null) ? $raw['environments'] : [];
        $env = is_array($environments[$environment] ?? null) ? $environments[$environment] : [];
        $env[$key] = $entries;
        $environments[$environment] = $env;
        $raw['environments'] = $environments;
        return $raw;
    }

    /**
     * The environments a change at this level reaches.
     *
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    private static function affectedEnvironments(array $raw, ?string $environment): array
    {
        return $environment === null ? ConfigResolver::environments($raw) : [$environment];
    }

    /** @param array<string, mixed> $raw */
    private static function resolvedNamed(array $raw, string $environment, string $name): QueueConfig|StorageConfig|GenericTableConfig|null
    {
        foreach (self::resolvedEntries(ConfigResolver::resolve($raw, $environment)) as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * The entry as the app level alone defines it: every environment's own call for it is ignored, so an
     * environment override never hides what a change at the app level does.
     *
     * @param array<string, mixed> $raw
     */
    private static function appLevelNamed(array $raw, string $name): QueueConfig|StorageConfig|GenericTableConfig|null
    {
        $environments = ConfigResolver::environments($raw);
        if (! is_array(self::levelNamed($raw, null)[$name] ?? null) || $environments === []) {
            return null;
        }
        foreach ($environments as $env) {
            $raw = self::withNamed($raw, $env, $name, null);
        }
        return self::resolvedNamed($raw, $environments[0], $name);
    }

    /**
     * Where the entry is already configured: the target level, and for the app level also every
     * environment. Returns the environment name, null for the app level, or false when it is nowhere.
     *
     * @param array<string, mixed> $raw
     */
    private static function existsAt(array $raw, ?string $environment, string $name): string|false|null
    {
        if ($environment !== null) {
            return self::resolvedNamed($raw, $environment, $name) !== null ? $environment : false;
        }
        if (is_array(self::levelNamed($raw, null)[$name] ?? null)) {
            return null;
        }
        foreach (ConfigResolver::environments($raw) as $env) {
            if (self::resolvedNamed($raw, $env, $name) !== null) {
                return $env;
            }
        }
        return false;
    }

    /**
     * Stops with the errors about this call the change would cause (or that the validator finds), so arco.php stays as it is.
     *
     * @param array<string, mixed> $changed
     */
    private function assertValid(array $changed, ?string $environment): void
    {
        $this->assertNoErrors($changed, $environment, self::resource()['errors']);
    }

    /**
     * Writes the call (false drops an inherited entry, null removes the call), or explains the line to write by hand.
     *
     * @param array<string, Option>|false|null $options
     */
    private function writeNamed(?string $environment, string $name, array|false|null $options): void
    {
        $method = self::resource()['method'];
        $this->writeOrExplain(
            $environment,
            $options === null ? $this->t('settings.remove_call', ['method' => $method]) : self::namedLine($name, $options),
            fn (): bool => ArcoFileEditor::setNamed($this->arcoPhp(), $environment, $method, $name, $options),
        );
    }

    /**
     * Adds the entry named by the `name` argument at the target level, unless it is already configured
     * there (or, for the app level, in any environment). $options builds its options once that is known.
     *
     * @param callable(): array<string, Option> $options
     */
    private function addNamed(InputInterface $input, SymfonyStyle $io, callable $options): int
    {
        $method = self::resource()['method'];
        $name = $this->argument($input, 'name');
        [$raw, $environment] = $this->target($input, $io);
        $found = self::existsAt($raw, $environment, $name);
        if ($found !== false) {
            throw new UserError($this->t("$method.exists", [$method => $name, 'where' => $this->where($found)]));
        }
        $options = $options();
        $this->assertValid(self::withNamed($raw, $environment, $name, $options), $environment);
        $this->writeNamed($environment, $name, $options);
        $io->success($this->t("$method.added", [$method => $name, 'environment' => $this->levelLabel($environment)]));
        $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
        $io->writeln('    ' . self::namedLine($name, $options));
        $this->printDeployNext($io, $raw, $environment);
        return self::SUCCESS;
    }

    /**
     * Removes the entry named by the `name` argument at the target level: the call at its own level, or
     * ->method('<name>', false) in an environment that inherits it from the app. What it created in AWS
     * stays there. An app-level removal names the environments whose own call still declares it.
     */
    private function removeNamed(InputInterface $input, SymfonyStyle $io): int
    {
        $method = self::resource()['method'];
        $name = $this->argument($input, 'name');
        [$raw, $environment] = $this->target($input, $io);
        $level = self::levelNamed($raw, $environment);
        $inherited = $environment !== null && is_array(self::levelNamed($raw, null)[$name] ?? null);
        if ($inherited) {
            // The app level defines it: only an environment-level false takes it out of this environment.
            if (($level[$name] ?? null) === false) {
                $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            $change = false;
        } elseif (is_array($level[$name] ?? null)) {
            $change = null;
        } else {
            throw new UserError($this->t("$method.not_found", [$method => $name, 'where' => $this->where($environment)]));
        }
        $this->assertValid(self::withNamed($raw, $environment, $name, $change), $environment);
        $this->writeNamed($environment, $name, $change);
        $io->success($this->t("$method.removed", [$method => $name, 'environment' => $this->levelLabel($environment)]));
        $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
        $io->writeln('    ' . ($change === false ? self::namedLine($name, false) : $this->t('settings.remove_call', ['method' => $method])));
        $io->writeln($this->t("$method.retained_note", [$method => $name]));
        if ($environment === null) {
            foreach (ConfigResolver::environments($raw) as $env) {
                if (is_array(self::levelNamed($raw, $env)[$name] ?? null)) {
                    $io->warning($this->t('settings.still_defined', ['environment' => $env, 'method' => $method, 'name' => $name]));
                }
            }
        }
        $this->printDeployNext($io, $raw, $environment);
        return self::SUCCESS;
    }

    /** @param array<string, Option>|false $options */
    private static function namedLine(string $name, array|false $options): string
    {
        return ArcoFileEditor::namedLine(self::resource()['method'], $name, $options);
    }
}
