<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Aws\Exception\AwsException;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\StorageConfig;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Deploy\Domains\Aws;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'storage:list', description: 'List the storage buckets in arco.php and the retained ones in AWS')]
final class StorageListCommand extends ArcoCommand
{
    use ResolvesTarget;

    /** @param (Closure(?string, string): AwsClients)|null $awsFactory */
    public function __construct(?Closure $awsFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $config = $this->loadTarget($input, $io);
            // Retained buckets may exist without any storage in arco.php, so AWS is read either way.
            $aws = $this->awsClients($config, $input, $io);
            $located = $config->account === null ? $config->withAccount($aws->accountId()) : $config;
            if ($config->storage === []) {
                $io->note($this->t('storage.none_configured', ['environment' => $config->environment]));
            }
            foreach ($config->storage as $storage) {
                $io->section($storage->name);
                $io->definitionList(...$this->rows($config, $located, $storage));
            }
            $this->retained($aws, $located, $io);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /** @return list<array<string, string>> */
    private function rows(AppConfig $config, AppConfig $located, StorageConfig $storage): array
    {
        $none = $this->t('storage.none');
        $expire = [];
        foreach ($storage->expire as $prefix => $days) {
            $expire[] = $this->expireRule($prefix, $days);
        }
        $overrides = $storage->default ? DeployPipeline::storageOverrides($config, $located) : [];
        $default = match (true) {
            $overrides !== [] => $this->t('storage.list_default_overridden', [
                'keys' => implode(', ', array_keys($overrides)),
                'uses' => implode(', ', array_map(
                    fn (string $key, string $value) => $this->t($key === 'AWS_BUCKET' ? 'storage.uses_bucket' : 'storage.uses_disk', ['value' => $value]),
                    array_keys($overrides),
                    array_values($overrides),
                )),
            ]),
            default => $this->t($storage->default ? 'common.yes' : 'common.no'),
        };
        return [
            [$this->t('storage.list_bucket') => $storage->bucketName($located)],
            [$this->t('storage.list_url') => $storage->url($located)],
            [$this->t('storage.list_kind') => $this->t($storage->existing === null ? 'storage.kind_created' : 'storage.kind_existing')],
            [$this->t('storage.list_default') => $default],
            [$this->t('storage.list_public') => $storage->public === [] ? $none : $this->t('storage.public_prefixes', ['prefixes' => implode(', ', $storage->public)])],
            [$this->t('storage.list_expire') => $expire === [] ? $none : implode(', ', $expire)],
            [$this->t('storage.list_cors') => $storage->cors === [] ? $none : implode(', ', $storage->cors)],
        ];
    }

    /**
     * Buckets Arco created for this app and environment (tags arco:app, arco:environment and an
     * arco:resource of storage:<name>) whose storage is no longer in arco.php. Unreadable is unknown.
     */
    private function retained(AwsClients $aws, AppConfig $located, SymfonyStyle $io): void
    {
        $io->section($this->t('storage.retained_title'));
        try {
            $retained = $this->retainedBuckets($aws, $located);
        } catch (AwsException $e) {
            $io->writeln($this->t('storage.retained_unknown', ['error' => (string) ($e->getAwsErrorCode() ?? 'UnknownError')]));
            return;
        }
        if ($retained === []) {
            $io->writeln($this->t('storage.retained_none', ['environment' => $located->environment]));
            return;
        }
        $io->definitionList(...array_map(fn (string $name, string $bucket) => [$name => $bucket], array_keys($retained), array_values($retained)));
    }

    /** @return array<string, string> storage name => bucket name */
    private function retainedBuckets(AwsClients $aws, AppConfig $located): array
    {
        $configured = [];
        foreach ($located->storage as $storage) {
            $configured[$storage->name] = $storage->bucketName($located);
        }
        $retained = [];
        $token = null;
        do {
            $page = Aws::call($aws->taggingApi(), 'GetResources', [
                // Buckets have no resource type in their ARN (arn:aws:s3:::name): the filter is the service.
                'ResourceTypeFilters' => ['s3'],
                'TagFilters' => [
                    ['Key' => 'arco:app', 'Values' => [$located->app]],
                    ['Key' => 'arco:environment', 'Values' => [$located->environment]],
                    ['Key' => 'arco:resource'],
                ],
                ...($token === null ? [] : ['PaginationToken' => $token]),
            ]);
            foreach (Aws::maps($page['ResourceTagMappingList'] ?? []) as $resource) {
                $arn = Aws::string($resource['ResourceARN'] ?? null);
                if ($arn === null || preg_match('/^arn:[^:]+:s3:::([^\/]+)$/', $arn, $match) !== 1) {
                    continue;
                }
                $name = null;
                foreach (Aws::maps($resource['Tags'] ?? []) as $tag) {
                    $value = Aws::string($tag['Value'] ?? null);
                    if (Aws::string($tag['Key'] ?? null) === 'arco:resource' && $value !== null && str_starts_with($value, 'storage:')) {
                        $name = substr($value, strlen('storage:'));
                    }
                }
                if ($name === null || $name === '' || isset($configured[$name]) || in_array($match[1], $configured, true)) {
                    continue;
                }
                $retained[$name] = $match[1];
            }
            $token = Aws::string($page['PaginationToken'] ?? null);
        } while ($token !== null);
        ksort($retained);
        return $retained;
    }
}
