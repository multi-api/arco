<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Config\ArcoFileEditor;

/** The resources `arco init` writes on the App chain, from its questions or flags. */
final class InitChoices
{
    /**
     * @param ?int $scheduler minutes between schedule:run invocations; null: no scheduler
     * @param ?int $logRetention days; null: not written (CloudWatch keeps logs forever)
     * @param list<string> $queues
     * @param ?string $storage the storage name; null: none
     */
    public function __construct(
        public readonly bool $console = false,
        public readonly ?int $scheduler = null,
        public readonly ?int $logRetention = null,
        public readonly array $queues = [],
        public readonly bool $failedJobsTable = false,
        public readonly ?string $storage = null,
        public readonly bool $cache = false,
    ) {
    }

    /**
     * The App chain lines, in the order init writes them. console(false) is never written: off is the default.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];
        if ($this->console) {
            $lines[] = ArcoFileEditor::settingLine('console', true);
        }
        if ($this->scheduler !== null) {
            $lines[] = ArcoFileEditor::settingLine('scheduler', $this->scheduler);
        }
        if ($this->logRetention !== null) {
            $lines[] = ArcoFileEditor::settingLine('logRetention', $this->logRetention);
        }
        foreach ($this->queues as $queue) {
            $lines[] = ArcoFileEditor::namedLine('queue', $queue, []);
        }
        if ($this->failedJobsTable) {
            $lines[] = ArcoFileEditor::singletonLine('failedJobsTable', true);
        }
        if ($this->storage !== null) {
            $lines[] = ArcoFileEditor::namedLine('storage', $this->storage, []);
        }
        if ($this->cache) {
            $lines[] = ArcoFileEditor::singletonLine('cache', true);
        }
        return $lines;
    }
}
