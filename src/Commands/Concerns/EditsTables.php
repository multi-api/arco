<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigResolver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared by cache:add, cache:remove and queue:failed-table: turning a DynamoDB table setting
 * (->cache() or ->failedJobsTable()) on or off at the target level.
 */
trait EditsTables
{
    use EditsSettings;

    /**
     * Turns the table on (Arco's own, or the --existing one) at the target level.
     *
     * @param 'cache'|'failedJobsTable' $method
     */
    private function addTable(InputInterface $input, SymfonyStyle $io, string $method): int
    {
        $existing = $input->getOption('existing');
        $existing = is_string($existing) && $existing !== '' ? $existing : null;
        $value = $existing !== null ? ['existing' => $existing] : true;
        [$raw, $environment] = $this->target($input, $io);
        $before = self::tableState($raw, $environment, $method);
        $after = $existing ?? true;
        if ($before === $after) {
            $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
            return self::SUCCESS;
        }
        $this->assertNoErrors(self::withTable($raw, $environment, $method, $value), $environment, ['validation.table_existing']);
        $this->writeTable($environment, $method, $value);
        $this->printTableChange($io, $raw, $environment, $method, $before, $after, ArcoFileEditor::singletonLine($method, $value));
        return self::SUCCESS;
    }

    /**
     * Turns the table off at the target level: ->method(false) when it would still be inherited from the
     * app level, otherwise the call is removed. The table itself stays in AWS.
     *
     * @param 'cache'|'failedJobsTable' $method
     */
    private function removeTable(InputInterface $input, SymfonyStyle $io, string $method): int
    {
        [$raw, $environment] = $this->target($input, $io);
        $before = self::tableState($raw, $environment, $method);
        if ($before === false) {
            $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
            return self::SUCCESS;
        }
        $change = $environment !== null && self::tableState($raw, null, $method) !== false ? false : null;
        $this->assertNoErrors(self::withTable($raw, $environment, $method, $change), $environment, ['validation.table_existing']);
        $this->writeTable($environment, $method, $change);
        $this->printTableChange($io, $raw, $environment, $method, $before, false, $change === false
            ? ArcoFileEditor::singletonLine($method, false)
            : $this->t('settings.remove_call', ['method' => $method]));
        $io->writeln($this->t('table.retained_note'));
        return self::SUCCESS;
    }

    /**
     * The table at this level: false when off, true for the table Arco creates, or the existing table's
     * name. The app level reads its own call; an environment reads what it resolves to.
     *
     * @param array<string, mixed> $raw
     * @param 'cache'|'failedJobsTable' $method
     */
    private static function tableState(array $raw, ?string $environment, string $method): string|bool
    {
        if ($environment === null) {
            $value = self::levelValue($raw, null, $method);
            return match (true) {
                $value === true => true,
                is_array($value) && isset($value['existing']) && is_scalar($value['existing']) => (string) $value['existing'],
                default => false,
            };
        }
        $config = ConfigResolver::resolve($raw, $environment);
        $table = $method === 'cache' ? $config->cacheTable : $config->failedJobsTable;
        return $table === null ? false : ($table->existing ?? true);
    }

    /**
     * The raw config with the setting at this level replaced (null removes it).
     *
     * @param array<string, mixed> $raw
     * @param array{existing: string}|bool|null $value
     * @return array<string, mixed>
     */
    private static function withTable(array $raw, ?string $environment, string $method, array|bool|null $value): array
    {
        $level = $raw;
        if ($environment !== null) {
            $environments = is_array($raw['environments'] ?? null) ? $raw['environments'] : [];
            $level = is_array($environments[$environment] ?? null) ? $environments[$environment] : [];
        }
        $settings = is_array($level['settings'] ?? null) ? $level['settings'] : [];
        if ($value === null) {
            unset($settings[$method]);
        } else {
            $settings[$method] = $value;
        }
        if ($environment === null) {
            $raw['settings'] = $settings;
            return $raw;
        }
        $level['settings'] = $settings;
        $environments = is_array($raw['environments'] ?? null) ? $raw['environments'] : [];
        $environments[$environment] = $level;
        $raw['environments'] = $environments;
        return $raw;
    }

    /**
     * Writes the call (or removes it when $value is null), or explains the line to change by hand.
     *
     * @param array{existing: string}|bool|null $value
     */
    private function writeTable(?string $environment, string $method, array|bool|null $value): void
    {
        $this->writeOrExplain(
            $environment,
            $value === null ? $this->t('settings.remove_call', ['method' => $method]) : ArcoFileEditor::singletonLine($method, $value),
            fn (): bool => ArcoFileEditor::setSingleton($this->arcoPhp(), $environment, $method, $value),
        );
    }

    /** @param array<string, mixed> $raw */
    private function printTableChange(SymfonyStyle $io, array $raw, ?string $environment, string $method, string|bool $from, string|bool $to, string $line): void
    {
        $io->success($this->t('table.changed', [
            'table' => $this->t($method === 'cache' ? 'table.cache' : 'table.failed_jobs'),
            'environment' => $this->levelLabel($environment),
            'from' => $this->showTable($from),
            'to' => $this->showTable($to),
        ]));
        $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
        $io->writeln('    ' . $line);
        $this->printDeployNext($io, $raw, $environment);
    }

    private function showTable(string|bool $state): string
    {
        return match (true) {
            $state === false => $this->t('table.off'),
            $state === true => $this->t('table.on'),
            default => $this->t('table.existing', ['name' => $state]),
        };
    }
}
