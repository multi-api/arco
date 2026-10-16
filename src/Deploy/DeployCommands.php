<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\ConsoleRuntime\ArgumentSplitter;

/** The deploy commands of one deploy: arco.php's deployCommands() adjusted by --migrate / --no-deploy-commands. */
final class DeployCommands
{
    public const MIGRATE = 'migrate --force';

    /**
     * @param list<string> $configured
     * @return list<string>
     */
    public static function resolve(array $configured, bool $migrate, bool $skip): array
    {
        if ($skip) {
            return [];
        }
        if ($migrate && ! self::hasMigrate($configured)) {
            return [self::MIGRATE, ...$configured];
        }
        return $configured;
    }

    /**
     * Whether a command runs `migrate` itself (not migrate:status or migrate:rollback).
     *
     * @param list<string> $commands
     */
    public static function hasMigrate(array $commands): bool
    {
        foreach ($commands as $command) {
            try {
                if ((ArgumentSplitter::split($command)[0] ?? null) === 'migrate') {
                    return true;
                }
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        return false;
    }
}
