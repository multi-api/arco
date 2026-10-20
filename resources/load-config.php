<?php declare(strict_types=1);

// Runs in a child process: php load-config.php <arco.php> <autoload.php> <output.json>
[, $configPath, $autoload, $outputPath] = $argv;

require $autoload;

try {
    $app = (static fn () => require $configPath)();
} catch (\Error $error) {
    // Methods removed in 0.9: say what replaces them instead of a bare "undefined method".
    if (preg_match('/^Call to undefined method .+::(maxEnvironments|consoleTimeout)\(\)$/', $error->getMessage(), $match) === 1) {
        fwrite(STDERR, 'ARCO_REMOVED_METHOD:' . $match[1]);
        exit(4);
    }
    // Signatures changed in 0.9: say what to write now. Any other error keeps PHP's own text.
    $changed = match (true) {
        $error->getMessage() === 'Unknown named parameter $path' => 'binary_path',
        $error->getMessage() === 'Unknown named parameter $profile' => 'dns_profile',
        $error instanceof \TypeError && preg_match('/::(cache|failedJobsTable)\(\): Argument #1 \(\$enabled\) must be of type bool, null given/', $error->getMessage(), $match) === 1 => 'null_' . $match[1],
        default => null,
    };
    if ($changed !== null) {
        fwrite(STDERR, 'ARCO_CHANGED_SIGNATURE:' . $changed);
        exit(5);
    }
    throw $error;
}

if (! $app instanceof \Multiapi\Arco\Config\App) {
    fwrite(STDERR, 'ARCO_INVALID_RETURN:' . get_debug_type($app));
    exit(3);
}

file_put_contents($outputPath, json_encode($app->toArray(), JSON_THROW_ON_ERROR));
