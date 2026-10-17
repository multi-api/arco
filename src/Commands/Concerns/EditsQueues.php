<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\QueueConfig;
use Symfony\Component\Console\Input\InputInterface;

/** Shared by queue:add, queue:set and queue:remove: EditsNamedResources for the queue() calls. */
trait EditsQueues
{
    use EditsNamedResources;

    /** @return array{method: 'queue', key: string, errors: list<string>} */
    private static function resource(): array
    {
        return ['method' => 'queue', 'key' => 'queues', 'errors' => ['validation.queue', 'validation.worker_name_length']];
    }

    /** @return list<QueueConfig> */
    private static function resolvedEntries(AppConfig $config): array
    {
        return $config->queues;
    }

    /**
     * The queue() calls written at exactly this level: options, or false for a queue dropped there.
     *
     * @param array<string, mixed> $raw
     * @return array<string, array<string, int|bool|string>|false>
     */
    private static function levelQueues(array $raw, ?string $environment): array
    {
        return array_map(
            static fn (array|false $options): array|false => $options === false ? false : array_filter($options, static fn (mixed $v): bool => ! is_array($v)),
            self::levelNamed($raw, $environment),
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, int|bool|string>|false|null $options
     * @return array<string, mixed>
     */
    private static function withQueue(array $raw, ?string $environment, string $name, array|false|null $options): array
    {
        return self::withNamed($raw, $environment, $name, $options);
    }

    /** @param array<string, mixed> $raw */
    private static function resolvedQueue(array $raw, string $environment, string $name): ?QueueConfig
    {
        $queue = self::resolvedNamed($raw, $environment, $name);
        return $queue instanceof QueueConfig ? $queue : null;
    }

    /** @param array<string, mixed> $raw */
    private static function appLevelQueue(array $raw, string $name): ?QueueConfig
    {
        $queue = self::appLevelNamed($raw, $name);
        return $queue instanceof QueueConfig ? $queue : null;
    }

    /** @param array<string, int|bool|string>|false|null $options */
    private function writeQueue(?string $environment, string $name, array|false|null $options): void
    {
        $this->writeNamed($environment, $name, $options);
    }

    private function queueName(InputInterface $input): string
    {
        return $this->argument($input, 'name');
    }
}
