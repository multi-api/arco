<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Aws\Exception\AwsException;
use Multiapi\Arco\Aws\DnsZoneFinder;
use Multiapi\Arco\Aws\ProfileLister;
use Multiapi\Arco\Aws\SsoHint;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Validator;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\CloudflareApi;
use Multiapi\Arco\Deploy\Domains\CloudflareDns;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Decides an environment's dns() setting for a list of domains: from --dns-profile / --dns=manual /
 * --dns=cloudflare, else by asking where the DNS is and checking only the chosen profile (or Cloudflare,
 * when a token is at hand). Shared by init and domain:add.
 */
trait ChoosesDns
{
    use UsesCloudflare;

    protected DnsZoneFinder $zoneFinder;

    /**
     * Hostnames from user input (comma or space separated), lowercased and without duplicates.
     *
     * @param list<string>|string $input
     * @return list<string>
     */
    protected function parseDomains(array|string $input): array
    {
        $parts = preg_split('/[\s,]+/', strtolower(implode(',', (array) $input))) ?: [];
        $domains = array_values(array_unique(array_filter(array_map(fn (string $d) => rtrim(trim($d), '.'), $parts), fn (string $d) => $d !== '')));
        foreach ($domains as $domain) {
            if (str_contains($domain, '*')) {
                throw new UserError($this->t('validation.domain_wildcard', ['domain' => $domain]));
            }
            if (! Validator::isHostname($domain)) {
                throw new UserError($this->t('validation.domain', ['domain' => $domain]));
            }
        }
        if (count($domains) > Validator::MAX_DOMAINS) {
            throw new UserError($this->t('validation.domain_count', ['count' => count($domains), 'max' => Validator::MAX_DOMAINS]));
        }
        return $domains;
    }

    /** The dns() setting given with --dns-profile, --dns=manual or --dns=cloudflare, or null when none was given. */
    protected function dnsFromFlags(InputInterface $input): string|false|null
    {
        $mode = $input->hasOption('dns') ? $input->getOption('dns') : null;
        $profile = $input->hasOption('dns-profile') ? $input->getOption('dns-profile') : null;
        if (is_string($mode) && $mode !== '') {
            if ($mode !== 'manual' && $mode !== ConfigResolver::CLOUDFLARE) {
                throw new UserError($this->t('dns.invalid_mode', ['value' => $mode]));
            }
            if (is_string($profile) && $profile !== '') {
                throw new UserError($this->t('dns.flags_conflict'));
            }
            return $mode === 'manual' ? false : ConfigResolver::CLOUDFLARE;
        }
        return is_string($profile) && $profile !== '' ? $profile : null;
    }

    /**
     * Decides the dns() setting: a profile name, 'cloudflare', false (manual DNS) or null (Route 53 in the
     * deploy account, i.e. no dns() call). Only the profile the user chose (or the deploy profile) is
     * checked; Arco never scans the other local profiles.
     *
     * @param list<string> $domains
     * @param string|null $defaultProfile profile preselected in the question (default: the deploy account)
     */
    protected function chooseDns(InputInterface $input, SymfonyStyle $io, array $domains, ?string $deployProfile, ?string $defaultProfile = null): string|false|null
    {
        $flag = $this->dnsFromFlags($input);
        if ($flag === false) {
            return false;
        }
        if ($flag === ConfigResolver::CLOUDFLARE) {
            $problem = $this->checkCloudflare($input, $io, $domains);
            if ($problem !== null) {
                throw new UserError($problem . "\n" . $this->t('dns.use_flags'));
            }
            return $flag;
        }
        if ($flag !== null || ! $input->isInteractive()) {
            $profile = $flag ?? $deployProfile;
            if ($profile === null) {
                $io->note($this->t('dns.deploy_unchecked'));
                return null;
            }
            $problem = $this->checkProfile($io, $domains, $profile);
            if ($problem !== null) {
                throw new UserError($problem . "\n" . $this->t('dns.use_flags'));
            }
            return $flag !== null ? $flag : null;
        }

        $zones = implode(', ', array_values(array_unique(array_map(fn (string $d) => self::apexGuess($d), $domains))));
        while (true) {
            $choice = $this->askDnsLocation($io, $zones, $deployProfile, $defaultProfile);
            if ($choice === '@manual') {
                return false;
            }
            if ($choice === '@cloudflare') {
                $problem = $this->checkCloudflare($input, $io, $domains);
                if ($problem === null) {
                    return ConfigResolver::CLOUDFLARE;
                }
                $io->warning($problem);
                continue;
            }
            $profile = $choice === '@deploy' ? $deployProfile : $choice;
            if ($profile === null) {
                $io->note($this->t('dns.deploy_unchecked'));
                return null;
            }
            $problem = $this->checkProfile($io, $domains, $profile);
            if ($problem === null) {
                return $profile === $deployProfile ? null : $profile;
            }
            $io->warning($problem);
        }
    }

    /**
     * Checks one profile and prints the zones it holds; returns what is wrong, or null when it holds them all.
     *
     * @param list<string> $domains
     */
    private function checkProfile(SymfonyStyle $io, array $domains, string $profile): ?string
    {
        $io->writeln($this->t('dns.checking', ['profile' => $profile]));
        $search = $this->zoneFinder->find($domains, $profile);
        foreach ($search->found as $domain => $match) {
            $io->writeln('  <info>✓</info> ' . $this->t('dns.found', ['domain' => $domain, 'zone' => $match->zoneName, 'profile' => $match->profile, 'account' => $match->account]));
        }
        if ($search->error !== null) {
            return $this->t('dns.profile_failed', ['profile' => $profile, 'reason' => $this->skipReason($search->error, $profile)]);
        }
        $missing = $search->missing($domains);
        return $missing === [] ? null : $this->t('dns.not_found', ['profile' => $profile, 'domains' => implode(', ', $missing)]);
    }

    private function askDnsLocation(SymfonyStyle $io, string $zones, ?string $deployProfile, ?string $defaultProfile): string
    {
        $choices = ['@deploy' => $deployProfile === null
            ? $this->t('dns.choice_deploy')
            : $this->t('dns.choice_deploy_profile', ['profile' => $deployProfile])];
        foreach (ProfileLister::list(...ProfileLister::defaultPaths()) as $profile) {
            if ($profile->name !== $deployProfile) {
                $choices[$profile->name] = $this->t('dns.choice_profile', ['profile' => $profile->name]);
            }
        }
        $default = '@deploy';
        if ($defaultProfile === ConfigResolver::CLOUDFLARE) {
            $default = '@cloudflare';
        } elseif ($defaultProfile !== null && $defaultProfile !== $deployProfile) {
            $choices[$defaultProfile] ??= $this->t('dns.choice_profile', ['profile' => $defaultProfile]);
            $default = $defaultProfile;
        }
        $choices['@cloudflare'] = $this->t('dns.choice_cloudflare');
        $choices['@manual'] = $this->t('dns.choice_manual');
        $answer = $io->askQuestion(new ChoiceQuestion($this->t('dns.ask_where', ['zones' => $zones]), $choices, $default));
        return is_string($answer) || is_int($answer) ? (string) $answer : '@deploy';
    }

    /**
     * Checks the Cloudflare zones of the domains when a token is at hand (--cloudflare-token or
     * CLOUDFLARE_API_TOKEN); returns what is wrong, or null when every domain has a zone or there is
     * no token to check with (deploy checks then).
     *
     * @param list<string> $domains
     */
    private function checkCloudflare(InputInterface $input, SymfonyStyle $io, array $domains): ?string
    {
        $api = $this->cloudflareApiOrNull($input);
        if ($api === null) {
            $io->note($this->t('dns.cloudflare_unchecked', ['env' => CloudflareApi::TOKEN_ENV]));
            return null;
        }
        $io->writeln($this->t('dns.checking_cloudflare'));
        $dns = new CloudflareDns($api);
        $missing = [];
        try {
            foreach ($domains as $domain) {
                $zone = $dns->zoneFor($domain);
                if ($zone === null) {
                    $missing[] = $domain;
                } else {
                    $io->writeln('  <info>✓</info> ' . $this->t('dns.found_cloudflare', ['domain' => $domain, 'zone' => $zone->name]));
                }
            }
        } catch (DeployException $e) {
            return $this->t('dns.cloudflare_failed', ['reason' => $this->t($e->key, $e->params)]);
        }
        return $missing === [] ? null : $this->t('dns.cloudflare_not_found', ['domains' => implode(', ', $missing)]);
    }

    private function skipReason(Throwable $error, string $profile): string
    {
        $hint = SsoHint::command($error, $profile);
        if ($hint !== null) {
            return $this->t('dns.reason_sso', ['command' => $hint]);
        }
        if ($error instanceof AwsException && $error->getAwsErrorCode() !== null) {
            return $error->getAwsErrorCode() . ($error->getAwsErrorMessage() ? ': ' . $error->getAwsErrorMessage() : '');
        }
        $message = trim(strtok($error->getMessage(), "\n") ?: $error::class);
        return strlen($message) > 200 ? substr($message, 0, 197) . '...' : $message;
    }

    /** The zone name most people would look for: the last two labels. */
    private static function apexGuess(string $domain): string
    {
        $candidates = DnsZoneFinder::candidates($domain);
        return $candidates === [] ? $domain : $candidates[count($candidates) - 1];
    }
}
