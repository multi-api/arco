<?php declare(strict_types=1);

// Arco console runtime: entrypoint of the <app>-<env>-console function (provided.al2023), started by
// /var/task/bootstrap. Resolves secret references once, then runs one command per invocation.

use Multiapi\Arco\ConsoleRuntime\Handler;
use Multiapi\Arco\ConsoleRuntime\InvalidEvent;
use Multiapi\Arco\ConsoleRuntime\PhpIni;
use Multiapi\Arco\ConsoleRuntime\ProcessRunner;
use Multiapi\Arco\ConsoleRuntime\Response;
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

    ['resolved' => $resolved, 'missing' => $missing] = (new Secrets($env['AWS_REGION'] ?? ''))->resolve($env);
    if ($missing !== []) {
        $log(['arco' => 'secrets_missing', 'missing' => $missing]);
        $api->initError('Arco.SecretsMissing', 'These secret references do not exist or are not readable: ' . implode(', ', $missing));
        exit(1);
    }
    $tmp = $env['ARCO_TMP'] ?? '/tmp/arco';
    Storage::prepare($tmp);
    $childEnv = [...$env, ...$resolved, 'HOME' => $tmp];
    // phpIni() from arco.php, as the web runtime applies it: an .ini file every command's PHP scans.
    $childEnv = [...$childEnv, ...PhpIni::prepare(PhpIni::withDefaults(is_array($config['phpIni'] ?? null) ? $config['phpIni'] : []), "$tmp/php", $childEnv)];
    $binary = $env['ARCO_PHP_BINARY'] ?? "$root/bin/frankenphp";
    // FrankenPHP's php-cli shifts $argv by one; console-cli.php gives scripts PHP's argv back.
    $php = basename($binary) === 'frankenphp' ? [$binary, 'php-cli', __DIR__ . '/console-cli.php'] : [$binary];
    $handler = new Handler(
        new ProcessRunner(static function (string $chunk) use ($stdout): void { fwrite($stdout, $chunk); }),
        $php,
        "$root/app",
        $entry,
        $childEnv,
        $log,
    );
    $log(['arco' => 'console_ready', 'secrets' => count($resolved)]);
} catch (\Throwable $e) {
    $log(['arco' => 'fatal', 'error' => $e->getMessage()]);
    $api->initError('Arco.InitError', $e->getMessage());
    exit(1);
}

while (true) {
    $invocation = $api->next();
    try {
        $remaining = $invocation['deadlineMs'] - (int) floor(microtime(true) * 1000);
        $result = $handler->handle($invocation['body'], $remaining);
        $api->respond($invocation['requestId'], Response::encode($result['exitCode'], $result['output']));
    } catch (InvalidEvent $e) {
        $api->invocationError($invocation['requestId'], 'Arco.InvalidEvent', $e->getMessage());
    } catch (\Throwable $e) {
        $log(['arco' => 'invocation_error', 'error' => $e->getMessage()]);
        $api->invocationError($invocation['requestId'], 'Arco.RuntimeError', $e->getMessage());
    }
}
