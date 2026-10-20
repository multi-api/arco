<?php declare(strict_types=1);

namespace Multiapi\Arco\Binary;

final readonly class RuntimeBuild
{
    public function __construct(
        public string $id,
        public string $url,
        public string $sha256,
        public int $size,
        public string $frankenphp,
        public string $php,
        public ?PhpCli $phpCli = null,
    ) {
    }

    public function revision(): int
    {
        preg_match(RuntimeManifest::ID_PATTERN, $this->id, $m);
        return (int) ($m[3] ?? 0);
    }

    /** The PHP minor version, such as 8.5 for PHP 8.5.11. */
    public function phpMinor(): string
    {
        return implode('.', array_slice(explode('.', $this->php), 0, 2));
    }
}
