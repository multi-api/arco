<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigException;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Writes an environment's domain()/dns() to arco.php, or explains the change to make by hand. */
trait EditsDomains
{
    /** The current dns() setting: a profile, false (manual) or null (deploy account). */
    private static function currentDns(AppConfig $config): string|false|null
    {
        return $config->dnsManual ? false : $config->dnsProfile;
    }

    /**
     * @param list<string> $domains
     * @param bool $writeDns whether $dns changed and must be written too
     */
    private function writeDomains(AppConfig $config, array $domains, string|false|null $dns, bool $writeDns): void
    {
        $path = $this->projectDir() . '/arco.php';
        $environment = $config->environment;
        // An app-level list (or app-level dns() when going back to the deploy account) cannot be
        // changed for one environment by editing that environment's closure.
        $unsupported = ($config->sources['domains'] ?? null) === 'app'
            || ($writeDns && $dns === null && ($config->sources['dns'] ?? null) === 'app');

        $byHand = $this->t('domain.edit_by_hand', ['environment' => $environment]) . "\n" . $this->resultLines($domains, $dns);
        if ($unsupported) {
            throw new UserError($byHand);
        }
        // Both calls are edited in memory and written once, so arco.php is never half-edited.
        $changes = $writeDns ? ['domains' => $domains, 'dns' => $dns] : ['domains' => $domains];
        try {
            $written = ArcoFileEditor::update($path, $environment, $changes);
        } catch (ConfigException $e) {
            throw new UserError($this->t($e->key, $e->params) . "\n" . $byHand);
        }
        if (! $written) {
            throw new UserError($byHand);
        }
    }

    /** @param list<string> $domains */
    private function resultLines(array $domains, string|false|null $dns): string
    {
        $lines = [$domains === [] ? '    ' . $this->t('domain.no_domain_call') : '    ' . ArcoFileEditor::domainLine($domains)];
        if ($dns !== null && $domains !== []) {
            $lines[] = '    ' . ArcoFileEditor::dnsLine($dns);
        }
        return implode("\n", $lines);
    }

    /** @param list<string> $domains */
    private function printResult(SymfonyStyle $io, AppConfig $config, array $domains, string|false|null $dns): void
    {
        $io->writeln($this->t('domain.result', ['environment' => $config->environment]));
        $io->writeln($this->resultLines($domains, $dns));
        $io->newLine();
        $io->writeln($this->t('domain.deploy_next', ['environment' => $config->environment]));
    }
}
