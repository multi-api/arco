<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

use PhpToken;
use Symfony\Component\Process\Process;

final class ArcoFileEditor
{
    /** Older method names that write the same setting; an edit replaces them with the current name. */
    private const ALIASES = ['maxInstances' => ['maxEnvironments']];
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
     * @param array{domains?: list<string>, dns?: string|false|null, settings?: array<string, int|string|bool|null>} $changes
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
                $updated = self::edit($updated, $environment, $method, $value === null ? null : self::settingLine($method, $value));
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
            return $raw == self::expected($raw, $environment, $changes);
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
     * @param array{domains?: list<string>, dns?: string|false|null, settings?: array<string, int|string|bool|null>} $changes
     */
    public static function verifiedWrite(string $arcoPhpPath, string $contents, ?string $environment, array $changes): void
    {
        $original = (string) file_get_contents($arcoPhpPath);
        $before = self::load($arcoPhpPath);
        self::write($arcoPhpPath, $contents);
        try {
            $ok = self::load($arcoPhpPath) == self::expected($before, $environment, $changes);
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
     * @param array{domains?: list<string>, dns?: string|false|null, settings?: array<string, int|string|bool|null>} $changes
     * @return array<string, mixed>
     */
    private static function expected(array $raw, ?string $environment, array $changes): array
    {
        $settings = $changes['settings'] ?? [];
        if (array_key_exists('dns', $changes)) {
            $settings['dns'] = $changes['dns'];
        }
        if ($environment === null) {
            /** @var array<string, mixed> $app */
            $app = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
            $raw['settings'] = self::withSettings($app, $settings);
            return $raw;
        }
        /** @var array<string, array<string, mixed>> $environments */
        $environments = is_array($raw['environments'] ?? null) ? $raw['environments'] : [];
        $env = $environments[$environment] ?? ['settings' => [], 'variables' => [], 'runtime' => [], 'permissions' => [], 'domains' => null];
        if (array_key_exists('domains', $changes)) {
            $env['domains'] = $changes['domains'] === [] ? null : $changes['domains'];
        }
        if ($settings !== []) {
            /** @var array<string, mixed> $current */
            $current = is_array($env['settings'] ?? null) ? $env['settings'] : [];
            $env['settings'] = self::withSettings($current, $settings);
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
     * chain when $environment is null); null when the shape is not recognised.
     */
    private static function edit(string $source, ?string $environment, string $method, ?string $line): ?string
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

    /** @param list<PhpToken> $args */
    private static function isLiteral(array $args, string $method): bool
    {
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
