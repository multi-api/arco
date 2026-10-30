<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Resources\CertificateChecks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'certificate:set', description: 'Use your own ACM certificate for the custom domains: write certificate() in arco.php')]
final class CertificateSetCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsSettings;

    private const ARN = '/^arn:aws[a-z-]*:acm:([a-z0-9-]+):\d{12}:certificate\/[A-Za-z0-9-]+$/';

    /** @param (Closure(?string, string): AwsClients)|null $awsFactory */
    public function __construct(?Closure $awsFactory = null, private readonly ?Closure $clock = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('arn', InputArgument::REQUIRED, 'ARN of the ACM certificate (us-east-1)')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $arn = $this->argument($input, 'arn');
            if (preg_match(self::ARN, $arn, $match) !== 1) {
                throw new UserError($this->t('certificate.not_acm', ['value' => $arn]));
            }
            if ($match[1] !== 'us-east-1') {
                throw new UserError($this->t('certificate.wrong_region', ['region' => $match[1]]));
            }
            [$raw, $environment] = $this->target($input, $io);

            $old = self::levelValue($raw, $environment, 'certificate');
            $before = (string) @file_get_contents($this->arcoPhp());
            $this->writeSetting($environment, 'certificate', $arn);
            if ((string) @file_get_contents($this->arcoPhp()) === $before) {
                $io->note($this->t('certificate.unchanged', ['where' => $this->where($environment)]));
            } else {
                $io->success($this->t('certificate.set', ['where' => $this->where($environment)]));
                if (is_string($old)) {
                    $io->writeln($this->t('certificate.replaced', ['old' => $old]));
                }
            }
            foreach ($this->warnings($input, $io, $arn) as $warning) {
                $io->warning($this->t($warning['key'], $warning['params']));
            }
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            $io->writeln('    ' . ArcoFileEditor::settingLine('certificate', $arn));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /**
     * With working credentials, what ACM says about the certificate: not issued, expiring, not covering the
     * environment's domains. Without them (no profile, expired credentials, a denied call) the command stays
     * offline: the shape was validated and the deploy checks the rest. A certificate ACM does not know is
     * reported, not hidden.
     *
     * @return list<array{key: string, params: array<string, string>}>
     */
    private function warnings(InputInterface $input, SymfonyStyle $io, string $arn): array
    {
        try {
            $config = $this->loadTarget($input, new SymfonyStyle($input, new NullOutput()));
            $result = $this->awsClients($config, $input, $io)->acm()->describeCertificate(['CertificateArn' => $arn]);
        } catch (AwsException $e) {
            return $e->getAwsErrorCode() === 'ResourceNotFoundException'
                ? [['key' => 'resources.warning.certificate_not_found', 'params' => ['arn' => $arn]]]
                : [];
        } catch (Throwable) {
            return [];
        }
        $certificate = $result['Certificate'] ?? null;
        if (! is_array($certificate)) {
            return [];
        }
        return CertificateChecks::warnings($certificate, $config->domains, $this->clock ?? static fn (): int => time());
    }
}
