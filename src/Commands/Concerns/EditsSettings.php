<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Shared by `arco set` and `arco unset`: the CLI keys, the target (--env or --app) and the arco.php edit. */
trait EditsSettings
{
    /** CLI key => arco.php method (and resolved setting key). */
    private const KEYS = [
        'concurrency' => 'concurrency',
        'max-instances' => 'maxInstances',
        'rate-limit' => 'rateLimit',
        'timeout' => 'timeout',
        'log-level' => 'logLevel',
    ];

    private function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);
        return is_string($value) ? $value : '';
    }

    private function addTargetOptions(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment to change')
            ->addOption('app', null, InputOption::VALUE_NONE, 'Change the app level (all environments)');
    }

    private function method(InputInterface $input): string
    {
        $key = $this->argument($input, 'key');
        return self::KEYS[$key] ?? throw new UserError($this->t('settings.unknown_key', [
            'key' => $key,
            'keys' => implode(', ', array_keys(self::KEYS)),
        ]));
    }

    /**
     * The loaded arco.php and the environment to change, or null for the app level.
     *
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function target(InputInterface $input, SymfonyStyle $io): array
    {
        $env = $input->getOption('env');
        $env = is_string($env) && $env !== '' ? $env : null;
        $app = (bool) $input->getOption('app');
        if ($env !== null && $app) {
            throw new UserError($this->t('settings.one_target'));
        }
        $raw = (new ConfigLoader())->load($this->arcoPhp());
        if ($app) {
            return [$raw, null];
        }
        $environments = ConfigResolver::environments($raw);
        if ($env === null) {
            if (count($environments) !== 1) {
                throw new UserError($this->t('settings.choose_target', ['available' => implode(', ', $environments)]));
            }
            $env = $environments[0];
            $io->note($this->t('settings.only_environment', ['environment' => $env]));
        }
        if (! in_array($env, $environments, true)) {
            throw new UserError($this->t('config.unknown_environment', ['environment' => $env, 'available' => implode(', ', $environments)]));
        }
        return [$raw, $env];
    }

    /**
     * The value set at exactly this level (not inherited), or null when there is none.
     *
     * @param array<string, mixed> $raw
     */
    private static function levelValue(array $raw, ?string $environment, string $method): mixed
    {
        $level = $raw;
        if ($environment !== null) {
            $environments = $raw['environments'] ?? null;
            $level = is_array($environments) && is_array($environments[$environment] ?? null) ? $environments[$environment] : [];
        }
        $settings = $level['settings'] ?? null;
        return is_array($settings) ? $settings[$method] ?? null : null;
    }

    /**
     * Whether an environment the change applies to leaves the console setting unset. The scheduler commands
     * then write `->console()` too, so turning the scheduler off later keeps the console (spec §5) and the
     * deploy never meets an existing console with no setting.
     *
     * @param array<string, mixed> $raw
     */
    private static function consoleUnset(array $raw, ?string $environment): bool
    {
        foreach ($environment === null ? ConfigResolver::environments($raw) : [$environment] as $env) {
            if (ConfigResolver::resolve($raw, $env)->console === null) {
                return true;
            }
        }
        return false;
    }

    private function where(?string $environment): string
    {
        return $environment === null ? $this->t('settings.where_app') : $this->t('settings.where_environment', ['environment' => $environment]);
    }

    /** Writes the call (or removes it when $value is null), or explains the line to change by hand. */
    private function writeSetting(?string $environment, string $method, int|string|bool|null $value): void
    {
        $byHand = $this->t('settings.edit_by_hand', ['where' => $this->where($environment)]) . "\n    "
            . ($value === null ? $this->t('settings.remove_call', ['method' => $method]) : ArcoFileEditor::settingLine($method, $value));
        try {
            $written = ArcoFileEditor::setSetting($this->arcoPhp(), $environment, $method, $value);
        } catch (ConfigException $e) {
            throw new UserError($this->t($e->key, $e->params) . "\n" . $byHand);
        }
        if (! $written) {
            throw new UserError($byHand);
        }
    }

    /** @param array<string, mixed> $raw */
    private function printDeployNext(SymfonyStyle $io, array $raw, ?string $environment): void
    {
        $io->newLine();
        if ($environment !== null) {
            $io->writeln($this->t('domain.deploy_next', ['environment' => $environment]));
            return;
        }
        $io->writeln($this->t('settings.deploy_next_app', ['environments' => implode(', ', array_map(
            fn (string $env) => "`arco deploy --env=$env`",
            ConfigResolver::environments($raw),
        ))]));
    }

    /** The environment name, or the app level's label ("app"): how the change lines name where they apply. */
    private function levelLabel(?string $environment): string
    {
        return $environment ?? $this->t('settings.level_app');
    }

    private function arcoPhp(): string
    {
        return $this->projectDir() . '/arco.php';
    }
}
