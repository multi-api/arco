<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

use Aws\Acm\AcmClient;
use Aws\CloudFormation\CloudFormationClient;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialSources;
use Aws\Exception\CredentialsException;
use GuzzleHttp\Promise\Create;
use Aws\Iam\IamClient;
use Aws\Lambda\LambdaClient;
use Aws\LambdaWeb\LambdaWebClient;
use Aws\Route53\Route53Client;
use Aws\S3\S3Client;
use Aws\Sdk;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Ssm\SsmClient;
use Aws\Sts\StsClient;
use Multiapi\Arco\Arco;

final class AwsClients
{
    /** Credential sources whose account was set by AWS when it issued them. */
    private const ACCOUNT_BOUND_SOURCES = [CredentialSources::PROFILE_SSO, CredentialSources::PROFILE_SSO_LEGACY, CredentialSources::STS_ASSUME_ROLE];

    private Sdk $sdk;
    private ?string $accountId = null;

    /** @param array<string, mixed> $overrides extra SDK config (tests pass 'handler') */
    public function __construct(public readonly ?string $profile, public readonly string $region, array $overrides = [])
    {
        $config = [
            'region' => $region,
            'version' => 'latest',
            'ua_append' => ['arco/' . Arco::version()],
        ];
        if ($profile !== null) {
            $config['credentials'] = self::credentialsFor($profile, $region);
        }
        $this->sdk = new Sdk([...$config, ...$overrides]);
    }

    /**
     * Credentials for a named profile from both ~/.aws/config and ~/.aws/credentials.
     *
     * The SDK's own 'profile' option reads only the credentials file, so SSO, login and
     * credential_process profiles (the common case with IAM Identity Center) would fail with
     * "profile not found". This is the SDK default chain pinned to one profile, without the
     * environment and instance providers, so an explicit profile always wins. Role profiles
     * (role_arn + source_profile, in either file) are resolved here: the source profile through this
     * same method, then sts:AssumeRole. Not supported: credential_source, mfa_serial and
     * web_identity_token_file profiles.
     *
     * @param array<string, mixed> $stsOverrides extra config for the AssumeRole STS client (tests pass 'handler')
     * @param list<string> $visited role profiles already on the way here (cycle guard)
     */
    public static function credentialsFor(string $profile, string $region, array $stsOverrides = [], array $visited = []): callable
    {
        [$configFile, $credentialsFile] = ProfileLister::defaultPaths();
        $section = self::section($profile, $configFile, $credentialsFile);
        if (isset($section['role_arn'])) {
            return self::assumeRole($profile, $section, $region, $stsOverrides, $visited);
        }

        $options = ['region' => $region];
        $providers = [CredentialProvider::sso($profile, $configFile, $options)];
        // Only for `aws login` profiles: for others it emits a notice about an unrelated expired token.
        if (isset($section['login_session'])) {
            $providers[] = CredentialProvider::login($profile, $options);
        }
        // Same order as the SDK default chain: credentials file (process, then keys), then config file.
        array_push(
            $providers,
            CredentialProvider::process($profile),
            CredentialProvider::ini($profile, null, $options),
            CredentialProvider::process('profile ' . $profile, $configFile),
            CredentialProvider::ini('profile ' . $profile, $configFile, $options),
        );
        return CredentialProvider::memoize(CredentialProvider::chain(...$providers));
    }

    /**
     * @param array<string, string> $section
     * @param array<string, mixed> $stsOverrides
     * @param list<string> $visited
     */
    private static function assumeRole(string $profile, array $section, string $region, array $stsOverrides, array $visited): callable
    {
        [$configFile, $credentialsFile] = ProfileLister::defaultPaths();
        // Checked for the whole chain up front: a failing source would otherwise surface only as
        // the SDK's generic "Error in retrieving assume role credentials".
        $problem = self::roleProblem($profile, $section, $visited, $configFile, $credentialsFile);
        if ($problem !== null) {
            return self::failing($problem);
        }
        $source = $section['source_profile'];
        if ($source === $profile) {
            $sourceCredentials = CredentialProvider::fromCredentials(new Credentials($section['aws_access_key_id'], $section['aws_secret_access_key'], $section['aws_session_token'] ?? null));
        } else {
            $sourceCredentials = self::credentialsFor($source, $region, $stsOverrides, [...$visited, $profile]);
        }

        $params = [
            'RoleArn' => $section['role_arn'],
            'RoleSessionName' => ($section['role_session_name'] ?? '') !== '' ? $section['role_session_name'] : 'arco-' . time(),
        ];
        if (($section['external_id'] ?? '') !== '') {
            $params['ExternalId'] = $section['external_id'];
        }
        if (($section['duration_seconds'] ?? '') !== '') {
            $params['DurationSeconds'] = (int) $section['duration_seconds'];
        }
        $sts = new StsClient([
            'region' => ($section['region'] ?? '') !== '' ? $section['region'] : $region,
            'version' => 'latest',
            'credentials' => $sourceCredentials,
            ...$stsOverrides,
        ]);
        return CredentialProvider::memoize(CredentialProvider::assumeRole(['client' => $sts, 'assume_role_params' => $params]));
    }

    /**
     * Why a role profile (and the role profiles it sources from) cannot be used, or null.
     *
     * @param array<string, string> $section
     * @param list<string> $visited
     */
    private static function roleProblem(string $profile, array $section, array $visited, string $configFile, string $credentialsFile): ?string
    {
        if (isset($section['credential_source'])) {
            return "Profile \"$profile\" uses credential_source, which Arco does not support. Use source_profile, or provide the credentials through the environment.";
        }
        if (isset($section['mfa_serial'])) {
            return "Profile \"$profile\" requires an MFA code (mfa_serial), which Arco cannot ask for. Use a profile without mfa_serial, or IAM Identity Center (SSO).";
        }
        $source = $section['source_profile'] ?? '';
        if ($source === '') {
            return "Profile \"$profile\" has role_arn but no source_profile.";
        }
        if ($source === $profile) {
            return isset($section['aws_access_key_id'], $section['aws_secret_access_key']) ? null : "Profile \"$profile\" is its own source_profile but has no access keys.";
        }
        $chain = [...$visited, $profile];
        if (in_array($source, $chain, true)) {
            return 'Role profiles form a cycle: ' . implode(' -> ', [...$chain, $source]) . '.';
        }
        $sourceSection = self::section($source, $configFile, $credentialsFile);
        return isset($sourceSection['role_arn']) ? self::roleProblem($source, $sourceSection, $chain, $configFile, $credentialsFile) : null;
    }

    private static function failing(string $message): callable
    {
        return static fn () => Create::rejectionFor(new CredentialsException($message));
    }

    /**
     * The profile's settings: [profile X] (or [default]) in the config file, overridden by [X] in
     * the credentials file.
     *
     * @return array<string, string>
     */
    private static function section(string $profile, string $configFile, string $credentialsFile): array
    {
        $config = self::ini($configFile);
        $credentials = self::ini($credentialsFile);
        $fromConfig = $config["profile $profile"] ?? ($profile === 'default' ? ($config['default'] ?? []) : []);
        return [...$fromConfig, ...($credentials[$profile] ?? [])];
    }

    /** @return array<string, array<string, string>> */
    private static function ini(string $path): array
    {
        $sections = is_readable($path) ? @parse_ini_file($path, true, INI_SCANNER_RAW) : false;
        if (! is_array($sections)) {
            return [];
        }
        $out = [];
        foreach ($sections as $name => $values) {
            if (! is_array($values)) {
                continue;
            }
            $section = [];
            foreach ($values as $key => $value) {
                if (is_scalar($value)) {
                    $section[(string) $key] = (string) $value;
                }
            }
            $out[(string) $name] = $section;
        }
        return $out;
    }

    public function sts(): StsClient { return $this->sdk->createSts(); }
    public function s3(): S3Client { return $this->sdk->createS3(); }
    public function iam(): IamClient { return $this->sdk->createIam(); }
    public function lambdaWeb(): LambdaWebClient { return $this->sdk->createLambdaWeb(); }
    /** @param array<string, mixed> $overrides extra client config (invocations disable retries and raise the HTTP timeout) */
    public function lambda(array $overrides = []): LambdaClient { return $this->sdk->createLambda($overrides); }
    public function ssm(): SsmClient { return $this->sdk->createSsm(); }
    public function secretsManager(): SecretsManagerClient { return $this->sdk->createSecretsManager(); }
    /** @param array<string, mixed> $overrides extra client config (Live Tail passes an http_handler) */
    public function logs(array $overrides = []): CloudWatchLogsClient { return $this->sdk->createCloudWatchLogs($overrides); }
    /** CloudFront only uses certificates from us-east-1, whatever the app region. */
    public function acm(): AcmClient { return $this->sdk->createAcm(['region' => 'us-east-1']); }
    public function cloudFormation(): CloudFormationClient { return $this->sdk->createCloudFormation(); }
    public function route53(): Route53Client { return $this->sdk->createRoute53(); }

    /**
     * The account of these credentials, read once per process. IAM Identity Center (SSO) and AssumeRole
     * credentials carry the account AWS issued them for (sso:GetRoleCredentials for sso_account_id, the
     * AssumedRoleUser ARN), so no sts:GetCallerIdentity round trip is needed. Other credentials (keys,
     * credential_process, environment) may state an account nothing checked: those ask STS.
     */
    public function accountId(): string
    {
        if ($this->accountId === null) {
            $sts = $this->sts();
            $credentials = $sts->getCredentials()->wait();
            $account = $credentials instanceof Credentials && in_array($credentials->getSource(), self::ACCOUNT_BOUND_SOURCES, true)
                ? $credentials->getAccountId()
                : null;
            if (! is_string($account) || preg_match('/^\d{12}$/D', $account) !== 1) {
                $account = $sts->getCallerIdentity()['Account'];
            }
            if (! is_string($account)) {
                throw new \UnexpectedValueException('sts:GetCallerIdentity returned no Account.');
            }
            $this->accountId = $account;
        }
        return $this->accountId;
    }
}
