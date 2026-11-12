<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

use LogicException;
use Multiapi\Arco\Config\ProjectConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class ArcoCommand extends Command
{
    /**
     * A command for Laravel apps only (console, tinker, the arco.php editors, ...) is refused in a process
     * project before its input is read or anything runs.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        if ($this instanceof LaravelOnly) {
            $refusal = $this->refuseInProcessProject((string) $this->getName());
            if ($refusal !== null) {
                // None of the refused commands has --json today. One that gains it must print the refusal as its
                // JSON error document, with the key config.process_unsupported_command ($refusal->key), not this text.
                (new SymfonyStyle($input, $output))->getErrorStyle()->error($refusal->getMessage());
                return self::FAILURE;
            }
        }
        return parent::run($input, $output);
    }

    /**
     * The error for `arco <command>` in a process project (arco.json, or a go.mod alone); null in a PHP project. With
     * both arco.php and arco.json it is config.both_files, as a config load gives: the arco.php editors read arco.php
     * directly and would otherwise edit it next to an arco.json.
     */
    protected function refuseInProcessProject(string $command): ?UserError
    {
        $dir = $this->projectDir();
        return match (true) {
            ! ProjectConfig::isProcess($dir) => null,
            is_file($dir . '/arco.php') => $this->userError('config.both_files'),
            default => $this->userError('config.process_unsupported_command', ['command' => $command]),
        };
    }

    /** @param array<string, string|int> $params */
    protected function t(string $key, array $params = []): string
    {
        $app = $this->getApplication();
        if (! $app instanceof Application) {
            throw new LogicException('Arco commands must run inside the Arco application.');
        }
        return $app->translator()->trans($key, $params);
    }

    /**
     * A UserError with its message translated from $key; the key goes with it for --json output.
     *
     * @param array<string, string|int> $params
     */
    protected function userError(string $key, array $params = []): UserError
    {
        return new UserError($this->t($key, $params), $key);
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
