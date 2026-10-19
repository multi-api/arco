<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/** One SQS queue Arco manages (or references, when `existing` is set) and the worker function that consumes it. */
final readonly class QueueConfig
{
    private const SQS_NAME = '/^[A-Za-z0-9_-]{1,80}(\.fifo)?$/';
    private const SQS_ARN = '/^arn:aws[a-z-]*:sqs:([a-z0-9-]+):(\d{12}):([A-Za-z0-9_.-]+)$/';
    private const SQS_URL = '#^https://sqs\.([a-z0-9-]+)\.amazonaws\.com[a-z.]*/(\d{12})/([A-Za-z0-9_.-]+)$#';

    public function __construct(
        public string $name,
        public int $timeout = 60,
        public int $memory = 1024,
        /** null: no maximum concurrency on the event source mapping */
        public ?int $concurrency = null,
        public int $batchSize = 1,
        public int $tries = 3,
        /** a dead-letter queue is created next to a queue Arco owns; never for an existing one */
        public bool $dlq = true,
        public bool $fifo = false,
        /** name, URL or ARN of a queue Arco does not manage */
        public ?string $existing = null,
        public bool $default = false,
    ) {
    }

    /**
     * Region, account and queue name of an `existing` reference, or null when it is not a name, URL or ARN.
     *
     * @return array{region: string|null, account: string|null, name: string}|null
     */
    public static function parseExisting(string $reference): ?array
    {
        if (preg_match(self::SQS_ARN, $reference, $m) === 1 || preg_match(self::SQS_URL, $reference, $m) === 1) {
            return ['region' => $m[1], 'account' => $m[2], 'name' => $m[3]];
        }
        if (preg_match(self::SQS_NAME, $reference) === 1) {
            return ['region' => null, 'account' => null, 'name' => $reference];
        }
        return null;
    }

    public function physicalName(AppConfig $config): string
    {
        if ($this->existing !== null) {
            return self::parseExisting($this->existing)['name'] ?? $this->existing;
        }
        return self::managedName($config->app, $config->environment, $this->name, false, $this->fifo);
    }

    /** The name of an SQS queue Arco creates: <app>-<environment>-<queue>[-dlq][.fifo]. */
    public static function managedName(string $app, string $environment, string $queue, bool $dlq = false, bool $fifo = false): string
    {
        return $app . '-' . $environment . '-' . $queue . ($dlq ? '-dlq' : '') . ($fifo ? '.fifo' : '');
    }

    public function dlqName(AppConfig $config): ?string
    {
        if ($this->existing !== null || ! $this->dlq) {
            return null;
        }
        return self::managedName($config->app, $config->environment, $this->name, true, $this->fifo);
    }

    public function url(AppConfig $config): string
    {
        [$region, $account] = $this->location($config);
        return "https://sqs.$region.amazonaws.com/$account/" . $this->physicalName($config);
    }

    public function arn(AppConfig $config): string
    {
        [$region, $account] = $this->location($config);
        return "arn:aws:sqs:$region:$account:" . $this->physicalName($config);
    }

    public function dlqUrl(AppConfig $config): ?string
    {
        $name = $this->dlqName($config);
        if ($name === null) {
            return null;
        }
        [$region, $account] = $this->location($config);
        return "https://sqs.$region.amazonaws.com/$account/$name";
    }

    public function dlqArn(AppConfig $config): ?string
    {
        $name = $this->dlqName($config);
        if ($name === null) {
            return null;
        }
        [$region, $account] = $this->location($config);
        return "arn:aws:sqs:$region:$account:$name";
    }

    /** @return array{string, string} */
    private function location(AppConfig $config): array
    {
        $parsed = $this->existing !== null ? self::parseExisting($this->existing) : null;
        return [$parsed['region'] ?? $config->region ?? '', $parsed['account'] ?? $config->account ?? ''];
    }
}
