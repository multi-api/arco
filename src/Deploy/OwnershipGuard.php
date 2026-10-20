<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Aws\Iam\Exception\IamException;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * Reads the tags (and Arco's own descriptions) of the resources Arco addresses by name, and decides
 * with Ownership whether they belong to the app and environment before anything changes them.
 *
 * Markers that show Arco made an untagged resource (every Arco release since 0.1.0 wrote them):
 * the role's description, the web function's live revision description, the console and worker
 * function descriptions, the scheduler rule's description.
 */
final class OwnershipGuard
{
    public const ROLE_MARKER = 'Execution role managed by Arco';
    public const REVISION_MARKER = 'Deployed by Arco';
    public const CONSOLE_MARKER = 'Arco console function';
    public const WORKER_MARKER = 'Arco worker for queue';
    public const RULE_MARKER = 'Arco: schedule:run';
    private const DENIED = ['AccessDenied', 'AccessDeniedException'];

    public function __construct(private readonly AwsClients $aws)
    {
    }

    public function role(string $name): ?NamedResource
    {
        try {
            $role = Aws::map($this->aws->iam()->getRole(['RoleName' => $name])->get('Role'));
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() === 'NoSuchEntity') {
                return null;
            }
            throw $e;
        }
        // GetRole returns the role's tags: no other permission is needed to read them.
        return new NamedResource(NamedResource::ROLE, $name, $name, self::tagList($role['Tags'] ?? []), ($role['Description'] ?? null) === self::ROLE_MARKER);
    }

    /** @param string $keyPrefix the S3 prefix of the environment's web zips (`<app>/<env>/`) */
    public function webFunction(string $name, string $keyPrefix): ?NamedResource
    {
        $web = $this->aws->lambdaWeb();
        try {
            $function = Aws::call($web, 'GetWebFunction', ['functionName' => $name]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
        $arn = (string) Aws::string($function['functionArn'] ?? null);
        try {
            $tags = self::tagMap(Aws::call($web, 'ListTags', ['resource' => $arn])['tags'] ?? []);
        } catch (AwsException $e) {
            return self::unreadable(NamedResource::WEB, $name, $arn, 'lambda:ListTags', $e);
        }
        return new NamedResource(NamedResource::WEB, $name, $arn, $tags, ! self::hasBothTags($tags) && $this->revisionByArco($name, $keyPrefix));
    }

    /**
     * Whether the revision serving the web function's traffic was deployed by Arco for this app and
     * environment: Arco's description, and its zip under the environment's S3 prefix (both since 0.1.0),
     * so another environment's function stripped of its tags is never adopted.
     */
    private function revisionByArco(string $name, string $keyPrefix): bool
    {
        $web = $this->aws->lambdaWeb();
        try {
            $endpoint = Aws::call($web, 'GetWebFunctionEndpoint', ['functionName' => $name, 'endpointName' => WebFunctions::ENDPOINT]);
            $revision = WebFunctions::activeRevision($endpoint);
            if ($revision === null) {
                return false;
            }
            $details = Aws::call($web, 'GetWebFunctionRevision', ['functionName' => $name, 'revisionId' => $revision]);
        } catch (AwsException) {
            // No evidence: the resource is treated as untagged and not Arco's.
            return false;
        }
        $description = Aws::string($details['description'] ?? null);
        $code = Aws::map(Aws::map(Aws::map($details['buildConfig'] ?? null)['codeConfig'] ?? null)['s3Object'] ?? null);
        $key = Aws::string($code['key'] ?? null);
        return $description !== null && str_starts_with($description, self::REVISION_MARKER)
            && $key !== null && str_starts_with($key, $keyPrefix);
    }

    /** A classic Lambda function (console or worker); $marker is the start of the description Arco writes. */
    public function lambdaFunction(string $name, string $marker): ?NamedResource
    {
        $lambda = $this->aws->lambda();
        try {
            $function = Aws::map($lambda->getFunctionConfiguration(['FunctionName' => $name])->toArray());
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
        $arn = (string) Aws::string($function['FunctionArn'] ?? null);
        $description = (string) Aws::string($function['Description'] ?? null);
        try {
            $tags = self::tagMap($lambda->listTags(['Resource' => $arn])['Tags'] ?? []);
        } catch (AwsException $e) {
            return self::unreadable(NamedResource::LAMBDA, $name, $arn, 'lambda:ListTags', $e);
        }
        return new NamedResource(NamedResource::LAMBDA, $name, $arn, $tags, str_starts_with($description, $marker));
    }

    /**
     * The scheduler rule; null when it does not exist. When EventBridge refuses to describe it, the
     * resource is unreadable (events:DescribeRule): callers that will not change the rule drop it
     * (isUndescribed()), the others refuse.
     */
    public function schedulerRule(string $name): ?NamedResource
    {
        $events = $this->aws->eventBridge();
        try {
            $rule = Aws::map($events->describeRule(['Name' => $name])->toArray());
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            return self::unreadable(NamedResource::RULE, $name, '', 'events:DescribeRule', $e);
        }
        $arn = (string) Aws::string($rule['Arn'] ?? null);
        $description = (string) Aws::string($rule['Description'] ?? null);
        try {
            $tags = self::tagList($events->listTagsForResource(['ResourceARN' => $arn])['Tags'] ?? []);
        } catch (AwsException $e) {
            return self::unreadable(NamedResource::RULE, $name, $arn, 'events:ListTagsForResource', $e);
        }
        return new NamedResource(NamedResource::RULE, $name, $arn, $tags, str_starts_with($description, self::RULE_MARKER));
    }

    /**
     * Checks every resource before any is changed, then tags the ones this environment owns that lack
     * the tags. With $force (`deploy --force`) refusals are overridden: the resource is taken over and
     * tagged for this environment, with a warning.
     *
     * @param list<NamedResource> $resources
     * @param callable(string, array<string, string|int>): void $step
     * @throws DeployException deploy.not_owned, deploy.untagged_resource, deploy.tags_unreadable
     */
    public function claim(AppConfig $config, array $resources, bool $force, callable $step): void
    {
        foreach ($resources as $resource) {
            if ($resource->unreadable !== null && ! $force) {
                throw DeployException::make('deploy.tags_unreadable', ['resource' => $resource->name, ...$resource->unreadable]);
            }
            Ownership::check($resource->tags, $config, $resource->name, $force, $resource->arcoMade);
        }
        foreach ($resources as $resource) {
            if ($resource->unreadable !== null) {
                $step('deploy.ownership_forced', ['resource' => $resource->name, 'app' => $config->app, 'environment' => $config->environment]);
                continue;
            }
            if (Ownership::tagged($resource->tags, $config)) {
                continue;
            }
            $foreign = self::hasAnyTag($resource->tags) || ! $resource->arcoMade;
            if ($foreign) {
                // Only reachable with --force: the resource carries another owner's tags or no sign of Arco.
                $step('deploy.ownership_forced', ['resource' => $resource->name, 'app' => $config->app, 'environment' => $config->environment]);
            }
            try {
                $this->tag($resource, Ownership::tags($config));
            } catch (AwsException $e) {
                $step('deploy.tags_not_added', ['resource' => $resource->name, 'error' => self::error($e)]);
                continue;
            }
            if (! $foreign) {
                $step('deploy.tags_added', ['resource' => $resource->name, 'app' => $config->app, 'environment' => $config->environment]);
            }
        }
    }

    /**
     * Checks every resource `arco remove` is about to delete, before any is deleted. There is no
     * override: a resource another app or environment owns is never deleted by this one.
     *
     * @param list<NamedResource> $resources
     * @throws DeployException remove.not_owned, remove.untagged_resource, remove.tags_unreadable
     */
    public function checkRemoval(AppConfig $config, array $resources): void
    {
        foreach ($resources as $resource) {
            if ($resource->unreadable !== null) {
                throw DeployException::make('remove.tags_unreadable', ['resource' => $resource->name, ...$resource->unreadable]);
            }
            Ownership::check($resource->tags, $config, $resource->name, false, $resource->arcoMade, 'remove');
        }
    }

    /**
     * Checks the resources a command uses without deploying (`arco command`, `arco tinker`,
     * `scheduler:pause|resume`): no override, no tagging.
     *
     * @param list<NamedResource> $resources
     * @throws DeployException ownership.not_owned, ownership.untagged_resource, ownership.tags_unreadable
     */
    public function checkUse(AppConfig $config, array $resources): void
    {
        foreach ($resources as $resource) {
            if ($resource->unreadable !== null) {
                throw DeployException::make('ownership.tags_unreadable', ['resource' => $resource->name, ...$resource->unreadable]);
            }
            Ownership::check($resource->tags, $config, $resource->name, false, $resource->arcoMade, 'ownership');
        }
    }

    /**
     * Before `arco command` or `arco tinker` invokes the console function: refuses one another app or
     * environment owns. A missing function is left to the invoke to report.
     */
    public static function checkConsoleUse(AwsClients $aws, AppConfig $config): void
    {
        $guard = new self($aws);
        $console = $guard->lambdaFunction($config->consoleFunctionName(), self::CONSOLE_MARKER);
        $guard->checkUse($config, $console === null ? [] : [$console]);
    }

    /** Whether the rule could not even be described (no tags read, nothing known about it). */
    public static function isUndescribed(NamedResource $resource): bool
    {
        return ($resource->unreadable['action'] ?? null) === 'events:DescribeRule';
    }

    /** @param array<string, string> $tags */
    private function tag(NamedResource $resource, array $tags): void
    {
        $list = array_map(static fn (string $key, string $value): array => ['Key' => $key, 'Value' => $value], array_keys($tags), array_values($tags));
        match ($resource->type) {
            NamedResource::ROLE => $this->aws->iam()->tagRole(['RoleName' => $resource->arn, 'Tags' => $list]),
            NamedResource::WEB => Aws::call($this->aws->lambdaWeb(), 'TagResource', ['resource' => $resource->arn, 'tags' => $tags]),
            NamedResource::LAMBDA => $this->aws->lambda()->tagResource(['Resource' => $resource->arn, 'Tags' => $tags]),
            NamedResource::RULE => $this->aws->eventBridge()->tagResource(['ResourceARN' => $resource->arn, 'Tags' => $list]),
        };
    }

    /**
     * A resource whose tag lookup was refused (another error is rethrown).
     *
     * @param NamedResource::ROLE|NamedResource::WEB|NamedResource::LAMBDA|NamedResource::RULE $type
     */
    private static function unreadable(string $type, string $name, string $arn, string $action, AwsException $e): NamedResource
    {
        if (! in_array($e->getAwsErrorCode(), self::DENIED, true)) {
            throw $e;
        }
        return new NamedResource($type, $name, $arn, [], false, ['action' => $action, 'error' => self::error($e)]);
    }

    private static function error(AwsException $e): string
    {
        $message = $e->getAwsErrorMessage();
        return is_string($message) && $message !== '' ? $message : $e->getMessage();
    }

    /** @param array<string, string> $tags */
    private static function hasBothTags(array $tags): bool
    {
        return isset($tags[Ownership::APP], $tags[Ownership::ENVIRONMENT]);
    }

    /** @param array<string, string> $tags */
    private static function hasAnyTag(array $tags): bool
    {
        return isset($tags[Ownership::APP]) || isset($tags[Ownership::ENVIRONMENT]);
    }

    /** @return array<string, string> tags given as a map (Lambda) */
    private static function tagMap(mixed $tags): array
    {
        $out = [];
        foreach (is_array($tags) ? $tags : [] as $key => $value) {
            if (is_string($value)) {
                $out[(string) $key] = $value;
            }
        }
        return $out;
    }

    /** @return array<string, string> tags given as a list of Key/Value pairs (IAM, EventBridge) */
    private static function tagList(mixed $tags): array
    {
        $out = [];
        foreach (Aws::maps($tags) as $tag) {
            $key = Aws::string($tag['Key'] ?? null);
            if ($key !== null && is_string($tag['Value'] ?? null)) {
                $out[$key] = $tag['Value'];
            }
        }
        return $out;
    }
}
