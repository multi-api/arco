<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\AppConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The confirmation protected() asks before a command touches a protected environment's live data.
 * `--yes` answers it; without a terminal there is nobody to ask, so `--yes` is required.
 *
 * @phpstan-require-extends \Multiapi\Arco\Cli\ArcoCommand
 */
trait ConfirmsProtected
{
    /**
     * @param string $action one of tinker, command, redrive, remove (selects the lang keys)
     * @param array<string, int|string> $params extra placeholders for the question
     * @return bool false when the user declined; true when it may go on (not protected, --yes, or yes)
     */
    private function confirmProtected(InputInterface $input, SymfonyStyle $io, AppConfig $config, string $action, ?AwsClients $aws = null, array $params = []): bool
    {
        if (! $config->isProtected() || $input->getOption('yes')) {
            return true;
        }
        if (! $input->isInteractive()) {
            throw $this->protectedNeedsYes($config, $action);
        }
        $params += [
            'environment' => $config->environment,
            'account' => $aws?->accountId() ?? $config->account ?? '-',
            'function' => $config->consoleFunctionName(),
        ];
        if ($io->confirm($this->t('protected.confirm.' . $action, $params), false)) {
            return true;
        }
        $io->writeln($this->t('common.aborted'));
        return false;
    }

    private function protectedNeedsYes(AppConfig $config, string $action): UserError
    {
        return $this->userError('protected.needs_yes', [
            'environment' => $config->environment,
            'action' => $this->t('protected.action.' . $action),
        ]);
    }
}
