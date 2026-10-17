<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

use Multiapi\Arco\Config\AppConfig;

/** The log groups of an environment, by --source. */
final class LogGroups
{
    /**
     * @param string|null $queue the queue name for the `queue` source
     * @return list<string>
     */
    public static function for(AppConfig $config, string $source, ?string $queue = null): array
    {
        // Web Functions write to /aws/lambda/web/<function> and, for some events, "<group>.local".
        $web = '/aws/lambda/web/' . $config->functionName();
        $console = '/aws/lambda/' . $config->consoleFunctionName();
        $workers = array_map(fn ($q) => '/aws/lambda/' . $config->workerFunctionName($q->name), $config->queues);
        return match ($source) {
            'web' => [$web, "$web.local"],
            'console' => [$console],
            'queue' => [$queue !== null && in_array('/aws/lambda/' . $config->workerFunctionName($queue), $workers, true)
                ? '/aws/lambda/' . $config->workerFunctionName($queue)
                : throw new \InvalidArgumentException('Unknown queue ' . ($queue ?? '') . '.')],
            'queues' => $workers,
            'all' => [$web, "$web.local", $console, ...$workers],
            default => throw new \InvalidArgumentException("Unknown log source $source."),
        };
    }

    public static function arn(string $region, string $account, string $group): string
    {
        $partition = match (true) {
            str_starts_with($region, 'cn-') => 'aws-cn',
            str_starts_with($region, 'us-gov-') => 'aws-us-gov',
            default => 'aws',
        };
        return "arn:$partition:logs:$region:$account:log-group:$group";
    }
}
