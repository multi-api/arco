<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;

/** Helpers shared by the Lambda-backed deploy classes (WebFunctions and ConsoleFunction). */
final class LambdaHelpers
{
    private const ROLE_RETRIES = 10;

    /**
     * IAM roles take a few seconds to become assumable after creation. Only a role created in this
     * deploy is retried, and only when the AWS error message (not the request context that
     * getMessage() includes, such as the function name) mentions the role.
     *
     * @template T
     * @param list<string> $errorCodes the service's error codes that can signal an unassumable role
     * @param callable(): T $call
     * @return T
     */
    public static function retryRolePropagation(Sleeper $sleeper, bool $roleJustCreated, array $errorCodes, callable $call): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (AwsException $e) {
                $roleProblem = $roleJustCreated
                    && in_array($e->getAwsErrorCode(), $errorCodes, true)
                    && str_contains(strtolower((string) $e->getAwsErrorMessage()), 'role');
                if (! $roleProblem || $attempt >= self::ROLE_RETRIES) {
                    throw $e;
                }
                $sleeper->sleep(3);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }
        return $out;
    }

    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
