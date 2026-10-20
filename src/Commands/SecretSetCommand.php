<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Deploy\Ownership;
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
            // PutParameter cannot tag when it overwrites: the tags that make it the environment's follow apart.
            try {
                $aws->ssm()->addTagsToResource([
                    'ResourceType' => 'Parameter',
                    'ResourceId' => $parameter,
                    'Tags' => array_map(static fn (string $key, string $tag): array => ['Key' => $key, 'Value' => $tag], array_keys(Ownership::tags($config)), array_values(Ownership::tags($config))),
                ]);
            } catch (AwsException $e) {
                // The secret is saved: a refused tag is a warning.
                $message = $e->getAwsErrorMessage();
                $io->warning($this->t('secret.tags_not_added', ['parameter' => $parameter, 'error' => is_string($message) && $message !== '' ? $message : $e->getMessage()]));
            }

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
