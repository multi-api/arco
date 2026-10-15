<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'secret:set', description: 'Store a secret in SSM Parameter Store for an environment')]
final class SecretSetCommand extends ArcoCommand
{
    use ResolvesTarget;

    public function __construct(?Closure $awsFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Secret name, e.g. app-key')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $argument = $input->getArgument('name');
            $name = is_string($argument) ? $argument : '';
            if (! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
                throw new UserError($this->t('secret.invalid_name', ['name' => $name]));
            }
            $config = $this->loadTarget($input, $io);
            $value = $this->readValue($input, $io);
            if ($value === '') {
                throw new UserError($this->t('secret.empty'));
            }

            $aws = $this->awsClients($config, $input, $io);
            $parameter = "/{$config->app}/{$config->environment}/$name";
            $aws->ssm()->putParameter(['Name' => $parameter, 'Value' => $value, 'Type' => 'SecureString', 'Overwrite' => true]);

            $io->success($this->t('secret.saved', ['parameter' => $parameter, 'reference' => "arco-ssm:$parameter"]));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    private function readValue(InputInterface $input, SymfonyStyle $io): string
    {
        if ($input->isInteractive()) {
            $answer = $io->askHidden($this->t('secret.ask_value'));
            return is_string($answer) ? $answer : '';
        }
        $stream = $input instanceof StreamableInputInterface && $input->getStream() !== null ? $input->getStream() : STDIN;
        return rtrim((string) stream_get_contents($stream), "\r\n");
    }
}
