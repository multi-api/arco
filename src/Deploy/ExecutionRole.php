<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Iam\Exception\IamException;
use Aws\Iam\IamClient;
use Aws\ResultInterface;
use Multiapi\Arco\Config\AppConfig;

final class ExecutionRole
{
    private const TRUST = [
        'Version' => '2012-10-17',
        'Statement' => [['Effect' => 'Allow', 'Principal' => ['Service' => 'lambda.amazonaws.com'], 'Action' => 'sts:AssumeRole']],
    ];

    public function __construct(private readonly IamClient $iam)
    {
    }

    public static function roleName(AppConfig $config): string
    {
        return substr('arco-' . $config->functionName(), 0, 64);
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, string> $tags
     * @return array{arn: string, created: bool}
     */
    public function ensure(string $roleName, array $policy, array $tags): array
    {
        $created = false;
        try {
            $arn = self::arn($this->iam->getRole(['RoleName' => $roleName]));
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                throw $e;
            }
            $arn = self::arn($this->iam->createRole([
                'RoleName' => $roleName,
                'AssumeRolePolicyDocument' => json_encode(self::TRUST, JSON_THROW_ON_ERROR),
                'Description' => 'Execution role managed by Arco',
                'Tags' => array_map(fn ($k, $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), $tags),
            ]));
            $created = true;
        }

        $this->iam->putRolePolicy([
            'RoleName' => $roleName,
            'PolicyName' => 'arco',
            'PolicyDocument' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);

        return ['arn' => $arn, 'created' => $created];
    }

    private static function arn(ResultInterface $result): string
    {
        $role = $result->get('Role');
        $arn = is_array($role) ? ($role['Arn'] ?? null) : null;
        if (! is_string($arn)) {
            throw new \UnexpectedValueException('IAM returned no role ARN.');
        }
        return $arn;
    }

    public function delete(string $roleName): void
    {
        foreach ([
            fn () => $this->iam->deleteRolePolicy(['RoleName' => $roleName, 'PolicyName' => 'arco']),
            fn () => $this->iam->deleteRole(['RoleName' => $roleName]),
        ] as $call) {
            try {
                $call();
            } catch (IamException $e) {
                if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                    throw $e;
                }
            }
        }
    }
}
