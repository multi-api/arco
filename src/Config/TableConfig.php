<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/** A DynamoDB table Arco manages (or references, when `existing` is set): the cache store or the failed-jobs table. */
final readonly class TableConfig
{
    private const TABLE = '/^[A-Za-z0-9_.-]{3,255}$/';

    /** @param 'cache'|'failed-jobs' $kind */
    public function __construct(
        public string $kind,
        /** name of a table Arco does not manage */
        public ?string $existing = null,
    ) {
    }

    public static function isTableName(string $name): bool
    {
        return preg_match(self::TABLE, $name) === 1;
    }

    public function tableName(AppConfig $config): string
    {
        return $this->existing ?? $config->app . '-' . $config->environment . '-' . $this->kind;
    }

    public function arn(AppConfig $config): string
    {
        return 'arn:' . Partition::of($config->region ?? '') . ':dynamodb:' . ($config->region ?? '') . ':' . ($config->account ?? '') . ':table/' . $this->tableName($config);
    }
}
