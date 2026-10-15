<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AccountGuard;
use Multiapi\Arco\Aws\AccountMismatch;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Aws\AwsProfile;
use Multiapi\Arco\Aws\LocalState;
use Multiapi\Arco\Aws\ProfileLister;
use Multiapi\Arco\Aws\ProfileResolver;
use Multiapi\Arco\Aws\SsoHint;
use Multiapi\Arco\Binary\BinaryException;
use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Validator;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Package\PackageTooLarge;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

trait ResolvesTarget
{
    /** @var Closure(?string, string): AwsClients */
    protected Closure $awsFactory;

    protected function loadTarget(InputInterface $input, SymfonyStyle $io): AppConfig
    {
        $raw = (new ConfigLoader())->load($this->projectDir() . '/arco.php');
        $environments = ConfigResolver::environments($raw);
        $environment = $input->getOption('env');
        if (! is_string($environment) || $environment === '') {
            if (count($environments) !== 1) {
                throw new UserError($this->t('config.choose_environment', ['available' => implode(', ', $environments)]));
            }
            $environment = $environments[0];
        }

        $config = ConfigResolver::resolve($raw, $environment);
        $validation = (new Validator())->validate($config, $this->projectDir());
        foreach ($validation->warnings as $warning) {
            $io->warning($this->t($warning['key'], $warning['params']));
        }
        if (! $validation->isValid()) {
            throw new UserError(implode("\n", array_map(fn (array $e) => '• ' . $this->t($e['key'], $e['params']), $validation->errors)));
        }
        return $config;
    }

    protected function awsClients(AppConfig $config, InputInterface $input, SymfonyStyle $io): AwsClients
    {
        $state = new LocalState($this->projectDir());
        /** @var array<string, string> $env */
        $env = getenv();
        $flag = $input->hasOption('profile') ? $input->getOption('profile') : null;
        $choice = ProfileResolver::resolve(is_string($flag) ? $flag : null, $env, $config, $state);

        $profile = $choice?->profile;
        if ($choice === null) {
            if (! $input->isInteractive()) {
                throw new UserError($this->t('aws.no_profile_non_interactive', ['environment' => $config->environment]));
            }
            $profile = $this->askProfile($config, $io);
            $state->rememberProfile($config->environment, $profile);
            $io->writeln('<info>' . $this->t('aws.profile_saved', ['environment' => $config->environment]) . '</info>');
        }

        $clients = ($this->awsFactory)($profile, (string) $config->region);
        try {
            $account = $clients->accountId();
        } catch (Throwable $e) {
            $hint = SsoHint::command($e, $profile);
            throw new UserError($hint !== null
                ? $this->t('aws.sso_expired', ['command' => $hint])
                : $this->t('aws.credentials_failed', ['profile' => $profile ?? 'environment', 'message' => $e->getMessage()]));
        }

        try {
            AccountGuard::check($config->account, $account, $profile);
        } catch (AccountMismatch $e) {
            throw new UserError($this->t('aws.account_mismatch', [
                'environment' => $config->environment,
                'expected' => $e->expected,
                'actual' => $e->actual,
                'profile' => $profile ?? 'environment',
            ]));
        }
        return $clients;
    }

    /**
     * Clients for the account whose Route 53 holds the zones, when that is not the deploy account:
     * --dns-profile, else dns('profile') in arco.php (also when domain() was removed, so remove can
     * still clean up). Building the clients makes no AWS call; see checkDnsCredentials().
     */
    protected function dnsClients(AppConfig $config, InputInterface $input): ?AwsClients
    {
        $flag = $input->hasOption('dns-profile') ? $input->getOption('dns-profile') : null;
        $profile = is_string($flag) && $flag !== '' ? $flag : $config->dnsProfile;
        // Route 53 is global; us-east-1 is its signing region.
        return $profile === null ? null : ($this->awsFactory)($profile, 'us-east-1');
    }

    /** Fails early, with the SSO hint, when the DNS profile has no usable credentials. Returns its account. */
    protected function checkDnsCredentials(AwsClients $dns): string
    {
        try {
            return $dns->accountId();
        } catch (Throwable $e) {
            $hint = SsoHint::command($e, $dns->profile);
            throw new UserError($hint !== null
                ? $this->t('aws.sso_expired', ['command' => $hint])
                : $this->t('aws.credentials_failed', ['profile' => (string) $dns->profile, 'message' => $e->getMessage()]));
        }
    }

    protected function askProfile(AppConfig $config, SymfonyStyle $io): string
    {
        [$configPath, $credentialsPath] = ProfileLister::defaultPaths();
        $profiles = ProfileLister::list($configPath, $credentialsPath);
        if ($profiles === []) {
            throw new UserError($this->t('aws.no_profiles'));
        }
        $labels = [];
        foreach ($profiles as $profile) {
            $labels[$profile->name] = self::profileLabel($profile);
        }
        $answer = $io->askQuestion(new ChoiceQuestion($this->t('aws.choose_profile', ['environment' => $config->environment]), $labels));
        return is_string($answer) || is_int($answer) ? (string) $answer : throw new \UnexpectedValueException('Profile choice was not a string.');
    }

    private static function profileLabel(AwsProfile $profile): string
    {
        $type = strtoupper($profile->type);
        return $profile->accountId !== null ? "$type · {$profile->accountId}" : $type;
    }

    protected function describeError(Throwable $e): ?string
    {
        return match (true) {
            $e instanceof UserError => $e->getMessage(),
            $e instanceof ConfigException, $e instanceof BuildException, $e instanceof BinaryException, $e instanceof DeployException
                => $this->t($e->key, $e->params),
            $e instanceof AwsException => $this->t(in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true) ? 'aws.access_denied' : 'aws.request_failed', [
                'operation' => $e->getCommand()->getName(),
                'code' => (string) $e->getAwsErrorCode(),
                'message' => (string) ($e->getAwsErrorMessage() ?? $e->getMessage()),
            ]),
            $e instanceof PackageTooLarge => $this->t('package.too_large', [
                'size' => (int) round($e->bytes / 1048576),
                'limit' => (int) round($e->limit / 1048576),
            ]) . "\n" . implode("\n", array_map(
                fn (string $dir, int $bytes) => sprintf('  %6.1f MB  %s', $bytes / 1048576, $dir),
                array_keys($e->largest),
                $e->largest,
            )) . "\n" . $this->t('package.too_large_hint'),
            default => null,
        };
    }
}
