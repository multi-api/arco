# Configuration reference

`arco.php` at the project root returns an `App`:

```php
<?php

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\Runtime;

return App::make('myapp')
    ->region('us-east-1')
    ->concurrency(48)
    ->runtime(fn (Runtime $r) => $r
        ->workers(min: 4, max: 64)
        ->phpIni(['memory_limit' => '512M']))
    ->exclude(['docs/**'])
    ->environment('staging', fn (Environment $env) => $env
        ->maxInstances(2)
        ->variables(['APP_ENV' => 'staging', 'APP_KEY' => 'arco-ssm:/myapp/staging/app-key']))
    ->environment('production', fn (Environment $env) => $env
        ->account('222222222222')
        ->concurrency(64)
        ->variables(['APP_ENV' => 'production', 'APP_KEY' => 'arco-ssm:/myapp/production/app-key']));
```

The file is loaded in a separate PHP process. Validation runs before any build or AWS call.

## Inheritance

Every setting resolves as: **Arco default < app level < environment level**. `variables()` and
`phpIni()` merge by key (later levels win per key); scalar settings are replaced. `arco info`
shows each resolved value with its source (`default`, `app` or `environment`).

Settings, `variables()`, `permissions()`, `runtime()` and the custom domain methods can be set at app level or inside `environment()`.
`domain()` is the exception to per-key merging: an environment's domain list replaces the app's list as a whole.
`region()`, `exclude()` and `include()` exist only at app level; `account()` only on an environment.

## Settings

| Method | Default | Maps to |
|---|---|---|
| `App::make(name)` | required | App name. Function name is `<app>-<env>` (max 64 chars, lowercase letters, digits, hyphens, starting with a letter) |
| `region(string)` | required | AWS region; must be one of us-east-1, us-east-2, us-west-2, ap-northeast-1, eu-west-1 |
| `concurrency(int)` | AWS default (64) | Upper bound of requests each instance handles at once: revision `maxConcurrencyPerEnvironment` (1 to 128). Lambda applies its own dynamic limit below it |
| `timeout(int)` | 30 | Revision `timeoutSeconds` (3 to 900) |
| `maxInstances(int)` | unset | Maximum running instances of the function: endpoint `scalingConfig.maxEnvironments` (2 to 10000). `maxEnvironments(int)` is accepted as an alias |
| `rateLimit(int)` | unset | Endpoint `throttleConfig.rateLimit` (requests per second: 100 to 1000 in steps of 100, or 2000 to 10000 in steps of 1000) |
| `auth('public'\|'iam')` | `public` | Endpoint `authType`: `public` is `ApplicationManaged`, `iam` is `IamAuth` |
| `logLevel(string)` | `info` | Revision log level: trace, debug, info, warn, error, fatal |
| `role(arn)` | managed | Your own execution role ARN instead of the managed `arco-<app>-<env>` role |
| `kmsKey(arn)` | unset | Revision `kmsKeyArn` |
| `profile(string)` | unset | AWS profile for the environment (see getting started) |
| `account(string)` | written on first deploy | 12-digit account lock; environment level only |
| `variables(array)` | preset defaults | Revision environment variables |
| `permissions(array)` | `[]` | Extra IAM statements for the managed execution role (below) |
| `exclude(array)` / `include(array)` | preset rules | Packaging globs (below) |
| `domain(string\|array)` | none | Custom domains served through CloudFront (below); lowercase hostnames, no wildcards, at most 10 |
| `dns(string\|false)` | Route 53 in the deploy account | `dns('profile')`: Route 53 in the account of that AWS profile; `dns(false)`: DNS is elsewhere, Arco prints the records |
| `deployCommands(array)` | `[]` | Commands run on the console function during every deploy, before traffic moves; an environment's list replaces the app's |
| `console(bool = true)` | off | Deploy the console function (`arco command`, `arco tinker`, `deployCommands()`); `console(false)` deletes an existing one on the next deploy; a scheduler implies it |
| `scheduler(int\|false)` | off | Run Laravel's `schedule:run` every N minutes (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30 or 60), aligned to the UTC clock; needs the console, which it turns on |
| `logRetention(int\|false)` | not managed | Days CloudWatch keeps the web and console logs (1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922, 3288 or 3653); `false` = never expire; not set = Arco leaves the groups as they are |
| `consoleTimeout(int)` | 900 | Timeout of the console function in seconds (3 to 900) |
| `protected(bool = true)` | on for `production` | Ask for confirmation before `arco tinker`; `protected(false)` turns it off |
| `certificate(arn)` | looked up or requested | ACM certificate ARN in `us-east-1` to use for the domains |

An *environment* in Arco is an `environment()` in `arco.php` (staging, production), each deployed as
its own Web Function. AWS calls one running copy of a function an *execution environment*; Arco calls
that an **instance**, so `maxInstances()` caps how many instances one Arco environment runs.

Variable names must be letters, digits and underscores (not starting with a digit); names
reserved by Lambda are rejected: `_HANDLER`, `_X_AMZN_TRACE_ID`, `AWS_REGION`, `AWS_DEFAULT_REGION`, `AWS_EXECUTION_ENV`, `AWS_ACCESS_KEY`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SECRET_KEY`, `AWS_SESSION_TOKEN`, `LAMBDA_TASK_ROOT`, `LAMBDA_RUNTIME_DIR` and anything starting with `AWS_LAMBDA_` or `AWS_XRAY_`. Values may be references
(`arco-ssm:...`, `arco-secret:...`).

### Deploy commands

Each `deployCommands()` entry is an artisan command line for the Laravel preset (the runtime runs
`php artisan <entry>`), or a script and its arguments for plain PHP (`bin/console app:sync`). They run one
by one and the first failure stops the deploy, so for Laravel apps `arco deploy` and `arco init` warn about
entries that cannot work on Lambda:

- Entries starting with `artisan` or `php artisan`: Arco runs artisan for you, so `artisan migrate` would
  run `php artisan artisan migrate` and fail. Write `migrate --force`.
- Commands that write into the code directory, which is read-only on Lambda (`/var/task`), and fail:
  `config:cache`, `route:cache`, `event:cache`, `optimize`, `storage:link`. The build already runs
  `event:cache`.
- Commands that do nothing on Lambda: `config:clear`, `route:clear`, `event:clear`, `optimize:clear`.
  The files they delete are in the read-only code directory, so they exit without error and change
  nothing (`optimize:clear` still runs `cache:clear` and `view:clear`; run those directly if you need them).
  `view:cache` and `view:clear` work: compiled views live under `LARAVEL_STORAGE_PATH` (`/tmp`).
- Commands Laravel asks to confirm in production without `--force` (nobody can answer):
  `migrate`, `migrate:rollback`, `migrate:reset`, `migrate:fresh`, `migrate:refresh`, `db:seed`,
  `db:wipe`, `key:generate`, `queue:clear`. Write `migrate --force`.
- `migrate:fresh`, `migrate:refresh`, `migrate:reset` and `db:wipe` drop every table on each deploy:
  they almost never belong in `deployCommands()`; run them once with `arco command` if you must.

### Sizing

Lambda decides how many requests each instance gets with a dynamic limit based on the instance's
resource usage, and keeps spare warm instances ahead of traffic. `concurrency()` only caps that limit
(AWS default: 64), so Arco leaves it unset unless you set it.

Measured on a production Laravel app, through CloudFront over HTTP/2, ramping to 100 concurrent
requests (`/up`):

| `concurrency` | throughput at 100 concurrent | p95 | errors | instances started |
|---|---|---|---|---|
| 32 | 723 req/s | 142 ms | 0 | 41 |
| unset (AWS 64) | 729 req/s | 138 ms | 0 | 13 |

Forcing a low value gave the same throughput with three times the instances (more memory billed, more
database connections). A single instance with no Lambda routing in front of it (Docker, 2 vCPU / 2 GB,
full Inertia page) peaked at ~190 req/s around 16 concurrent requests, CPU-bound; so set a lower
`concurrency()` only for CPU-heavy apps whose latency grows with load. Use `maxInstances()` to cap
instances (cost, database connections).

## Changing settings from the CLI

`arco set <key> <value>` and `arco unset <key>` edit `arco.php` for you:

```bash
vendor/bin/arco set concurrency 16 --env=production
vendor/bin/arco set max-instances 20 --env=production
vendor/bin/arco set rate-limit 100 --env=production
vendor/bin/arco set timeout 28 --env=production
vendor/bin/arco set log-level debug --env=production
vendor/bin/arco unset rate-limit --env=production
vendor/bin/arco set timeout 60 --app          # app level (all environments)
```

| Key | Method | Accepted values |
|---|---|---|
| `concurrency` | `concurrency(int)` | 1 to 128 |
| `max-instances` | `maxInstances(int)` | 2 to 10000 |
| `rate-limit` | `rateLimit(int)` | 100–1000 (steps of 100) or 2000–10000 (steps of 1000) |
| `timeout` | `timeout(int)` | 3 to 900 |
| `log-level` | `logLevel(string)` | trace, debug, info, warn, error, fatal |

- Pass exactly one of `--env=<name>` or `--app`. With a single environment and neither flag, that
  environment is used (and Arco says so).
- The value is checked with the same rules as validation before anything is written.
- `set` adds or replaces the `->method(value)` call in the environment's `fn (Environment $env) => $env ...`
  closure (a bare `->environment('x')` becomes one), or on the `App::make(...)` chain with `--app`.
  An existing `maxEnvironments(...)` is replaced by `maxInstances(...)`. With `--app`, Arco warns
  about environments that set their own value, since the app-level value does not apply there.
- `unset` removes the call so the app-level or default value applies. If the setting is not set at
  that level, it says so and changes nothing.
- The edit is tokenizer-based and safe: the new file is checked with `php -l`, written atomically,
  then loaded again to confirm that only that setting changed (otherwise the original is restored).
  When the call has a shape Arco does not edit safely (a value built from variables or functions,
  named arguments, the call written twice, an app built in several statements), nothing is written and the
  command prints the line to change by hand, exiting non-zero.
- Only `arco.php` changes: run `arco deploy --env=<env>` to apply. (Applying a setting without a
  redeploy, e.g. `--apply`, may come later.)

## Permissions

The managed execution role only gets what Arco itself needs: writing logs (web and console), reading
the console function's FrankenPHP binary (only used when it downloads it) and the secrets your variables reference. When your app uses other AWS
resources (an existing bucket, a DynamoDB table, SES), add IAM statements with `permissions()`, in the same shape as an IAM
policy statement:

```php
App::make('shop')
    ->region('us-east-1')
    ->permissions([
        ['Effect' => 'Allow', 'Action' => ['s3:GetObject', 's3:PutObject'], 'Resource' => 'arn:aws:s3:::shop-files/*'],
        ['Effect' => 'Allow', 'Action' => 'ses:SendRawEmail', 'Resource' => '*'],
    ])
    ->environment('production', fn (Environment $env) => $env
        ->permissions([
            ['Effect' => 'Allow', 'Action' => ['dynamodb:GetItem', 'dynamodb:PutItem'], 'Resource' => 'arn:aws:dynamodb:us-east-1:123456789012:table/shop-production-cache'],
        ]));
```

- App and environment statements are combined (app first) and added after Arco's own statements.
- Each statement needs `Effect` (`Allow` or `Deny`), `Action` (or `NotAction`) and `Resource`
  (or `NotResource`), each a string or a non-empty list. An optional `Sid` must be alphanumeric
  and must not start with `Arco` (reserved). `Condition` is passed through as is.
- IAM limits the role's policy to 10,240 characters; a larger policy stops the deploy before
  anything changes. Use wildcards to stay under it.
- With `role(arn)` Arco does not manage the role, so `permissions()` is ignored with a warning.

## Console function

The console function is **opt-in**: by default `arco deploy` creates only the web function. Turn it on with
`->console()` in `arco.php` (or `arco console:add`); `->console(false)` (or `arco console:remove`) deletes it
on the next deploy, and a failed delete is a warning. `arco command`, `arco tinker`, `deployCommands()` and
`arco deploy --migrate` need it and fail before touching AWS when it is off, naming `arco console:add`.
`arco info` shows it as `on`, `off` or `not set` (in your language); in `--json`, `settings.console` is `true`,
`false` or `null`, `settings.scheduler` the minutes or `null`, and `settings.logRetention` the days, `false`
(never expire) or `null` (not managed), as `arco.php` writes them.

**Upgrading to 0.4.** An environment deployed with Arco 0.2 or 0.3 already has a console function. If
`arco.php` has no `console()` setting for it, `arco deploy` stops before changing anything: add `->console()`
to keep it, or `->console(false)` to delete it.

When on, `arco deploy` creates or updates `<app>-<env>-console` (runtime `provided.al2023`, arm64, 2048 MB,
1024 MB of `/tmp`, timeout `consoleTimeout()`; the name must fit Lambda's 64 characters), with the web
revision's variables, secrets and execution role. It publishes a version and points the `live` alias at it
once traffic has moved and the health check passed. If AWS refuses that alias move (for example a profile
without `lambda:UpdateAlias`), the deploy still succeeds with a warning: the web revision is live, but
`arco command` and `arco tinker` keep running the previous version until the next deploy. `arco command`
and `arco tinker` invoke `live`; deploy
commands invoke the version published by the deploy itself. The deploy keeps the live version plus the 4
newest and prunes older ones best-effort. Its logs go to `/aws/lambda/<app>-<env>-console`, which the
managed role may write. `arco remove` deletes it (log groups are kept).

Lambda limits a classic function to 250 MB of unzipped code, so the console function gets a copy of the
package **without** the FrankenPHP binary and the Node web runtime. With current runtime builds it carries
the build's static PHP CLI instead (`bin/php`, about 91 MB, same PHP version and extensions, so about 159 MB of the 250 MB
are left for your app, in the units `arco package` prints) and runs it directly, with no download at cold start. The CLI is fetched from GitHub releases
once per runtime build and machine and cached in `~/.arco/cache/runtime/<build>/php`.

The console downloads FrankenPHP instead for a custom `binary()` (the deploy output notes it), and with a
warning in the deploy output for a runtime
build without a PHP CLI (`1.13.0-r1`) or unknown to this Arco version, when `bin/php` alone would take the
console over 250 MB (then `->exclude([...])` leaves room), or when the PHP CLI cannot be downloaded from
GitHub (the warning names the URL and the error; a size or checksum mismatch still stops the deploy). In those cases the deploy uploads the exact binary of the web revision once to
the code bucket (`runtime/<build>/frankenphp`); at a cold start the console function downloads it (about 2.5 s
for 160 MB, once per execution environment), checks its SHA-256 and keeps it in `/tmp`. The managed role may
read `runtime/*` of the code bucket in every case; with your own `role()`, allow `s3:GetObject` on
`arn:aws:s3:::arco-<account>-<region>/runtime/*` (needed whenever a console version that downloads can run)
and `logs:CreateLogStream` / `logs:PutLogEvents` on the console log group. If the console package is
still over 250 MB, the web function deploys, the console function is skipped with a warning and deploy
commands are refused: leave large folders out with `->exclude([...])`.

### Scheduler

`->scheduler(N)` (or `arco scheduler:set [N]`) runs Laravel's `schedule:run` every N minutes on the console
function, through an EventBridge rule named `arco-<function>-scheduler` (for example `arco-shop-production-scheduler`) that invokes the `live` alias. N is
one of 1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30 or 60. The rule is clock-aligned cron in UTC (every 5 minutes
fires at :00, :05, :10 and so on, whenever the rule was created), so `->everyFiveMinutes()` tasks line up
with it. The scheduler needs the console, so `scheduler()` turns it on and `console(false)` with a scheduler
is a config error. `scheduler:set` and `scheduler:remove` also write `->console()` where the console is not
set, so `scheduler:remove` turns the scheduler off and keeps the console; `console:remove`
refuses while a scheduler runs.

`arco scheduler:set` without N suggests an interval from your tasks: it runs
`php artisan arco:schedule-intervals` (from `multiapi/arco-laravel`) with `ARCO=1` in the environment, so
any `env('ARCO')` branch in your own app sees it while the schedule is read. A deploy warns when a task
needs a shorter interval than the one configured (for example an `everyMinute()` task with `scheduler(5)`).
The rule is only created or updated after the console function was activated successfully, so it never
fires at an older version, and it is deleted when the scheduler is off. If EventBridge cannot be read
(missing `events:DescribeRule`), the deploy warns instead of guessing.

### Log retention

`->logRetention(30)` (or `arco logs:retention 30`) sets the CloudWatch retention of the web function's log
group and, when the console is on, the console's; creating the groups first when they do not exist yet.
`->logRetention(false)` (`arco logs:retention never`) makes them never expire. With no setting Arco does not
touch retention; `arco logs:retention --remove` goes back to that. Allowed days are the ones CloudWatch
accepts (1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922,
3288, 3653).

### Runtime binary clean-up

Each deploy that needs the FrankenPHP binary in the code bucket uploads it to `runtime/<build>/...`. After a
deploy Arco deletes `runtime/*` objects older than 24 hours that no published version of any Arco function in the region
(found by the `arco:app` tag, all versions, since any can be rolled back to) references; the 24-hour grace
period protects a deploy that has uploaded a binary but not yet published.
This is best effort: when it cannot list or delete, the deploy warns and goes on.

## Custom domains

Lambda Web Function endpoints only answer on their own `*.on.aws` domain. With `domain()`, `arco
deploy` puts the endpoint behind a CloudFront distribution with your hostnames:

```php
App::make('shop')
    ->region('us-east-1')
    ->environment('production', fn (Environment $env) => $env
        ->domain(['shop.example.com', 'api.example.com'])
        ->dns('dns-account'));           // optional, see below
```

You rarely need to write these lines yourself: `arco init` asks for the domains and where their DNS is,
and `arco domain:add` / `arco domain:remove` change them later (see
[Getting started](getting-started.md#custom-domains-in-init)).

After the function is deployed and verified, `deploy`:

1. **Certificate** (ACM, always `us-east-1`, deploy account). With `certificate(arn)` that
   certificate must be issued and cover every domain. Otherwise Arco reuses an issued certificate
   whose names cover all domains (an exact name, or a `*.parent` wildcard covering one label),
   preferring one it requested for this environment, then the one with fewest names. If there is
   none it requests one (DNS validation, tagged `arco:managed=true`) and validates it.
   Only certificates CloudFront accepts are used: Amazon-issued or imported (not private CA), with
   an RSA 2048 or ECDSA P-256 key; `certificate(arn)` with any other one stops the deploy.
2. **CloudFront** through the CloudFormation stack `arco-<app>-<env>` (in the app region): a
   distribution with your domains, HTTPS only, no caching except the preset's asset paths
   (`/build/*` for Laravel), and a viewer-request function that passes the original host to the
   app as `X-Forwarded-Host`. The first creation takes several minutes; later deploys only
   update the stack when something changed.
3. **DNS**: `A` and `AAAA` alias records to the distribution.

The summary lists `https://<domain>` for every domain next to the endpoint URL.

### Where the DNS records go

| Config | DNS |
|---|---|
| nothing | Route 53 of the deploy account. A domain with no public hosted zone there falls back to manual. |
| `dns('profile')` | Route 53 of the account behind that AWS profile (for example a shared DNS account). `--dns-profile=` on `deploy` and `remove` overrides it. |
| `dns(false)` | Not in Route 53. Arco prints the certificate validation `CNAME`s and waits up to 30 minutes for the certificate, then prints `CNAME <domain> → <distribution>.cloudfront.net` for you to create. |

For each record Arco picks the longest public hosted zone that is a suffix of the name. It never
takes over a live record: if a domain already has an `A`, `AAAA` or `CNAME` record that does not
point at this environment's distribution, `deploy` stops before creating anything and names the
record. `deploy --force-dns` replaces such records.

At a zone apex (`example.com`), most DNS providers do not allow a `CNAME`; in manual mode use your
provider's ALIAS/ANAME record type.

### Remove

`remove` deletes the alias records that point at this environment's distribution (Route 53 modes
only), then the stack, then the certificate if Arco requested it for this environment. Certificates
you created are never deleted. Certificate validation `CNAME`s (`_….<domain>`) are always kept: they
are harmless, and ACM reuses the same record for any certificate of that name in any region of the
account. Delete them yourself if no certificate needs them.

### Limits

- `auth('iam')` cannot be combined with `domain()` (CloudFront would have to sign requests).
- The origin read timeout is `timeout() + 2` seconds, at most 60 (CloudFront's default limit).
- One distribution per environment; no WAF, no apex redirects.

## Runtime options

Set with `->runtime(fn (Runtime $r) => ...)`.

| Method | Default | Meaning |
|---|---|---|
| `build(string)` | the default build of your Arco version (`1.13.0-r1`) | Exact runtime build to deploy; builds are immutable releases of `multi-api/arco-runtime` |
| `frankenphp(string)` | unset | Shortcut: the highest revision of that FrankenPHP version known to your Arco version. Cannot be combined with `build()` |
| `binary(path)` | unset | Your own Linux arm64 FrankenPHP binary; must exist and be an arm64 ELF |
| `workers(min, max)` | min 4, max 64 | Octane worker count / FrankenPHP `max_threads` (`min >= 1`, `max >= min`) |
| `threads(int)` | 8 | FrankenPHP `num_threads` for non-worker requests (>= 1) |
| `mode('direct'\|'proxy')` | `direct` | How the runtime hands requests to FrankenPHP |
| `phpIni(array)` | `[]` | `php.ini` overrides, merged by key; applied to web requests and to commands on the console function (`arco command`, deploy commands, tinker) |

### Runtime builds

A given Arco release always deploys the same bytes: each runtime build is immutable and listed
in the manifest shipped with Arco. Upgrading Arco (or pinning `->build()`) is how you take a
newer build. Downloads are verified by size and SHA-256 and cached in
`~/.arco/cache/runtime/<build>/`.

## Packaging globs

`exclude()` and `include()` take lists of glob patterns matched against paths relative to the
project root. Patterns from the preset and from `exclude()` are combined. Rules, as implemented:

- A leading `/` is ignored. Patterns are always anchored at the project root and must match
  the whole relative path: `*.log` matches only `x.log` at the root, `**/*.log` matches at any depth.
- `*` matches any characters except `/`.
- `?` matches exactly one character except `/`.
- `**/` matches zero or more directories; `**` elsewhere matches anything including `/`
  (`storage/logs/**` matches everything below `storage/logs/`).
- Every other character is literal; there are no `[...]` classes or `{a,b}` alternatives.
- A directory is tested as `name` and as `name/`; if it is excluded, its contents are not packaged.
- Symlinks are skipped.

Evaluation order for each path:

1. Never packaged: `.env` and `.env.*` in any directory (`**/.env`, `**/.env.*`). No `include`
   pattern can bring them back. Keep secrets in SSM or Secrets Manager.
2. If an `include` pattern matches, the path is packaged.
3. A directory that is the literal prefix of an `include` pattern (for example `storage/app` for
   `storage/app/keep/**`) is kept so the include can reach inside it.
4. Otherwise, if an `exclude` pattern matches (always-excluded, preset or yours), the path is skipped.

Always excluded (an `include` can override them): `.git/**`, `.arco/**`, `node_modules/**`,
`vendor/**`, editor and CI folders (`.idea`, `.vscode`, `.fleet`, `.zed`, `.github`), AI assistant config
(`.claude`, `.superpowers`, `.ai`, `.junie`, `.cursor`, `.windsurf`, `.gemini`, `.codex`, `.aider*`,
`.mcp.json`), `.DS_Store` files, `arco-*.zip` at the project root, `storage/framework/down` (Laravel's maintenance-mode flag; shipping it would put the
deployed app in maintenance mode). `vendor/` is rebuilt in the staging copy with
`composer install --no-dev --optimize-autoloader`. A root `auth.json` (Composer credentials for
private repositories) is copied so that install can use it, then deleted from the staging copy
before packaging. The unzipped package must be 512 MB or less;
otherwise Arco lists the largest directories.

`arco package` writes the zip to `.arco/build/arco-<app>-<env>.zip` by default (`.arco/` is
git-ignored and never packaged). An `--output` path inside the project is always left out of the
next package, so a previous zip never ends up inside a new one.

The zip also contains `arco-runtime.json` (the runtime options above) and `arco-package.json`, a
manifest that identifies the package (app, environment, preset, runtime build, content hash, Arco
version, creation time) so `arco deploy --package=<zip>` can check it. Variables are not
packaged; `deploy --package` takes them from the current `arco.php`.

## Laravel preset

Detected when `artisan` exists and `composer.json` requires `laravel/framework`. Requires
`laravel/octane`; the worker script is copied from Octane's FrankenPHP stub.

- Build step: `php artisan event:cache`. There is no `route:cache` (a cached closure route such
  as `/up` would embed the build path) and no `config:cache` (secrets are resolved at runtime).
- Default variables (yours override them): `LARAVEL_STORAGE_PATH=/tmp/arco/storage`,
  `LOG_CHANNEL=stderr`, `SESSION_DRIVER=cookie`, `OCTANE_SERVER=frankenphp`.
- Default excludes: `storage/**` (storage lives in `/tmp` at runtime, so packaged files there
  would never be read; keep files your app needs in S3 or in the code tree),
  `bootstrap/cache/*.php`, `public/hot`, `public/frankenphp-worker.php`, `tests/**`.
- Health check used by `deploy`: `/up`.

Without a framework preset, the plain PHP preset serves `public/` with FrankenPHP and checks `/`.
