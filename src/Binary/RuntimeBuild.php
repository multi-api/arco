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
        return (int) ($m[2] ?? 0);
    }
}
