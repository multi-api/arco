<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AccountGuard;
use Multiapi\Arco\Aws\AccountMismatch;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Aws\AwsProfile;
use Multiapi\Arco\Aws\CredentialError;
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
use Multiapi\Arco\Config\PermissionsBoundary;
use Multiapi\Arco\Config\Validator;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\Route53Dns;
use Multiapi\Arco\Package\PackageTooLarge;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

trait ResolvesTarget
{
    /** @var Closure(?string, string): AwsClients */
    protected Closure $awsFactory;

    /** The profile whose clients the command is using, so credential errors can name it. */
    protected ?string $credentialProfile = null;
    /** The DNS profile, when the command also uses one (Route 53 in another account). */
    protected ?string $dnsCredentialProfile = null;

    /** @param (Closure(string, array<string, string|int>): void)|null $warn gets each config warning instead of $io (--json) */
    protected function loadTarget(InputInterface $input, SymfonyStyle $io, ?Closure $warn = null): AppConfig
    {
        $raw = (new ConfigLoader())->load($this->projectDir() . '/arco.php');
        $environments = ConfigResolver::environments($raw);
        $environment = $input->getOption('env');
        if (! is_string($environment) || $environment === '') {
            if (count($environments) !== 1) {
                throw $this->userError('config.choose_environment', ['available' => implode(', ', $environments)]);
            }
            $environment = $environments[0];
        }

        $config = ConfigResolver::resolve($raw, $environment);
        // deploy and info: the deploy host's override, validated with the rest (info shows what deploy uses).
        if ($input->hasOption(PermissionsBoundary::OPTION)) {
            $flag = $input->getOption(PermissionsBoundary::OPTION);
            /** @var array<string, string> $env */
            $env = getenv();
            $config = PermissionsBoundary::apply($config, is_string($flag) ? $flag : null, $env);
        }
        $validation = (new Validator())->validate($config, $this->projectDir());
        foreach ($validation->warnings as $warning) {
            $warn !== null ? $warn($warning['key'], $warning['params']) : $io->warning($this->t($warning['key'], $warning['params']));
        }
        if (! $validation->isValid()) {
            throw new UserError(implode("\n", array_map(fn (array $e) => '• ' . $this->t($e['key'], $e['params']), $validation->errors)), $validation->errors[0]['key'] ?? null);
        }
        return $config;
    }

    /**
     * @param bool $checkCredentials false skips sts:GetCallerIdentity when there is no account lock to
     *     check: a round trip (and a TLS handshake) that latency-sensitive commands (tinker, command)
     *     do not need; missing or expired credentials then surface on their first call, by profile
     */
    protected function awsClients(AppConfig $config, InputInterface $input, SymfonyStyle $io, bool $checkCredentials = true): AwsClients
    {
        $state = new LocalState($this->projectDir());
        /** @var array<string, string> $env */
        $env = getenv();
        $flag = $input->hasOption('profile') ? $input->getOption('profile') : null;
        $choice = ProfileResolver::resolve(is_string($flag) ? $flag : null, $env, $config, $state);

        $profile = $choice?->profile;
        if ($choice === null) {
            if (! $input->isInteractive()) {
                throw $this->userError('aws.no_profile_non_interactive', ['environment' => $config->environment]);
            }
            $profile = $this->askProfile($config, $io);
            $state->rememberProfile($config->environment, $profile);
            $io->writeln('<info>' . $this->t('aws.profile_saved', ['environment' => $config->environment]) . '</info>');
        }

        $this->credentialProfile = $profile;
        $clients = ($this->awsFactory)($profile, (string) $config->region);
        if (! $checkCredentials && $config->account === null) {
            return $clients;
        }
        try {
            $account = $clients->accountId();
        } catch (Throwable $e) {
            throw new UserError($this->credentialProblem($e, $profile)
                ?? $this->t('aws.credentials_failed', ['profile' => $profile ?? 'environment', 'message' => $e->getMessage()]), self::credentialProblemKey($e, $profile));
        }

        $this->guardAccount($config, $account, $profile);
        return $clients;
    }

    /** @throws UserError when the environment is locked to another account than the profile's */
    protected function guardAccount(AppConfig $config, string $account, ?string $profile): void
    {
        try {
            AccountGuard::check($config->account, $account, $profile);
        } catch (AccountMismatch $e) {
            throw $this->userError('aws.account_mismatch', [
                'environment' => $config->environment,
                'expected' => $e->expected,
                'actual' => $e->actual,
                'profile' => $profile ?? 'environment',
            ]);
        }
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
        $this->dnsCredentialProfile = $profile;
        // Route 53 is global; us-east-1 is its signing region.
        return $profile === null ? null : ($this->awsFactory)($profile, 'us-east-1');
    }

    /** Fails early, with the SSO hint, when the DNS profile has no usable credentials. Returns its account. */
    protected function checkDnsCredentials(AwsClients $dns): string
    {
        try {
            return $dns->accountId();
        } catch (Throwable $e) {
            throw new UserError($this->credentialProblem($e, $dns->profile)
                ?? $this->t('aws.credentials_failed', ['profile' => (string) $dns->profile, 'message' => $e->getMessage()]), self::credentialProblemKey($e, $dns->profile));
        }
    }

    /**
     * The translated "credentials missing or expired" message, with `aws sso login` for SSO profiles;
     * "could not get credentials" with AWS's message when they failed for another reason (a denied
     * AssumeRole, a failing credential_process); null when the failure is not about credentials.
     */
    protected function credentialProblem(Throwable $e, ?string $profile): ?string
    {
        $name = $profile ?? 'environment';
        if (SsoHint::command($e, $profile) === null && ! CredentialError::matches($e)) {
            return CredentialError::isCredentialsFailure($e)
                ? $this->t('aws.credentials_failed', ['profile' => $name, 'message' => CredentialError::message($e)])
                : null;
        }
        $command = CredentialError::loginCommand($e, $profile);
        return $command !== null
            ? $this->t('aws.credentials_expired', ['profile' => $name, 'command' => $command])
            : $this->t('aws.credentials_expired_generic', ['profile' => $name, 'message' => CredentialError::message($e)]);
    }

    /**
     * credentialProblem() for the profile whose clients failed: the DNS profile for a Route 53 call
     * when one is in use. When the failing call is unknown (a credential provider error has none),
     * both profiles are named rather than possibly the wrong one.
     */
    private function commandCredentialProblem(Throwable $e): ?string
    {
        $deploy = $this->credentialProfile;
        $dns = $this->dnsCredentialProfile;
        if ($dns === null || $dns === $deploy) {
            return $this->credentialProblem($e, $deploy);
        }
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AwsException) {
                return $this->credentialProblem($e, in_array($current->getCommand()->getName(), Route53Dns::OPERATIONS, true) ? $dns : $deploy);
            }
        }
        if (! CredentialError::matches($e) && SsoHint::command($e, $deploy) === null) {
            return CredentialError::isCredentialsFailure($e)
                ? $this->t('aws.credentials_failed', ['profile' => ($deploy ?? 'environment') . ", $dns", 'message' => CredentialError::message($e)])
                : null;
        }
        $commands = array_values(array_filter([CredentialError::loginCommand($e, $deploy), CredentialError::loginCommand($e, $dns)]));
        return $this->t('aws.credentials_expired_either', ['profile' => $deploy ?? 'environment', 'dns' => $dns, 'message' => CredentialError::message($e)])
            . ($commands === [] ? '' : "\n" . $this->t('aws.sso_login_hint', ['commands' => implode(' / ', array_unique($commands))]));
    }

    /** The message key credentialProblem() translates for $e (aws.credentials_failed when it returns null). */
    private static function credentialProblemKey(Throwable $e, ?string $profile): string
    {
        if (SsoHint::command($e, $profile) === null && ! CredentialError::matches($e)) {
            return 'aws.credentials_failed';
        }
        return CredentialError::loginCommand($e, $profile) !== null ? 'aws.credentials_expired' : 'aws.credentials_expired_generic';
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
            CredentialError::isCredentialsFailure($e) || CredentialError::matches($e) => (string) $this->commandCredentialProblem($e),
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

    /** The message key behind describeError($e), for --json output; error.unexpected when it has none. */
    protected function describeErrorKey(Throwable $e): string
    {
        return match (true) {
            $e instanceof UserError => $e->key ?? 'error.user',
            CredentialError::isCredentialsFailure($e) || CredentialError::matches($e) => self::credentialProblemKey($e, $this->credentialProfile),
            $e instanceof ConfigException, $e instanceof BuildException, $e instanceof BinaryException, $e instanceof DeployException => $e->key,
            $e instanceof AwsException => in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true) ? 'aws.access_denied' : 'aws.request_failed',
            $e instanceof PackageTooLarge => 'package.too_large',
            default => 'error.unexpected',
        };
    }
}
