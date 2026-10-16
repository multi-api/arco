<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/** Keeps the last $limit bytes of a command's output; earlier output is replaced by a marker. */
final class OutputBuffer
{
    public const LIMIT = 5 * 1024 * 1024;
    public const MARKER = "[truncated]\n";

    private string $data = '';
    private bool $dropped = false;

    public function __construct(private readonly int $limit = self::LIMIT)
    {
    }

    public function append(string $chunk): void
    {
        $this->data .= $chunk;
        // Trimmed in batches: cutting on every chunk would copy the whole buffer each time.
        if (strlen($this->data) > 2 * $this->limit) {
            $this->data = substr($this->data, -$this->limit);
            $this->dropped = true;
        }
    }

    public function truncated(): bool
    {
        return $this->dropped || strlen($this->data) > $this->limit;
    }

    public function contents(): string
    {
        return $this->truncated() ? self::MARKER . substr($this->data, -$this->limit) : $this->data;
    }
}
