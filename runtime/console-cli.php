<?php declare(strict_types=1);

// Runs `<entry> <args>` with the argv PHP's own CLI would give it. FrankenPHP's php-cli puts its binary
// in $argv[0] and the script in $argv[1], where PHP puts the script in $argv[0], so artisan would
// read its own name as the command. runtime/console.php runs commands as
// `frankenphp php-cli runtime/console-cli.php <entry> <args>`.

array_shift($argv); // FrankenPHP: its binary; PHP: this script
if (isset($argv[0]) && basename($argv[0]) === basename(__FILE__)) {
    array_shift($argv); // FrankenPHP: this script
}
if ($argv === []) {
    fwrite(STDERR, "No script to run.\n");
    exit(1);
}
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = $argc = count($argv);
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $argv[0];
require $argv[0];
