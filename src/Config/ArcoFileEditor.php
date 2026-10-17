<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

use PhpToken;
use Symfony\Component\Process\Process;

/**
 * @phpstan-type Option int|bool|string|array<array-key, int|string>
 * @phpstan-type Changes array{domains?: list<string>, dns?: string|false|null, settings?: array<string, int|string|bool|array<string, Option>|null>, queues?: array<string, array<string, Option>|false|null>, storage?: array<string, array<string, Option>|false|null>}
 */
final class ArcoFileEditor
{
    /** Older method names that write the same setting; an edit replaces them with the current name. */
    private const ALIASES = ['maxInstances' => ['maxEnvironments']];
    /** The order queue() options are written in. */
    private const QUEUE_OPTIONS = ['existing', 'default', 'timeout', 'memory', 'concurrency', 'batchSize', 'tries', 'dlq', 'fifo'];
    /**
     * Calls keyed by a name in their first argument, such as ->queue('jobs', ...): the raw config key
     * they load into and the order their named options are written in.
     */
    private const NAMED = [
        'queue' => ['key' => 'queues', 'options' => self::QUEUE_OPTIONS],
        'storage' => ['key' => 'storage', 'options' => ['existing', 'default', 'public', 'expire', 'cors']],
    ];
    /** Settings written as a call without a name: ->cache(), ->cache(false) or ->cache(existing: 'x'). */
    private const SINGLETONS = ['cache', 'failedJobsTable'];
    /** Writes ->account('...') for an environment. Returns false when arco.php has an unexpected shape. */
    public static function addAccount(string $arcoPhpPath, string $environment, string $account): bool
    {
        $source = (string) file_get_contents($arcoPhpPath);
        $env = preg_quote($environment, '/');

        $withClosure = '/(->environment\(\s*[\'"]' . $env . '[\'"]\s*,\s*(?:static\s+)?fn\s*\([^)]*\)\s*=>\s*\$\w+)/';
        $updated = preg_replace($withClosure, "\$1\n        ->account('$account')", $source, 1, $count);

        if ($count === 0) {
            $bare = '/->environment\(\s*([\'"])' . $env . '\1\s*\)/';
            $updated = preg_replace($bare, "->environment('$environment', fn (\\Multiapi\\Arco\\Config\\Environment \$env) => \$env->account('$account'))", $source, 1, $count);
        }

        if ($count === 0 || ! is_string($updated)) {
            return false;
        }
        file_put_contents($arcoPhpPath, $updated);
        return true;
    }

    /**
     * Sets the environment's ->domain([...]) call; an empty list removes it. Returns false, without
     * writing, when arco.php has a shape this editor does not understand (the caller then shows the
     * line to add by hand). Throws ConfigException when the result would not parse or not load as intended.
     *
     * @param list<string> $domains
     */
    public static function setDomains(string $arcoPhpPath, string $environment, array $domains): bool
    {
        return self::update($arcoPhpPath, $environment, ['domains' => $domains]);
    }

    /** Sets the environment's ->dns(...) call; null removes it (Route 53 in the deploy account). */
    public static function setDns(string $arcoPhpPath, string $environment, string|false|null $dns): bool
    {
        return self::update($arcoPhpPath, $environment, ['dns' => $dns]);
    }

    /**
     * Sets a scalar setting call such as ->concurrency(16) in an environment, or on the App chain
     * when $environment is null; a null value removes the call so the inherited value applies.
     * The method is the setting's key (concurrency, timeout, maxInstances, rateLimit, logLevel).
     * Returns false, without writing, when arco.php has a shape this editor does not understand.
     */
    public static function setSetting(string $arcoPhpPath, ?string $environment, string $method, int|string|bool|null $value): bool
    {
        return self::update($arcoPhpPath, $environment, ['settings' => [$method => $value]]);
    }

    /**
     * Sets the ->queue('<name>', ...) call (see setNamed()).
     *
     * @param array<string, Option>|false|null $options
     */
    public static function setQueue(string $arcoPhpPath, ?string $environment, string $name, array|false|null $options): bool
    {
        return self::setNamed($arcoPhpPath, $environment, 'queue', $name, $options);
    }

    /** @param array<string, Option>|false $options */
    public static function queueLine(string $name, array|false $options): string
    {
        return self::namedLine('queue', $name, $options);
    }

    /**
     * Sets a named call such as ->queue('<name>', ...) or ->storage('<name>', ...) in an environment,
     * or on the App chain when $environment is null: options write named arguments, false writes
     * ->method('<name>', false) and null removes the call. Other calls of the method are matched by
     * their first argument and left alone; a new one goes after the last one. Returns false, without
     * writing, when arco.php has a shape this editor does not understand (such as the same name twice,
     * or a non-literal argument).
     *
     * @param 'queue'|'storage' $method
     * @param array<string, Option>|false|null $options
     */
    public static function setNamed(string $arcoPhpPath, ?string $environment, string $method, string $name, array|false|null $options): bool
    {
        return self::update($arcoPhpPath, $environment, [self::NAMED[$method]['key'] => [$name => $options]]);
    }

    /**
     * @param 'queue'|'storage' $method
     * @param array<string, Option>|false $options
     */
    public static function namedLine(string $method, string $name, array|false $options): string
    {
        $args = [var_export($name, true)];
        if ($options === false) {
            $args[] = 'false';
        } else {
            $ordered = array_replace(array_intersect_key(array_flip(self::NAMED[$method]['options']), $options), $options);
            foreach ($ordered as $key => $value) {
                $args[] = $key . ': ' . self::literal($value);
            }
        }
        return '->' . $method . '(' . implode(', ', $args) . ')';
    }

    /**
     * Sets a call without a name such as ->cache() (true), ->cache(false) or ->cache(existing: 'x')
     * (an array of named arguments), in an environment or on the App chain; null removes it.
     *
     * @param array<string, Option>|bool|null $value
     */
    public static function setSingleton(string $arcoPhpPath, ?string $environment, string $method, array|bool|null $value): bool
    {
        if (! in_array($method, self::SINGLETONS, true)) {
            return false;
        }
        return self::update($arcoPhpPath, $environment, ['settings' => [$method => $value]]);
    }

    /** @param array<string, Option>|bool $value */
    public static function singletonLine(string $method, array|bool $value): string
    {
        if (! is_array($value)) {
            return self::settingLine($method, $value);
        }
        $args = [];
        foreach ($value as $key => $option) {
            $args[] = $key . ': ' . self::literal($option);
        }
        return '->' . $method . '(' . implode(', ', $args) . ')';
    }

    /**
     * Whether named arguments can be written as literals the editor reads back: identifier names, and
     * values that are an int, string, bool, a list of strings or a map of (non-numeric) strings to ints.
     *
     * @param array<mixed> $options
     */
    private static function writable(array $options): bool
    {
        foreach ($options as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z_]\w*$/', $key) !== 1) {
                return false;
            }
            if (is_array($value)) {
                $list = array_is_list($value);
                foreach ($value as $k => $item) {
                    if ($list ? ! is_string($item) : (! is_string($k) || ! is_int($item))) {
                        return false;
                    }
                }
            } elseif (! is_int($value) && ! is_string($value) && ! is_bool($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Strict comparison that ignores the order of string-keyed entries (the loader's option order
     * differs from the order of the changes) but not the order of lists.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        return self::normalized($a) === self::normalized($b);
    }

    private static function normalized(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::normalized(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    /**
     * PHP source for a literal value: an int, string or bool, a list of strings (['a/', 'b/']) or a
     * map of strings to ints (['tmp/' => 1]).
     *
     * @param Option $value
     */
    private static function literal(int|bool|string|array $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (! is_array($value)) {
            return var_export($value, true);
        }
        $items = [];
        foreach ($value as $key => $item) {
            $item = is_int($item) ? (string) $item : var_export($item, true);
            $items[] = array_is_list($value) ? $item : var_export((string) $key, true) . ' => ' . $item;
        }
        return '[' . implode(', ', $items) . ']';
    }

    /** Whether arco.php calls an older name of $method (such as maxEnvironments for maxInstances). */
    public static function usesAlias(string $arcoPhpPath, string $method): bool
    {
        $source = (string) @file_get_contents($arcoPhpPath);
        foreach (self::ALIASES[$method] ?? [] as $alias) {
            if (preg_match('/->\s*' . preg_quote($alias, '/') . '\s*\(/', $source) === 1) {
                return true;
            }
        }
        return false;
    }

    /** true renders a no-argument call such as ->console(); false renders ->console(false). */
    public static function settingLine(string $method, int|string|bool $value): string
    {
        if ($value === true) {
            return '->' . $method . '()';
        }
        return '->' . $method . '(' . (is_int($value) ? (string) $value : var_export($value, true)) . ')';
    }

    /**
     * Applies every change in memory, then writes once and checks the result (see verifiedWrite()).
     * Returns false, writing nothing, when any change has a shape this editor does not understand.
     * A null environment means the App chain (settings only).
     *
     * @param Changes $changes
     */
    public static function update(string $arcoPhpPath, ?string $environment, array $changes): bool
    {
        if ($environment === null && (array_key_exists('domains', $changes) || array_key_exists('dns', $changes))) {
            return false;
        }
        $source = (string) file_get_contents($arcoPhpPath);
        $updated = $source;
        foreach ($changes['settings'] ?? [] as $method => $value) {
            if ($updated !== null) {
                if ($value === null) {
                    $line = null;
                } elseif (in_array($method, self::SINGLETONS, true)) {
                    if (! (is_bool($value) || (is_array($value) && self::writable($value)))) {
                        return false;
                    }
                    $line = self::singletonLine($method, $value);
                } elseif (is_array($value)) {
                    return false;
                } else {
                    $line = self::settingLine($method, $value);
                }
                $updated = self::edit($updated, $environment, $method, $line);
            }
        }
        if ($updated !== null && array_key_exists('domains', $changes)) {
            $domains = $changes['domains'];
            $updated = self::edit($updated, $environment, 'domain', $domains === [] ? null : self::domainLine($domains));
        }
        if ($updated !== null && array_key_exists('dns', $changes)) {
            $dns = $changes['dns'];
            $updated = self::edit($updated, $environment, 'dns', $dns === null ? null : self::dnsLine($dns));
        }
        foreach (self::NAMED as $method => ['key' => $key]) {
            foreach ($changes[$key] ?? [] as $name => $options) {
                if ($updated !== null) {
                    $name = (string) $name;
                    if (is_array($options) && ! self::writable($options)) {
                        return false;
                    }
                    $updated = self::edit($updated, $environment, $method, $options === null ? null : self::namedLine($method, $name, $options), $name);
                }
            }
        }
        if ($updated === null) {
            return false;
        }
        if ($updated === $source) {
            // Nothing to write, so the file must already say what was asked (e.g. a removal of a
            // call that lives in another environment() call is not a success).
            try {
                $raw = self::load($arcoPhpPath);
            } catch (ConfigException) {
                return false;
            }
            return self::same($raw, self::expected($raw, $environment, $changes));
        }
        self::verifiedWrite($arcoPhpPath, $updated, $environment, $changes);
        return true;
    }

    /** @param list<string> $domains */
    public static function domainLine(array $domains): string
    {
        return '->domain([' . implode(', ', array_map(fn (string $d) => var_export($d, true), $domains)) . '])';
    }

    public static function dnsLine(string|false $dns): string
    {
        return '->dns(' . ($dns === false ? 'false' : var_export($dns, true)) . ')';
    }

    /**
     * Writes $contents (see write()), then loads the new arco.php and checks that it equals the old
     * configuration with only the asked changes (in the environment, or the app when null). On any
     * difference or load error the original bytes are restored and ConfigException editor.verify_failed is thrown.
     *
     * @param Changes $changes
     */
    public static function verifiedWrite(string $arcoPhpPath, string $contents, ?string $environment, array $changes): void
    {
        $original = (string) file_get_contents($arcoPhpPath);
        $before = self::load($arcoPhpPath);
        self::write($arcoPhpPath, $contents);
        try {
            $ok = self::same(self::load($arcoPhpPath), self::expected($before, $environment, $changes));
        } catch (ConfigException) {
            $ok = false;
        }
        if (! $ok) {
            self::replace($arcoPhpPath, $original);
            throw ConfigException::make($environment === null ? 'editor.verify_failed_app' : 'editor.verify_failed', ['path' => $arcoPhpPath, 'environment' => (string) $environment]);
        }
    }

    /** Atomic write: a temp file next to arco.php, checked with `php -l`, then renamed over it. */
    public static function write(string $arcoPhpPath, string $contents): void
    {
        $temp = self::temp($arcoPhpPath, $contents);
        $lint = new Process([PHP_BINARY, '-l', $temp]);
        $lint->run();
        if (! $lint->isSuccessful()) {
            @unlink($temp);
            throw ConfigException::make('editor.lint_failed', [
                'path' => $arcoPhpPath,
                'output' => trim(str_replace($temp, $arcoPhpPath, $lint->getOutput() . "\n" . $lint->getErrorOutput())),
            ]);
        }
        self::rename($temp, $arcoPhpPath);
    }

    private static function replace(string $path, string $contents): void
    {
        self::rename(self::temp($path, $contents), $path);
    }

    private static function temp(string $path, string $contents): string
    {
        $temp = $path . '.arco-' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $contents) === false) {
            throw ConfigException::make('editor.write_failed', ['path' => $path]);
        }
        $perms = @fileperms($path);
        if ($perms !== false) {
            @chmod($temp, $perms & 0777);
        }
        return $temp;
    }

    private static function rename(string $temp, string $path): void
    {
        if (! rename($temp, $path)) {
            @unlink($temp);
            throw ConfigException::make('editor.write_failed', ['path' => $path]);
        }
    }

    /** @return array<string, mixed> */
    private static function load(string $path): array
    {
        return (new ConfigLoader())->load($path);
    }

    /**
     * The loaded configuration as it should be after the changes.
     *
     * @param array<string, mixed> $raw
     * @param Changes $changes
     * @return array<string, mixed>
     */
    private static function expected(array $raw, ?string $environment, array $changes): array
    {
        $settings = $changes['settings'] ?? [];
        if (array_key_exists('dns', $changes)) {
            $settings['dns'] = $changes['dns'];
        }
        $named = [];
        foreach (self::NAMED as ['key' => $key]) {
            if (($changes[$key] ?? []) !== []) {
                $named[$key] = $changes[$key];
            }
        }
        if ($environment === null) {
            /** @var array<string, mixed> $app */
            $app = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
            $raw['settings'] = self::withSettings($app, $settings);
            foreach ($named as $key => $entries) {
                /** @var array<string, mixed> $current */
                $current = is_array($raw[$key] ?? null) ? $raw[$key] : [];
                $raw[$key] = self::withSettings($current, $entries);
            }
            return $raw;
        }
        /** @var array<string, array<string, mixed>> $environments */
        $environments = is_array($raw['environments'] ?? null) ? $raw['environments'] : [];
        $env = $environments[$environment] ?? ['settings' => [], 'variables' => [], 'runtime' => [], 'permissions' => [], 'domains' => null, 'queues' => [], 'storage' => []];
        if (array_key_exists('domains', $changes)) {
            $env['domains'] = $changes['domains'] === [] ? null : $changes['domains'];
        }
        if ($settings !== []) {
            /** @var array<string, mixed> $current */
            $current = is_array($env['settings'] ?? null) ? $env['settings'] : [];
            $env['settings'] = self::withSettings($current, $settings);
        }
        foreach ($named as $key => $entries) {
            /** @var array<string, mixed> $current */
            $current = is_array($env[$key] ?? null) ? $env[$key] : [];
            $env[$key] = self::withSettings($current, $entries);
        }
        $environments[$environment] = $env;
        $raw['environments'] = $environments;
        return $raw;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $changes null removes the key
     * @return array<string, mixed>
     */
    private static function withSettings(array $current, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }
        return $current;
    }

    /**
     * The source with the call set, replaced or removed (in the environment's closure, or on the App
     * chain when $environment is null); null when the shape is not recognised. With a $key, only the
     * calls whose first argument is that string literal count (queue('name', ...), storage('name', ...)), and a new call
     * goes after the last call of $method.
     */
    private static function edit(string $source, ?string $environment, string $method, ?string $line, ?string $key = null): ?string
    {
        $nl = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $t = array_values(array_filter(PhpToken::tokenize($source), fn (PhpToken $token) => ! $token->isIgnorable()));
        $depths = self::depths($t);
        $methods = [$method, ...(self::ALIASES[$method] ?? [])];

        $scope = $environment === null
            ? self::appScope($t, $depths, $source, $methods, $nl)
            : self::environmentScope($t, $depths, $source, $environment, $methods, $nl);
        if ($scope === null) {
            return null;
        }
        ['calls' => $calls, 'at' => $at, 'prefix' => $prefix] = $scope;
        if ($key !== null && $calls !== []) {
            $last = self::matching($t, $depths, $calls[count($calls) - 1] + 2);
            if ($last === null) {
                return null;
            }
            $at = $t[$last]->pos + 1;
            $keyed = [];
            foreach ($calls as $call) {
                $first = self::firstLiteral($t, $call);
                if ($first === null) {
                    return null;
                }
                if ($first === $key) {
                    $keyed[] = $call;
                }
            }
            $calls = $keyed;
        }
        if (count($calls) > 1) {
            return null;
        }

        if ($calls === []) {
            // An app-level domain() list would still apply where the environment has none.
            if ($method === 'domain' && self::hasAppLevelCall($t, $depths, $method)) {
                return null;
            }
            return $line === null ? $source : substr_replace($source, $prefix . $line, $at, 0);
        }

        $start = $calls[0];
        $argsOpen = $start + 2;
        $argsClose = self::matching($t, $depths, $argsOpen);
        if ($argsClose === null || ! self::isLiteral(array_slice($t, $argsOpen + 1, $argsClose - $argsOpen - 1), $method)) {
            return null;
        }
        $from = $t[$start]->pos;
        $to = $t[$argsClose]->pos + 1;
        if ($line !== null) {
            return substr_replace($source, $line, $from, $to - $from);
        }
        // Removal: a call alone on its line takes the whole line with it; otherwise only the call
        // goes, so a comment at the end of the previous line never swallows the rest of the chain.
        $lineStart = strrpos(substr($source, 0, $from), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($source, "\n", $to);
        $lineEnd = $lineEnd === false ? strlen($source) : $lineEnd + 1;
        if (trim(substr($source, $lineStart, $from - $lineStart)) === '' && trim(substr($source, $to, $lineEnd - $to)) === '') {
            return substr_replace($source, '', $lineStart, $lineEnd - $lineStart);
        }
        return substr_replace($source, '', $from, $to - $from);
    }

    /**
     * Where an environment's calls live: the calls of $methods in its closure, and where a new call goes.
     *
     * @param list<PhpToken> $t
     * @param list<int> $depths
     * @param list<string> $methods
     * @return array{calls: list<int>, at: int, prefix: string}|null
     */
    private static function environmentScope(array $t, array $depths, string $source, string $environment, array $methods, string $nl): ?array
    {
        $env = self::findEnvironment($t, $depths, $environment);
        if ($env === null) {
            return null;
        }
        [$open, $close] = $env;
        $head = $close; // bare ->environment('x')
        $next = $open + 2;
        if (self::is($t[$next], ',')) {
            $head = self::arrowHead($t, $next + 1, $close);
            if ($head === null) {
                return null;
            }
        } elseif (! self::is($t[$next], ')')) {
            return null;
        }

        $calls = [];
        for ($i = $head + 1; $i < $close - 2; $i++) {
            if ($depths[$i] === $depths[$open] + 1 && self::isAnyCall($t, $i, $methods)) {
                $calls[] = $i;
            }
        }
        $indent = self::lineIndent($source, $t[$open - 2]->pos) . '    ';
        if ($head === $close) {
            return ['calls' => $calls, 'at' => $t[$close]->pos, 'prefix' => ", fn (\\Multiapi\\Arco\\Config\\Environment \$env) => \$env$nl$indent"];
        }
        $after = $head;
        // dns() goes right after the environment's domain() call, when there is one.
        for ($i = $head + 1; $methods[0] === 'dns' && $i < $close - 2; $i++) {
            if ($depths[$i] === $depths[$open] + 1 && self::isCall($t, $i, 'domain')) {
                $after = self::matching($t, $depths, $i + 2) ?? $head;
                break;
            }
        }
        return ['calls' => $calls, 'at' => $t[$after]->pos + strlen($t[$after]->text), 'prefix' => "$nl$indent"];
    }

    /**
     * The App::make(...)->...; chain: the calls of $methods on it, and where a new call goes (after
     * ->region(...) when present, else after App::make(...)). Null when there is not exactly one
     * App::make(), or a call of $methods at the chain's level is outside the chain (e.g. $app->timeout()).
     *
     * @param list<PhpToken> $t
     * @param list<int> $depths
     * @param list<string> $methods
     * @return array{calls: list<int>, at: int, prefix: string}|null
     */
    private static function appScope(array $t, array $depths, string $source, array $methods, string $nl): ?array
    {
        $makes = [];
        foreach ($t as $i => $token) {
            if (in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && preg_match('/(^|\\\\)App$/', $token->text) === 1
                && isset($t[$i + 3]) && $t[$i + 1]->id === T_DOUBLE_COLON
                && $t[$i + 2]->id === T_STRING && strcasecmp($t[$i + 2]->text, 'make') === 0
                && self::is($t[$i + 3], '(')) {
                $makes[] = $i;
            }
        }
        if (count($makes) !== 1) {
            return null;
        }
        $class = $makes[0];
        $j = self::matching($t, $depths, $class + 3);
        if ($j === null) {
            return null;
        }
        $after = $j;
        $chain = [];
        $calls = [];
        while (isset($t[$j + 3]) && ($t[$j + 1]->id === T_OBJECT_OPERATOR || $t[$j + 1]->id === T_NULLSAFE_OBJECT_OPERATOR)
            && $t[$j + 2]->id === T_STRING && self::is($t[$j + 3], '(')) {
            $arrow = $j + 1;
            $chain[] = $arrow;
            $j = self::matching($t, $depths, $arrow + 2);
            if ($j === null) {
                return null;
            }
            if (self::isAnyCall($t, $arrow, $methods)) {
                $calls[] = $arrow;
            }
            if (strcasecmp($t[$arrow + 1]->text, 'region') === 0) {
                $after = $j;
            }
        }
        foreach ($t as $i => $token) {
            if ($depths[$i] === $depths[$class] && ! in_array($i, $chain, true) && self::isAnyCall($t, $i, $methods)) {
                return null;
            }
        }
        $first = $chain[0] ?? null;
        $indent = $first !== null && trim(self::lineBefore($source, $t[$first]->pos)) === ''
            ? self::lineIndent($source, $t[$first]->pos)
            : self::lineIndent($source, $t[$class]->pos) . '    ';
        return ['calls' => $calls, 'at' => $t[$after]->pos + strlen($t[$after]->text), 'prefix' => "$nl$indent"];
    }

    /**
     * @param list<PhpToken> $t
     * @param list<string> $methods
     */
    private static function isAnyCall(array $t, int $i, array $methods): bool
    {
        foreach ($methods as $method) {
            if (self::isCall($t, $i, $method)) {
                return true;
            }
        }
        return false;
    }

    /** A real one-character token (not text inside a string that happens to be a bracket). */
    private static function is(PhpToken $token, string $char): bool
    {
        return $token->id === ord($char) && $token->text === $char;
    }

    private static function isOpener(PhpToken $token): bool
    {
        return self::is($token, '(') || self::is($token, '[') || self::is($token, '{')
            || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES;
    }

    private static function isCloser(PhpToken $token): bool
    {
        return self::is($token, ')') || self::is($token, ']') || self::is($token, '}');
    }

    /**
     * Nesting depth before each token.
     *
     * @param list<PhpToken> $t
     * @return list<int>
     */
    private static function depths(array $t): array
    {
        $depths = [];
        $depth = 0;
        foreach ($t as $token) {
            if (self::isCloser($token)) {
                $depth--;
            }
            $depths[] = $depth;
            if (self::isOpener($token)) {
                $depth++;
            }
        }
        return $depths;
    }

    /**
     * Index of "(" and of its ")" for the one App::make(...)->...->environment('name', ...) call.
     * Null when there is none, more than one, or the call is on another receiver.
     *
     * @param list<PhpToken> $t
     * @param list<int> $depths
     * @return array{0: int, 1: int}|null
     */
    private static function findEnvironment(array $t, array $depths, string $environment): ?array
    {
        $found = [];
        for ($i = 0; $i + 3 < count($t); $i++) {
            if (self::isCall($t, $i, 'environment') && $t[$i + 3]->id === T_CONSTANT_ENCAPSED_STRING && substr($t[$i + 3]->text, 1, -1) === $environment) {
                $found[] = $i;
            }
        }
        if (count($found) !== 1 || ! self::isAppChain($t, $depths, $found[0])) {
            return null;
        }
        $close = self::matching($t, $depths, $found[0] + 2);
        return $close === null ? null : [$found[0] + 2, $close];
    }

    /**
     * Whether the receiver of the call at $arrow is a chain that starts with App::make(...).
     *
     * @param list<PhpToken> $t
     * @param list<int> $depths
     */
    private static function isAppChain(array $t, array $depths, int $arrow): bool
    {
        $j = $arrow - 1;
        while ($j > 0 && self::is($t[$j], ')')) {
            $open = self::opening($t, $depths, $j);
            if ($open === null || $open < 3 || $t[$open - 1]->id !== T_STRING) {
                return false;
            }
            $operator = $t[$open - 2];
            if ($operator->id === T_OBJECT_OPERATOR || $operator->id === T_NULLSAFE_OBJECT_OPERATOR) {
                $j = $open - 3;
                continue;
            }
            $class = $t[$open - 3];
            return $operator->id === T_DOUBLE_COLON
                && strcasecmp($t[$open - 1]->text, 'make') === 0
                && in_array($class->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && preg_match('/(^|\\\\)App$/', $class->text) === 1;
        }
        return false;
    }

    /**
     * For `fn (Type $param) => $param` starting at $i, the index of the body's `$param`. The body must
     * start with the parameter, followed by ->, ?-> or the ")" ($limit) closing environment().
     *
     * @param list<PhpToken> $t
     */
    private static function arrowHead(array $t, int $i, int $limit): ?int
    {
        if ($t[$i]->id === T_STATIC) {
            $i++;
        }
        if ($t[$i]->id !== T_FN || ! self::is($t[$i + 1], '(')) {
            return null;
        }
        $parameters = [];
        for ($j = $i + 2; $j < $limit && ! self::is($t[$j], ')'); $j++) {
            if ($t[$j]->id === T_VARIABLE) {
                $parameters[] = $t[$j]->text;
            }
        }
        if (count($parameters) !== 1) {
            return null;
        }
        for (; $j < $limit; $j++) {
            if ($t[$j]->id === T_DOUBLE_ARROW) {
                $head = $j + 1;
                $after = $t[$head + 1] ?? null;
                $chains = $after !== null && ($head + 1 === $limit || $after->id === T_OBJECT_OPERATOR || $after->id === T_NULLSAFE_OBJECT_OPERATOR);
                return $t[$head]->id === T_VARIABLE && $t[$head]->text === $parameters[0] && $chains ? $head : null;
            }
        }
        return null;
    }

    /** @param list<PhpToken> $t */
    private static function isCall(array $t, int $i, string $method): bool
    {
        return isset($t[$i + 2])
            && ($t[$i]->id === T_OBJECT_OPERATOR || $t[$i]->id === T_NULLSAFE_OBJECT_OPERATOR)
            && $t[$i + 1]->id === T_STRING && strcasecmp($t[$i + 1]->text, $method) === 0
            && self::is($t[$i + 2], '(');
    }

    /**
     * @param list<PhpToken> $t
     * @param list<int> $depths
     */
    private static function hasAppLevelCall(array $t, array $depths, string $method): bool
    {
        foreach ($t as $i => $token) {
            if ($depths[$i] === 0 && self::isCall($t, $i, $method)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<PhpToken> $t
     * @param list<int> $depths
     */
    private static function matching(array $t, array $depths, int $open): ?int
    {
        for ($i = $open + 1; $i < count($t); $i++) {
            if ($depths[$i] === $depths[$open] && self::isCloser($t[$i])) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @param list<PhpToken> $t
     * @param list<int> $depths
     */
    private static function opening(array $t, array $depths, int $close): ?int
    {
        for ($i = $close - 1; $i >= 0; $i--) {
            if ($depths[$i] === $depths[$close] && self::isOpener($t[$i])) {
                return $i;
            }
        }
        return null;
    }

    /**
     * The value of the string literal that is the whole first argument of the call at $i, or null.
     *
     * @param list<PhpToken> $t
     */
    private static function firstLiteral(array $t, int $i): ?string
    {
        if (! isset($t[$i + 4]) || $t[$i + 3]->id !== T_CONSTANT_ENCAPSED_STRING || ! (self::is($t[$i + 4], ',') || self::is($t[$i + 4], ')'))) {
            return null;
        }
        $text = $t[$i + 3]->text;
        $body = substr($text, 1, -1);
        return $text[0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
    }

    /**
     * Literal call arguments: each one optionally named (name: value) and a scalar or an array literal
     * (see isArrayLiteral()). With $named, the first is a positional string literal (the name).
     *
     * @param list<PhpToken> $args
     */
    private static function isArgsLiteral(array $args, bool $named): bool
    {
        $groups = self::split($args);
        if ($groups !== [] && end($groups) === []) {
            array_pop($groups); // trailing comma
        }
        if ($named && ($groups === [] || count($groups[0]) !== 1 || $groups[0][0]->id !== T_CONSTANT_ENCAPSED_STRING)) {
            return false;
        }
        foreach ($groups as $group) {
            if (isset($group[1]) && self::is($group[1], ':') && preg_match('/^[A-Za-z_]\w*$/', $group[0]->text) === 1) {
                $group = array_slice($group, 2);
            }
            $ok = count($group) === 1 ? self::isScalarToken($group[0]) : self::isArrayLiteral($group);
            if (! $ok) {
                return false;
            }
        }
        return true;
    }

    /**
     * ['a', 'b'] (strings only) or ['a' => 1, 'b' => 2] (string keys, integer values); nothing nested.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isArrayLiteral(array $tokens): bool
    {
        $count = count($tokens);
        if ($count < 2 || ! self::is($tokens[0], '[') || ! self::is($tokens[$count - 1], ']')) {
            return false;
        }
        $items = self::split(array_slice($tokens, 1, $count - 2));
        if ($items !== [] && end($items) === []) {
            array_pop($items);
        }
        $shapes = [];
        foreach ($items as $item) {
            if (count($item) === 1 && $item[0]->id === T_CONSTANT_ENCAPSED_STRING) {
                $shapes['list'] = true;
            } elseif (count($item) === 3 && $item[0]->id === T_CONSTANT_ENCAPSED_STRING && $item[1]->id === T_DOUBLE_ARROW && $item[2]->id === T_LNUMBER) {
                $shapes['map'] = true;
            } else {
                return false;
            }
        }
        return count($shapes) <= 1;
    }

    /**
     * Tokens split at the commas outside brackets.
     *
     * @param list<PhpToken> $tokens
     * @return list<list<PhpToken>>
     */
    private static function split(array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }
        $groups = [[]];
        $depth = 0;
        foreach ($tokens as $token) {
            if (self::isCloser($token)) {
                $depth--;
            }
            if ($depth === 0 && self::is($token, ',')) {
                $groups[] = [];
            } else {
                $groups[count($groups) - 1][] = $token;
            }
            if (self::isOpener($token)) {
                $depth++;
            }
        }
        return $groups;
    }

    private static function isScalarToken(PhpToken $token): bool
    {
        return in_array($token->id, [T_LNUMBER, T_CONSTANT_ENCAPSED_STRING], true)
            || ($token->id === T_STRING && in_array(strtolower($token->text), ['true', 'false', 'null'], true));
    }

    /** @param list<PhpToken> $args */
    private static function isLiteral(array $args, string $method): bool
    {
        if (isset(self::NAMED[$method]) || in_array($method, self::SINGLETONS, true)) {
            return self::isArgsLiteral($args, isset(self::NAMED[$method]));
        }
        if ($method !== 'domain' && $method !== 'dns') {
            // A scalar setting: no argument, or exactly one integer, plain string or true/false.
            if ($args === []) {
                return true;
            }
            return count($args) === 1 && (
                in_array($args[0]->id, [T_LNUMBER, T_CONSTANT_ENCAPSED_STRING], true)
                || ($args[0]->id === T_STRING && in_array(strtolower($args[0]->text), ['true', 'false'], true))
            );
        }
        foreach ($args as $token) {
            $ok = $token->id === T_CONSTANT_ENCAPSED_STRING
                || self::is($token, '[') || self::is($token, ']') || self::is($token, ',')
                || ($method === 'dns' && $token->id === T_STRING && strtolower($token->text) === 'false');
            if (! $ok) {
                return false;
            }
        }
        return true;
    }

    /** The text from the start of $pos's line up to $pos. */
    private static function lineBefore(string $source, int $pos): string
    {
        $lineStart = strrpos(substr($source, 0, $pos), "\n");
        return substr($source, $lineStart === false ? 0 : $lineStart + 1, $pos - ($lineStart === false ? 0 : $lineStart + 1));
    }

    private static function lineIndent(string $source, int $pos): string
    {
        $lineStart = strrpos(substr($source, 0, $pos), "\n");
        $line = substr($source, $lineStart === false ? 0 : $lineStart + 1);
        return preg_match('/^[ \t]*/', $line, $m) ? $m[0] : '';
    }
}
