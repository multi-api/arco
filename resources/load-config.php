<?php declare(strict_types=1);

// Runs in a child process: php load-config.php <arco.php> <autoload.php> <output.json>
[, $configPath, $autoload, $outputPath] = $argv;

require $autoload;

$app = (static fn () => require $configPath)();

if (! $app instanceof \Multiapi\Arco\Config\App) {
    fwrite(STDERR, 'ARCO_INVALID_RETURN:' . get_debug_type($app));
    exit(3);
}

file_put_contents($outputPath, json_encode($app->toArray(), JSON_THROW_ON_ERROR));
