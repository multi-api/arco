<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Exception\AwsException;
use Multiapi\Arco\Deploy\Domains\Aws;
use UnexpectedValueException;

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
     * The tags of the table (DescribeTable for its ARN, then ListTagsOfResource). Throws on any failure,
     * an answer without the table's ARN included.
     *
     * @return array<string, string>
     * @throws AwsException|UnexpectedValueException
     */
    public function tags(string $table): array
    {
        $arn = Aws::string(Aws::map(Aws::call($this->dynamoDb, 'DescribeTable', ['TableName' => $table])['Table'] ?? null)['TableArn'] ?? null);
        if ($arn === null || $arn === '') {
            throw new UnexpectedValueException("DescribeTable returned no TableArn for $table.");
        }
        $tags = [];
        $token = null;
        do {
            $result = Aws::call($this->dynamoDb, 'ListTagsOfResource', ['ResourceArn' => $arn] + ($token === null ? [] : ['NextToken' => $token]));
            $tags += Aws::tagMap($result['Tags'] ?? []);
            $token = Aws::string($result['NextToken'] ?? null);
        } while ($token !== null);
        return $tags;
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
