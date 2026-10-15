<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

use LogicException;
use Symfony\Component\Console\Command\Command;

abstract class ArcoCommand extends Command
{
    /** @param array<string, string|int> $params */
    protected function t(string $key, array $params = []): string
    {
        $app = $this->getApplication();
        if (! $app instanceof Application) {
            throw new LogicException('Arco commands must run inside the Arco application.');
        }
        return $app->translator()->trans($key, $params);
    }

    protected function projectDir(): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new LogicException('Cannot determine the current directory.');
        }
        return $cwd;
    }
}
