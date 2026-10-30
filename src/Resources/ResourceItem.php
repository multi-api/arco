<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

/** One existing AWS resource as `arco resources` shows it (spec §5.1) and as the JSON gives it (spec §8). */
final class ResourceItem
{
    public const STACK_TAG = 'aws:cloudformation:stack-name';

    /**
     * @param array<string, string> $tags
     * @param array<string, mixed> $details type-specific fields (spec §5.2), merged flat into the JSON
     * @param list<array{key: string, params: array<string, string>}> $warnings
     * @param array{source: string}|null $inUse
     * @param array{command: string, args: list<string>}|array<string, array{command: string, args: list<string>}>|null $apply one command, or (tables) one per use: generic, cache, failedJobs
     * @param array{with: string, source: string}|null $conflict
     */
    public function __construct(
        public ResourceType $type,
        public string $id,
        public ?string $name,
        public array $tags,
        public array $details,
        public array $warnings = [],
        public bool $hidden = false,
        public ?array $inUse = null,
        public ?string $suggestedName = null,
        public ?array $apply = null,
        public ?array $conflict = null,
    ) {
    }

    /** The CloudFormation stack that created the resource, when the tag says so. */
    public function stack(): ?string
    {
        $stack = $this->tags[self::STACK_TAG] ?? '';
        return $stack === '' ? null : $stack;
    }

    /** @return array{app: string, environment: string}|null "created by Arco for <app>/<env>" */
    public function arco(): ?array
    {
        $app = $this->tags['arco:app'] ?? '';
        $environment = $this->tags['arco:environment'] ?? '';
        return $app === '' || $environment === '' ? null : ['app' => $app, 'environment' => $environment];
    }

    /** Nothing identifies it: no name, no tags, no stack. */
    public function unnamed(): bool
    {
        return $this->name === null && $this->tags === [] && $this->stack() === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'type' => $this->type->value,
            'id' => $this->id,
            'name' => $this->name,
            ...$this->details,
            'stack' => $this->stack(),
            'arco' => $this->arco(),
            'tags' => $this->tags === [] ? new \stdClass() : $this->tags,
            'unnamed' => $this->unnamed(),
            'inUse' => $this->inUse,
            'hidden' => $this->hidden,
            'warnings' => $this->warnings,
            'apply' => $this->apply,
        ];
        if ($this->suggestedName !== null) {
            $out['suggestedName'] = $this->suggestedName;
        }
        if ($this->conflict !== null) {
            $out['conflict'] = $this->conflict;
        }
        return $out;
    }
}
