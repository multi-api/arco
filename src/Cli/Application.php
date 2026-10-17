<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

use Multiapi\Arco\Arco;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends SymfonyApplication
{
    private ?Translator $translator = null;

    public static function create(): self
    {
        $app = new self('Arco', Arco::version());
        $app->addCommands([
            new \Multiapi\Arco\Commands\InitCommand(),
            new \Multiapi\Arco\Commands\DeployCommand(),
            new \Multiapi\Arco\Commands\ConsoleCommand(),
            new \Multiapi\Arco\Commands\TinkerCommand(),
            new \Multiapi\Arco\Commands\LogsCommand(),
            new \Multiapi\Arco\Commands\PackageCommand(),
            new \Multiapi\Arco\Commands\InfoCommand(),
            new \Multiapi\Arco\Commands\RemoveCommand(),
            new \Multiapi\Arco\Commands\ProfileCommand(),
            new \Multiapi\Arco\Commands\SecretSetCommand(),
            new \Multiapi\Arco\Commands\DomainAddCommand(),
            new \Multiapi\Arco\Commands\DomainRemoveCommand(),
            new \Multiapi\Arco\Commands\SetCommand(),
            new \Multiapi\Arco\Commands\UnsetCommand(),
            new \Multiapi\Arco\Commands\ConsoleAddCommand(),
            new \Multiapi\Arco\Commands\ConsoleRemoveCommand(),
            new \Multiapi\Arco\Commands\SchedulerSetCommand(),
            new \Multiapi\Arco\Commands\SchedulerRemoveCommand(),
            new \Multiapi\Arco\Commands\LogsRetentionCommand(),
        ]);
        return $app;
    }

    public function translator(): Translator
    {
        return $this->translator ??= new Translator('en', Arco::packageRoot() . '/lang');
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();
        $definition->addOption(new InputOption('lang', null, InputOption::VALUE_REQUIRED, 'Language for messages (en, es)'));
        return $definition;
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $flagValue = $input->hasParameterOption('--lang') ? $input->getParameterOption('--lang') : null;
        $flag = is_string($flagValue) ? $flagValue : null;
        /** @var array<string, string> $env */
        $env = getenv();
        $locale = LocaleResolver::resolve($flag, $env, LocaleResolver::globalConfigPath());
        $this->translator = new Translator($locale, Arco::packageRoot() . '/lang');

        return parent::doRun($input, $output);
    }
}
