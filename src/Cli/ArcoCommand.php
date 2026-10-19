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

    /** One expire rule line, with "1 day" in the singular. */
    protected function expireRule(string $prefix, int $days): string
    {
        return $days === 1
            ? $this->t('storage.expire_rule_one_day', ['prefix' => $prefix])
            : $this->t('storage.expire_rule', ['prefix' => $prefix, 'days' => $days]);
    }

    /** A plain integer argument as an int; anything else stays text, for the validator to reject. */
    protected static function intOrText(string $value): int|string
    {
        return preg_match('/^-?\d{1,9}$/', $value) === 1 ? (int) $value : $value;
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
