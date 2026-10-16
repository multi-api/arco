<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/** arco-ssm: / arco-secret: references; same rules as Multiapi\Arco\Secrets\SecretReference. */
final readonly class Reference
{
    public const SSM = 'arco-ssm:';
    public const SECRET = 'arco-secret:';

    private function __construct(public string $source, public string $name, public ?string $jsonKey)
    {
    }

    public static function isReference(string $value): bool
    {
        return str_starts_with($value, self::SSM) || str_starts_with($value, self::SECRET);
    }

    public static function parse(string $value): self
    {
        if (str_starts_with($value, self::SSM)) {
            $name = substr($value, strlen(self::SSM));
            if ($name === '') {
                throw new \InvalidArgumentException("Invalid secret reference \"$value\": the parameter name is empty.");
            }
            if (str_contains($name, '#')) {
                throw new \InvalidArgumentException("Invalid secret reference \"$value\": \"#key\" is only supported for arco-secret: references.");
            }
            return new self('ssm', $name, null);
        }
        if (str_starts_with($value, self::SECRET)) {
            $parts = explode('#', substr($value, strlen(self::SECRET)), 2);
            $key = $parts[1] ?? null;
            if ($parts[0] === '') {
                throw new \InvalidArgumentException("Invalid secret reference \"$value\": the secret id is empty.");
            }
            if ($key === '') {
                throw new \InvalidArgumentException("Invalid secret reference \"$value\": the JSON key after \"#\" is empty.");
            }
            return new self('secretsmanager', $parts[0], $key);
        }
        throw new \InvalidArgumentException("\"$value\" is not an arco-ssm: or arco-secret: reference.");
    }
}
