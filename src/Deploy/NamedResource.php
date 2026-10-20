<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

/**
 * A resource Arco addresses by name, as found in AWS: what OwnershipGuard needs to decide whether it
 * belongs to the app and environment, and to tag it.
 */
final class NamedResource
{
    public const ROLE = 'role';
    public const WEB = 'web';
    public const LAMBDA = 'lambda';
    public const RULE = 'rule';

    /**
     * @param self::ROLE|self::WEB|self::LAMBDA|self::RULE $type
     * @param string $arn the ARN (the role name for a role: IAM tags by name)
     * @param array<string, string> $tags
     * @param bool $arcoMade a description Arco writes shows Arco made it
     * @param array{action: string, error: string}|null $unreadable the tag lookup was refused
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $arn,
        public readonly array $tags,
        public readonly bool $arcoMade,
        public readonly ?array $unreadable = null,
    ) {
    }
}
