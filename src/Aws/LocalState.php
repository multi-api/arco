<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

final class LocalState
{
    private string $path;

    public function __construct(string $projectDir)
    {
        $this->path = $projectDir . '/.arco/local.php';
    }

    public function profile(string $environment): ?string
    {
        $profiles = $this->read()['profiles'] ?? [];
        return $profiles[$environment] ?? null;
    }

    public function rememberProfile(string $environment, string $profile): void
    {
        $state = $this->read();
        $state['profiles'][$environment] = $profile;
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0777, true);
        }
        file_put_contents($this->path, "<?php\n\n// Local Arco state for this machine. Do not commit.\nreturn " . var_export($state, true) . ";\n");
    }

    /** @return array{profiles?: array<string, string>} */
    private function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $state = require $this->path;
        if (! is_array($state)) {
            return [];
        }
        /** @var array{profiles?: array<string, string>} $state */
        return $state;
    }
}
