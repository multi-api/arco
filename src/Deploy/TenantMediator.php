<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Multiapi\Arco\Arco;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Config\AppConfig;

/**
 * The <app>-<env>-tenants function and its own execution role: a small Node.js function the Laravel app invokes
 * to add, check, list and remove the distribution tenants (customer domains) of the environment's tenant
 * distribution. It holds the only CloudFront permissions of the feature and only over tenants tagged for this
 * app and environment; the app itself may do nothing but invoke it (PolicyBuilder, ArcoTenantDomains).
 *
 * Its code (tenant-domains.js and lib/tenants/) is sent inline and no version or alias is published: the app
 * invokes the unqualified function. A deploy never sets or removes its reserved concurrency; `arco remove` sets it
 * to 0 to drain the function, a deploy that finds it so stops rather than undo that, and only `arco tenants:restore`
 * (TenantRestore) deletes it again.
 */
final class TenantMediator
{
    /** Seconds before Lambda stops an invocation; `arco remove` waits this long for invocations in flight to end. */
    public const TIMEOUT = 30;
    public const MEMORY_MB = 256;
    public const HANDLER = 'tenant-domains.handler';
    public const ENTRY = 'tenant-domains.js';
    public const CODE_DIR = 'lib/tenants';
    /** The public suffix list the mediator validates customer domains with; without it every operation fails. */
    public const SUFFIX_LIST = 'lib/tenants/public_suffix_list.dat';
    /** Start of the function's description. */
    public const MARKER = 'Arco tenant domains mediator';

    private readonly string $runtimeDir;

    /** @param string|null $runtimeDir the directory holding tenant-domains.js and lib/ (the package's runtime/) */
    public function __construct(private readonly AwsClients $aws, private readonly Sleeper $sleeper, ?string $runtimeDir = null)
    {
        $this->runtimeDir = rtrim($runtimeDir ?? Arco::packageRoot() . '/runtime', '/');
    }

    /** The IAM role's name: the execution role's, with -tenants; a long one is shortened with a hash of the function name. */
    public static function roleName(AppConfig $config): string
    {
        $base = ExecutionRole::roleName($config);
        $suffix = '-tenants';
        if (strlen($base) + strlen($suffix) <= 64) {
            return $base . $suffix;
        }
        // 47 + "-" + 8 hash + 8 suffix = 64
        return substr($base, 0, 47) . '-' . substr(hash('sha256', $config->functionName()), 0, 8) . $suffix;
    }

    /** The unqualified ARN the app is allowed to invoke. */
    public static function arn(AppConfig $config, string $region, string $account): string
    {
        return "arn:aws:lambda:$region:$account:function:{$config->tenantMediatorFunctionName()}";
    }

    /**
     * The mediator role's whole inline policy. Tenants are created and tagged with the environment's tags (request
     * tags), and changed or deleted only when they carry them (resource tags); reads have no tag condition, so the
     * mediator can tell that a domain belongs to someone else. ACM only to request and read the tenants' managed
     * certificates; no other CloudFront action, nothing of the app's.
     *
     * @return array{Version: string, Statement: list<array<string, mixed>>}
     */
    public static function policy(string $app, string $environment, string $region, string $account, string $function): array
    {
        $tenants = "arn:aws:cloudfront::$account:distribution-tenant/*";
        $request = ['StringEquals' => ['aws:RequestTag/arco:app' => $app, 'aws:RequestTag/arco:environment' => $environment]];
        $resource = ['StringEquals' => ['aws:ResourceTag/arco:app' => $app, 'aws:ResourceTag/arco:environment' => $environment]];
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Sid' => 'ArcoTenantLogs',
                    'Effect' => 'Allow',
                    'Action' => ['logs:CreateLogGroup', 'logs:CreateLogStream', 'logs:PutLogEvents'],
                    'Resource' => "arn:aws:logs:$region:$account:log-group:/aws/lambda/$function:*",
                ],
                [
                    'Sid' => 'ArcoTenantCreate',
                    'Effect' => 'Allow',
                    'Action' => 'cloudfront:CreateDistributionTenant',
                    'Resource' => '*',
                    'Condition' => $request,
                ],
                [
                    'Sid' => 'ArcoTenantTagOnCreate',
                    'Effect' => 'Allow',
                    'Action' => 'cloudfront:TagResource',
                    'Resource' => $tenants,
                    'Condition' => $request,
                ],
                [
                    'Sid' => 'ArcoTenantWrite',
                    'Effect' => 'Allow',
                    'Action' => ['cloudfront:UpdateDistributionTenant', 'cloudfront:DeleteDistributionTenant'],
                    'Resource' => $tenants,
                    'Condition' => $resource,
                ],
                [
                    'Sid' => 'ArcoTenantRead',
                    'Effect' => 'Allow',
                    'Action' => ['cloudfront:GetDistributionTenant', 'cloudfront:GetDistributionTenantByDomain', 'cloudfront:GetManagedCertificateDetails', 'cloudfront:ListTagsForResource'],
                    'Resource' => $tenants,
                ],
                [
                    // A tenant's managed certificate is requested by CloudFront with the caller's permissions: without
                    // these, CreateDistributionTenant is denied ("CloudFront cannot access the specified Amazon ACM
                    // certificate for the operation: RequestCertificate"). CloudFront certificates live in us-east-1.
                    'Sid' => 'ArcoTenantCertificates',
                    'Effect' => 'Allow',
                    'Action' => ['acm:RequestCertificate', 'acm:DescribeCertificate', 'acm:AddTagsToCertificate'],
                    'Resource' => "arn:aws:acm:us-east-1:$account:certificate/*",
                ],
                [
                    'Sid' => 'ArcoTenantList',
                    'Effect' => 'Allow',
                    'Action' => 'cloudfront:ListDistributionTenants',
                    'Resource' => '*',
                ],
            ],
        ];
    }

    /**
     * The function's settings (those UpdateFunctionConfiguration sends; what a create adds is in deploy()).
     *
     * @param array{target: string, endpoint: string, distributionId: string, connectionGroupId: string} $infra
     * @return array<string, mixed>
     */
    public static function settings(AppConfig $config, string $roleArn, array $infra): array
    {
        $reserved = array_values(array_unique(array_map('strtolower', $config->domains)));
        sort($reserved);
        return [
            'Role' => $roleArn,
            'Description' => self::MARKER . ' (Arco ' . Arco::version() . ')',
            'MemorySize' => self::MEMORY_MB,
            'Timeout' => self::TIMEOUT,
            'Environment' => ['Variables' => array_filter([
                'ARCO_TENANT_DISTRIBUTION_ID' => $infra['distributionId'],
                'ARCO_TENANT_CONNECTION_GROUP_ID' => $infra['connectionGroupId'],
                'ARCO_TENANT_TARGET' => $infra['target'],
                'ARCO_TENANT_ENDPOINT' => $infra['endpoint'],
                'ARCO_TENANT_RESERVED' => implode(',', $reserved),
                'ARCO_APP' => $config->app,
                'ARCO_ENVIRONMENT' => $config->environment,
            ], static fn (string $value): bool => $value !== '')],
        ];
    }

    /**
     * Everything the deploy would refuse, read-only and before anything changes: the code is not complete, the
     * function or the role belongs to someone else (or its tags cannot be read), or the function is being drained
     * by `arco remove`. `deploy --force` overrides none of it: a mediator of another environment would hand
     * this one's customers' domains to that environment's CloudFront permissions.
     *
     * @throws DeployException deploy.tenant_mediator_runtime_missing, deploy.tenant_mediator_suffix_list_missing,
     *         deploy.tenant_mediator_not_owned, deploy.tenant_mediator_untagged, deploy.tenant_mediator_tags_unreadable,
     *         deploy.tenant_mediator_draining
     * @throws AwsException a failed read (including a refused GetFunctionConcurrency: it stops the deploy)
     */
    public function preflight(AppConfig $config): void
    {
        $this->files();
        $guard = new OwnershipGuard($this->aws);
        $role = $guard->role(self::roleName($config));
        if ($role !== null) {
            self::assertOwned($config, $role);
        }
        $name = $config->tenantMediatorFunctionName();
        $function = $guard->lambdaFunction($name, self::MARKER);
        if ($function === null) {
            return;
        }
        self::assertOwned($config, $function);
        $reserved = $this->aws->lambda()->getFunctionConcurrency(['FunctionName' => $name])['ReservedConcurrentExecutions'] ?? null;
        if ($reserved === 0) {
            throw DeployException::make('deploy.tenant_mediator_draining', ['function' => $name, 'environment' => $config->environment]);
        }
    }

    /**
     * Creates or updates the role and the function, after the tenant infrastructure is routing: no tenant can
     * exist before this runs.
     *
     * @param array{target: string, endpoint: string, distributionId: string, connectionGroupId: string} $infra what TenantDomains::deploy() returns
     * @param array<string, string> $tags
     * @param callable(string, array<string, string|int>): void $step
     */
    public function deploy(AppConfig $config, array $infra, array $tags, callable $step): void
    {
        $this->preflight($config);
        $files = $this->files();
        $region = (string) $config->region;
        $account = $this->aws->accountId();
        $name = $config->tenantMediatorFunctionName();
        $roleName = self::roleName($config);

        $step('deploy.step_tenant_mediator', ['function' => $name, 'role' => $roleName]);
        ['arn' => $roleArn, 'created' => $created] = (new ExecutionRole($this->aws->iam()))->ensure(
            $roleName,
            self::policy($config->app, $config->environment, $region, $account, $name),
            $tags,
            $region,
            $step,
            $config->permissionsBoundary,
        );
        (new LambdaFunction($this->aws->lambda(), $this->sleeper, 'deploy.tenant_mediator_missing', 'deploy.tenant_mediator_failed'))->deployInline(
            $name,
            self::HANDLER,
            ['ZipFile' => InlineZip::build($files)],
            self::settings($config, $roleArn, $infra),
            $tags,
            $created,
            Arco::LAMBDA_RUNTIME,
        );
    }

    /**
     * The files of the function's code by path in the zip.
     *
     * @return array<string, string>
     * @throws DeployException deploy.tenant_mediator_runtime_missing, deploy.tenant_mediator_suffix_list_missing
     */
    private function files(): array
    {
        $entry = $this->runtimeDir . '/' . self::ENTRY;
        if (! is_file($entry)) {
            throw DeployException::make('deploy.tenant_mediator_runtime_missing', ['path' => $entry]);
        }
        if (! is_file($this->runtimeDir . '/' . self::SUFFIX_LIST)) {
            throw DeployException::make('deploy.tenant_mediator_suffix_list_missing', ['path' => $this->runtimeDir . '/' . self::SUFFIX_LIST]);
        }
        $files = [self::ENTRY => self::read($entry)];
        $root = $this->runtimeDir . '/' . self::CODE_DIR;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[self::CODE_DIR . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = self::read($file->getPathname());
            }
        }
        return $files;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw DeployException::make('deploy.tenant_mediator_runtime_missing', ['path' => $path]);
        }
        return $contents;
    }

    /** Strict: the resource carries this app's and environment's tags, nothing else counts (not its description either). */
    public static function assertOwned(AppConfig $config, NamedResource $resource): void
    {
        if ($resource->unreadable !== null) {
            throw DeployException::make('deploy.tenant_mediator_tags_unreadable', ['resource' => $resource->name, ...$resource->unreadable]);
        }
        $app = $resource->tags[Ownership::APP] ?? null;
        $environment = $resource->tags[Ownership::ENVIRONMENT] ?? null;
        if ($app === $config->app && $environment === $config->environment) {
            return;
        }
        if ($app === null && $environment === null) {
            throw DeployException::make('deploy.tenant_mediator_untagged', ['resource' => $resource->name]);
        }
        throw DeployException::make('deploy.tenant_mediator_not_owned', ['resource' => $resource->name, 'app' => $app ?? '-', 'environment' => $environment ?? '-']);
    }
}
