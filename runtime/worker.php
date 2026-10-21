<?php declare(strict_types=1);

// Arco worker runtime: entrypoint of a queue's worker function (<app>-<env>-queue-<name>), started by
// /var/task/bootstrap when ARCO_WORKER_QUEUE is set. Prepares the environment exactly like
// runtime/console.php (secrets, storage, phpIni), then becomes `php artisan arco:work`, which boots
// Laravel once and serves every invocation through the Runtime API.

use Multiapi\Arco\ConsoleRuntime\PhpIni;
use Multiapi\Arco\ConsoleRuntime\RuntimeApi;
use Multiapi\Arco\ConsoleRuntime\Secrets;
use Multiapi\Arco\ConsoleRuntime\Storage;

foreach (glob(__DIR__ . '/console/*.php') ?: [] as $file) {
    require_once $file;
}

$started = microtime(true);
$stdout = fopen('php://stdout', 'wb');
$log = static function (array $data) use ($started, $stdout): void {
    $data['ms'] = (int) round((microtime(true) - $started) * 1000);
    fwrite($stdout, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
};

/** @var array<string, string> $env */
$env = getenv();
$root = $env['LAMBDA_TASK_ROOT'] ?? dirname(__DIR__);
$api = new RuntimeApi($env['AWS_LAMBDA_RUNTIME_API'] ?? '127.0.0.1:9001');

try {
    $config = json_decode((string) file_get_contents("$root/arco-runtime.json"), true);
    $console = is_array($config) && is_array($config['console'] ?? null) ? $config['console'] : [];
    $entry = is_string($console['entry'] ?? null) ? $console['entry'] : null;
    if ($entry === null) {
        throw new \RuntimeException('The app has no artisan entry point: a queue worker needs Laravel (artisan).');
    }
    $artisan = "$root/app/$entry";
    if (! is_file($artisan)) {
        throw new \RuntimeException("The artisan entry point $artisan does not exist.");
    }

    ['resolved' => $resolved, 'missing' => $missing] = (new Secrets($env['AWS_REGION'] ?? ''))->resolve($env);
    if ($missing !== []) {
        throw new \RuntimeException('These secret references do not exist or are not readable: ' . implode(', ', $missing));
    }
    $tmp = $env['ARCO_TMP'] ?? '/tmp/arco';
    Storage::prepare($tmp);
    $childEnv = [...$env, ...$resolved, 'HOME' => $tmp];
    $childEnv = [...$childEnv, ...PhpIni::prepare(PhpIni::withDefaults(is_array($config['phpIni'] ?? null) ? $config['phpIni'] : []), "$tmp/php", $childEnv)];
    $binary = $env['ARCO_PHP_BINARY'] ?? "$root/bin/frankenphp";
    // FrankenPHP's php-cli shifts $argv by one; console-cli.php gives scripts PHP's argv back.
    $php = basename($binary) === 'frankenphp' ? [$binary, 'php-cli', __DIR__ . '/console-cli.php'] : [$binary];
    $arguments = [...array_slice($php, 1), $artisan, 'arco:work'];
    if (! chdir("$root/app")) {
        throw new \RuntimeException("Cannot enter $root/app.");
    }
    $log(['arco' => 'worker_ready', 'queue' => $env['ARCO_WORKER_QUEUE'] ?? null, 'secrets' => count($resolved)]);
} catch (\Throwable $e) {
    $log(['arco' => 'fatal', 'error' => $e->getMessage()]);
    $api->initError('Arco.WorkerInit', $e->getMessage());
    exit(1);
}

/** @var array<string, string> $childEnv */
if (function_exists('pcntl_exec')) {
    // The worker replaces this process: no wrapper to forward signals or the exit code.
    pcntl_exec($php[0], $arguments, $childEnv);
    // Only reached when the exec failed: fall through to the child process.
}
$process = proc_open([$php[0], ...$arguments], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, $childEnv);
if (! is_resource($process)) {
    $log(['arco' => 'fatal', 'error' => "Cannot start {$php[0]}."]);
    $api->initError('Arco.WorkerInit', "Cannot start {$php[0]}.");
    exit(1);
}
exit(proc_close($process));
