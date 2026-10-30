<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Aws\Exception\AwsException;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Deploy\Domains\Aws;
use Multiapi\Arco\Resources\SubnetLister;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'vpc:set', description: 'Set the VPC (subnets, security groups, gateway endpoints) of the functions in arco.php')]
final class VpcSetCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsSettings;

    /** @param (Closure(?string, string): AwsClients)|null $awsFactory */
    public function __construct(?Closure $awsFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('subnet', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Subnet id (subnet-...); repeat for each subnet')
            ->addOption('security-group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Security group id (sg-...); repeat for each group')
            ->addOption('endpoint', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 's3 or dynamodb (Arco creates the gateway endpoint), or s3=vpce-... / dynamodb=vpce-... (yours)')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            [$raw, $environment] = $this->target($input, $io);
            $vpc = [
                'subnets' => self::list($input, 'subnet'),
                'securityGroups' => self::list($input, 'security-group'),
                'endpoints' => self::endpoints(self::list($input, 'endpoint')),
            ];
            $this->assertNoErrors(self::withVpc($raw, $environment, $vpc), $environment, ['validation.vpc']);
            if ($environment !== null) {
                $this->checkSubnets($input, $io, $vpc['subnets']);
            }

            $old = self::levelValue($raw, $environment, 'vpc');
            $before = (string) @file_get_contents($this->arcoPhp());
            $this->writeOrExplain($environment, ArcoFileEditor::vpcLine($vpc), fn (): bool => ArcoFileEditor::setVpc($this->arcoPhp(), $environment, $vpc));
            if ((string) @file_get_contents($this->arcoPhp()) === $before) {
                $io->note($this->t('vpc.unchanged', ['where' => $this->where($environment)]));
            } else {
                $io->success($this->t('vpc.set', ['where' => $this->where($environment)]));
                if ($old !== null) {
                    $io->writeln($this->t('vpc.replaced', ['old' => $this->describe($old)]));
                }
            }
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            $io->writeln('    ' . ArcoFileEditor::vpcLine($vpc));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /**
     * With working credentials, checks the subnets with EC2: a subnet that does not exist stops the command, subnets
     * in more than one VPC too, and the egress warnings of `arco resources` are printed (they never block). Without
     * credentials (no profile, expired credentials, a denied call) the command stays offline: the shape was validated
     * and the deploy checks the rest.
     *
     * @param list<string> $subnetIds
     */
    private function checkSubnets(InputInterface $input, SymfonyStyle $io, array $subnetIds): void
    {
        try {
            $config = $this->loadTarget($input, new SymfonyStyle($input, new NullOutput()));
            $aws = $this->awsClients($config, $input, $io);
            $result = $aws->ec2()->describeSubnets(['SubnetIds' => $subnetIds]);
        } catch (AwsException $e) {
            if (str_starts_with((string) $e->getAwsErrorCode(), 'InvalidSubnetID.NotFound')) {
                preg_match_all('/subnet-[0-9a-z]+/', (string) $e->getAwsErrorMessage(), $named);
                throw new UserError($this->t('vpc.subnet_not_found', ['subnets' => implode(', ', $named[0] !== [] ? $named[0] : $subnetIds)]));
            }
            return;
        } catch (Throwable) {
            return;
        }
        $subnets = Aws::maps($result['Subnets'] ?? []);
        $this->assertOneVpc($subnets);
        $this->warnAboutEgress($io, $aws, $subnets);
    }

    /**
     * @param list<array<string, mixed>> $subnets
     */
    private function warnAboutEgress(SymfonyStyle $io, AwsClients $aws, array $subnets): void
    {
        try {
            $items = (new SubnetLister())->items($aws, $subnets);
        } catch (Throwable) {
            return; // the warnings are a courtesy: without the route tables there is nothing to say
        }
        foreach ($items as $item) {
            foreach ($item->warnings as $warning) {
                $io->warning($item->id . ': ' . $this->t($warning['key'], $warning['params']));
            }
        }
    }

    /**
     * The seam for what the DescribeSubnets answer says about the subnets: they must share one VPC.
     *
     * @param list<array<string, mixed>> $subnets
     */
    private function assertOneVpc(array $subnets): void
    {
        $vpcs = [];
        foreach ($subnets as $subnet) {
            if (is_string($subnet['VpcId'] ?? null)) {
                $vpcs[$subnet['VpcId']] = true;
            }
        }
        if (count($vpcs) > 1) {
            throw new UserError($this->t('vpc.mixed_vpcs', ['vpcs' => implode(', ', array_keys($vpcs))]));
        }
    }

    /** @return list<string> */
    private static function list(InputInterface $input, string $option): array
    {
        $values = $input->getOption($option);
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    /**
     * `s3` is an endpoint Arco creates (a list entry); `s3=vpce-...` names one that exists (service => id).
     *
     * @param list<string> $values
     * @return array<int|string, string>
     */
    private static function endpoints(array $values): array
    {
        $endpoints = [];
        foreach ($values as $value) {
            if (str_contains($value, '=')) {
                [$service, $id] = explode('=', $value, 2);
                $endpoints[$service] = $id;
            } else {
                $endpoints[] = $value;
            }
        }
        return $endpoints;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $vpc
     * @return array<string, mixed>
     */
    private static function withVpc(array $raw, ?string $environment, array $vpc): array
    {
        if ($environment === null) {
            $settings = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
            $settings['vpc'] = $vpc;
            $raw['settings'] = $settings;
            return $raw;
        }
        $environments = is_array($raw['environments'] ?? null) ? $raw['environments'] : [];
        $env = is_array($environments[$environment] ?? null) ? $environments[$environment] : [];
        $settings = is_array($env['settings'] ?? null) ? $env['settings'] : [];
        $settings['vpc'] = $vpc;
        $env['settings'] = $settings;
        $environments[$environment] = $env;
        $raw['environments'] = $environments;
        return $raw;
    }

    /** The existing vpc() call as the "replaced" line names it. */
    private function describe(mixed $old): string
    {
        if (! is_array($old)) {
            return ArcoFileEditor::vpcLine(false);
        }
        $ids = static fn (mixed $list): string => implode(', ', is_array($list) ? array_filter($list, 'is_string') : []);
        return $this->t('info.vpc', ['subnets' => $ids($old['subnets'] ?? []), 'groups' => $ids($old['securityGroups'] ?? [])]);
    }
}
