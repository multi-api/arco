<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Exception\AwsException;
use Multiapi\Arco\Deploy\Domains\Aws;

/** DynamoDB lookups around the tables of the resources stack. */
final class Tables
{
    public function __construct(private readonly DynamoDbClient $dynamoDb)
    {
    }

    /**
     * Whether a table with this name exists in the account and region. A profile that may not
     * describe tables gets deploy.lookup_denied; other errors are not guessed at.
     */
    public function exists(string $name): bool
    {
        try {
            Aws::call($this->dynamoDb, 'DescribeTable', ['TableName' => $name]);
            return true;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return false;
            }
            if (in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true)) {
                throw DeployException::make('deploy.lookup_denied', ['permission' => 'dynamodb:DescribeTable', 'name' => $name], $e);
            }
            throw $e;
        }
    }

    /**
     * The approximate item count (DynamoDB refreshes it about every six hours), or null when it
     * cannot be read: only ever shown in warnings.
     */
    public function itemCount(string $name): ?int
    {
        try {
            $table = Aws::map(Aws::call($this->dynamoDb, 'DescribeTable', ['TableName' => $name])['Table'] ?? null);
        } catch (AwsException) {
            return null;
        }
        $count = $table['ItemCount'] ?? null;
        return is_numeric($count) ? (int) $count : null;
    }
}
