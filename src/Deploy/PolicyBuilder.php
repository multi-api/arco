<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Secrets\SecretReference;
use Multiapi\Arco\Secrets\SecretSource;

final class PolicyBuilder
{
    /**
     * IAM limits a role's inline policies to 10,240 characters, whitespace excluded.
     */
    public const MAX_SIZE = 10240;

    /**
     * @param array<string, SecretReference> $references
     * @param list<array<string, mixed>> $permissions extra statements from permissions()
     * @return array{Version: string, Statement: list<array<string, mixed>>}
     */
    public static function document(string $region, string $account, string $function, array $references, array $permissions = []): array
    {
        // Web Functions log to /aws/lambda/web/<function> and also to a "<group>.local" group.
        $group = "arn:aws:logs:$region:$account:log-group:/aws/lambda/web/$function";
        $statements = [[
            'Sid' => 'ArcoLogs',
            'Effect' => 'Allow',
            'Action' => ['logs:CreateLogGroup', 'logs:CreateLogStream', 'logs:PutLogEvents'],
            'Resource' => ["$group:*", "$group.local:*"],
        ]];

        $parameters = [];
        $secrets = [];
        foreach ($references as $reference) {
            if ($reference->source === SecretSource::Ssm) {
                $name = str_starts_with($reference->name, '/') ? $reference->name : '/' . $reference->name;
                $parameters[] = "arn:aws:ssm:$region:$account:parameter$name";
            } else {
                $secrets[] = str_starts_with($reference->name, 'arn:')
                    ? $reference->name
                    : "arn:aws:secretsmanager:$region:$account:secret:{$reference->name}-??????";
            }
        }
        $parameters = array_values(array_unique($parameters));
        $secrets = array_values(array_unique($secrets));

        if ($parameters !== []) {
            $statements[] = ['Sid' => 'ArcoParameters', 'Effect' => 'Allow', 'Action' => ['ssm:GetParameters'], 'Resource' => $parameters];
        }
        if ($secrets !== []) {
            $statements[] = ['Sid' => 'ArcoSecrets', 'Effect' => 'Allow', 'Action' => ['secretsmanager:GetSecretValue'], 'Resource' => $secrets];
            $statements[] = ['Sid' => 'ArcoSecretsBatch', 'Effect' => 'Allow', 'Action' => ['secretsmanager:BatchGetSecretValue'], 'Resource' => '*'];
        }
        if ($parameters !== [] || $secrets !== []) {
            $statements[] = [
                'Sid' => 'ArcoDecrypt',
                'Effect' => 'Allow',
                'Action' => ['kms:Decrypt'],
                'Resource' => '*',
                'Condition' => ['StringEquals' => ['kms:ViaService' => ["ssm.$region.amazonaws.com", "secretsmanager.$region.amazonaws.com"]]],
            ];
        }

        $document = ['Version' => '2012-10-17', 'Statement' => [...$statements, ...$permissions]];
        $size = strlen(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($size > self::MAX_SIZE) {
            throw DeployException::make('deploy.policy_too_large', ['size' => $size, 'max' => self::MAX_SIZE]);
        }
        return $document;
    }
}
