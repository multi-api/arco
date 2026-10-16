<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

use Multiapi\Arco\Config\AppConfig;

/** The log groups of an environment, by --source. */
final class LogGroups
{
    /** @return list<string> */
    public static function for(AppConfig $config, string $source): array
    {
        // Web Functions write to /aws/lambda/web/<function> and, for some events, "<group>.local".
        $web = '/aws/lambda/web/' . $config->functionName();
        $console = '/aws/lambda/' . $config->consoleFunctionName();
        return match ($source) {
            'web' => [$web, "$web.local"],
            'console' => [$console],
            'all' => [$web, "$web.local", $console],
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
