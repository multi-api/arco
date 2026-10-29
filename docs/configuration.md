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
        ->php('8.5')
        ->workers(4)
        ->maxThreads(64)
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

Upgrading from an earlier Arco? [Upgrading](upgrading.md) lists every change to `arco.php` per version, and what
1.x keeps stable.

## Names and ownership

Arco names every resource after `<app>-<env>`: the web function `<app>-<env>`, the console function
`<app>-<env>-console`, one worker `<app>-<env>-queue-<queue>` per queue, the execution role
`arco-<app>-<env>`, the scheduler rule `arco-<app>-<env>-scheduler`, the stacks `arco-<app>-<env>` and
`arco-<app>-<env>-resources`. So that these names never collide across apps and environments:

- app and environment names must not end in `-console`, `-resources`, `-scheduler` or `-host`, and must
  not contain `-queue-`. The same holds for `<app>-<env>` as a whole when it would name another app's
  resource: app `shop-prod` with environment `queue-emails` (a worker of `shop`/`prod`), app `shop-eu` with
  environment `console` (the console function of `shop`/`eu`). Validation names the rule that a name breaks.
- hyphens are otherwise allowed, so app `shop-eu` with environment `prod` and app `shop` with environment
  `eu-prod` share every name. Arco tells them apart by the `arco:app` and `arco:environment` tags it puts
  on everything it creates: before a deploy changes, or `arco remove` deletes, a function, role or
  scheduler rule, Arco reads its tags and refuses (before changing anything) when they belong to another
  app or environment. A resource with Arco's name but no tags is refused too, unless Arco's own
  description is on it (every Arco version wrote one): then it is this environment's, and the deploy adds
  the tags. `arco deploy --force` overrides a refusal and tags the resource for this environment; it is
  dangerous, so use it only for a resource you know is yours. `arco remove` has no override: it never
  deletes another environment's resources. `arco command`, `arco tinker` and `scheduler:pause`/`scheduler:resume`
  check the console function or scheduler rule the same way, with no override, before they run or change
  anything. An untagged web function counts as Arco's only when its live revision carries Arco's description
  and its zip sits under this environment's `<app>/<env>/` prefix in the code bucket.
- every environment also gets an AWS Resource Group `arco-<app>-<env>` (a tag query on `arco:app` and
  `arco:environment`), so the AWS console shows its resources together; `arco info --resources` lists them in
  the terminal (also `--json`), and `arco remove` prints the same list before it asks. The group is a
  convenience: without `resource-groups:*` permissions the deploy and the remove print a note.

### One region per app

An app and its environments live in the region of `region()` (app level only). The execution role, the
CloudFront function and the bucket names are account-wide, so moving a deployed environment to another
region would leave the old function running with a role rewritten for the new one. Arco tags each new
execution role with `arco:region`, and `arco deploy` refuses, before any change and even with `--force`, when
an existing role carries another region than `region()`. For a second region, use another environment name. A role
created by an Arco before 0.9 has no `arco:region` tag: the next deploy adds it.

## Inheritance

Every setting resolves as: **Arco default < app level < environment level**. `variables()` and
`phpIni()` merge by key (later levels win per key); scalar settings are replaced. `arco info`
shows each resolved value with its source (`default`, `app` or `environment`).

Settings, `variables()`, `permissions()`, `runtime()` and the custom domain methods can be set at app level or inside `environment()`.
`domain()` is the exception to per-key merging: an environment's domain list replaces the app's list as a whole.
`queue()` merges by queue name: an environment's options for a queue go over the app's, and `queue(name, false)` drops it there.
`storage()` and `table()` merge by name the same way (`storage(name, false)`, `table(name, false)` drop one); `cache()` and `failedJobsTable()` are settings
like the others (an environment's call replaces the app's, and `cache(false)` turns it off there).
`region()`, `exclude()` and `include()` exist only at app level; `account()` only on an environment.

## Settings

| Method | Default | Maps to |
|---|---|---|
| `App::make(name)` | required | App name. Function name is `<app>-<env>` (max 56 chars, so that the console function `<app>-<env>-console` fits Lambda's 64; lowercase letters, digits, hyphens, starting with a letter; see [Names and ownership](#names-and-ownership)) |
| `region(string)` | required | AWS region; must be one of us-east-1, us-east-2, us-west-2, ap-northeast-1, eu-west-1; app level only (see [One region per app](#one-region-per-app)) |
| `concurrency(int)` | AWS default (64) | Upper bound of requests each instance handles at once: revision `maxConcurrencyPerEnvironment` (1 to 128). Lambda applies its own dynamic limit below it |
| `timeout(int)` | 30 | Revision `timeoutSeconds` (3 to 900) |
| `maxInstances(int)` | unset | Maximum running instances of the function: endpoint `scalingConfig.maxEnvironments` (2 to 10000). |
| `rateLimit(int)` | unset | Endpoint `throttleConfig.rateLimit` (requests per second: 100 to 1000 in steps of 100, or 2000 to 10000 in steps of 1000) |
| `auth('public'\|'iam')` | `public` | Endpoint `authType`: `public` is `ApplicationManaged`, `iam` is `IamAuth` |
| `logLevel(string)` | `info` | Revision log level: trace, debug, info, warn, error, fatal |
| `role(arn)` | managed | Your own execution role ARN instead of the managed `arco-<app>-<env>` role |
| `permissionsBoundary(string\|false $policyArn)` | unset | IAM permissions boundary (a policy ARN) on the managed execution role `arco-<app>-<env>`; `false` at environment level clears an app-level one; overridden on the deploy host by `--permissions-boundary` or `ARCO_PERMISSIONS_BOUNDARY`; cannot be combined with `role()` (see [Permissions boundary](#permissions-boundary)) |
| `kmsKey(arn)` | unset | Revision `kmsKeyArn` |
| `profile(string)` | unset | AWS profile for the environment. Without it, the first deploy asks and remembers the answer in `.arco/local.php`, not in `arco.php` (see [Choosing the AWS profile](getting-started.md#choosing-the-aws-profile)) |
| `account(string)` | written on first deploy | 12-digit account lock; environment level only |
| `variables(array)` | preset defaults | Revision environment variables. Names starting with `ARCO` are reserved for Arco and refused; Lambda's own (`AWS_REGION`, `AWS_LAMBDA_*`, ...) too |
| `permissions(array)` | `[]` | Extra IAM statements for the managed execution role (below) |
| `exclude(array)` / `include(array)` | preset rules | Packaging globs (below) |
| `domain(string\|array)` | none | Custom domains served through CloudFront (below); lowercase hostnames, no wildcards, at most 10 |
| `dns(string\|false $dns)` | Route 53 in the deploy account | `dns('profile')`: Route 53 in the account of that AWS profile; `dns('cloudflare')`: Cloudflare (see [Cloudflare](#cloudflare)), so a profile named `cloudflare` cannot be used here; `dns(false)`: DNS is elsewhere, Arco prints the records |
| `deployCommands(array)` | `[]` | Commands run on the console function during every deploy, before traffic moves; an environment's list replaces the app's |
| `console(bool = true, ?int timeout, ?int memory)` | off | Deploy the console function (`arco command`, `arco tinker`, `deployCommands()`); `console(false)` deletes an existing one on the next deploy; a scheduler implies it. `timeout:` is in seconds (3 to 900, default 900), `memory:` in MB (128 to 10240, default 2048); giving either also turns the console on |
| `scheduler(int\|false)` | off | Run Laravel's `schedule:run` every N minutes (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30 or 60), aligned to the UTC clock; needs the console, which it turns on |
| `logRetention(int\|false)` | 30 | Days CloudWatch keeps the web, console and queue worker logs (1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922, 3288 or 3653); `false` = never expire; not set = 30 days |
| `protected(bool = true)` | on for `production` | Ask for confirmation before `arco tinker`, `arco command`, `arco remove` and `arco queue:dlq --redrive`; `--yes` answers it, and without a terminal it is required (CI: `arco command "migrate --force" --env=production --no-interaction --yes`). Deploy commands (`deployCommands()`, `arco deploy --migrate`) are not asked. `protected(false)` turns it off |
| `certificate(arn)` | looked up or requested | ACM certificate ARN in `us-east-1` to use for the domains |
| `queue(name, ...)` | none | An SQS queue and the worker function that runs its jobs (see [Queues](#queues)); `queue(name, false)` drops an app-level queue from an environment |
| `storage(name, ...)` | none | An S3 bucket, used as a Laravel disk (see [Storage](#storage)); `storage(name, false)` drops an app-level storage from an environment |
| `cache(bool = true, existing: ?string, driver: 'dynamodb')` | off | A DynamoDB table for Laravel's cache (see [Cache and failed-jobs tables](#cache-and-failed-jobs-tables)) |
| `failedJobsTable(bool = true, existing: ?string)` | off | A DynamoDB table for Laravel's failed jobs (same section) |
| `table(name, ttl: ?string, existing: ?string)` | none | A DynamoDB table of your own (`PK`/`SK` strings, on demand) for the app to use through the AWS SDK (see [Tables](#tables)); `table(name, false)` drops an app-level table from an environment |
| `vpc(subnets: [...], securityGroups: [...], endpoints: [...])` | none | Run the environment's Web Function, console, scheduler and workers inside your VPC through a Lambda network connector (see [VPC](#vpc)); 1 to 16 subnets and 1 to 5 security groups; `endpoints:` makes sure S3 and/or DynamoDB go through free gateway endpoints (see [Gateway endpoints](#gateway-endpoints)); `vpc(false)` at environment level clears an app-level `vpc()` |

An *environment* in Arco is an `environment()` in `arco.php` (staging, production), each deployed as
its own Web Function. AWS calls one running copy of a function an *execution environment*; Arco calls
that an **instance**, so `maxInstances()` caps how many instances one Arco environment runs.

Variable names must be letters, digits and underscores (not starting with a digit); names
reserved by Lambda are rejected: `_HANDLER`, `_X_AMZN_TRACE_ID`, `AWS_REGION`, `AWS_DEFAULT_REGION`, `AWS_EXECUTION_ENV`, `AWS_ACCESS_KEY`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SECRET_KEY`, `AWS_SESSION_TOKEN`, `LAMBDA_TASK_ROOT`, `LAMBDA_RUNTIME_DIR` and anything starting with `AWS_LAMBDA_` or `AWS_XRAY_`. Every name
starting with `ARCO` is reserved for Arco and rejected too, so a variable Arco adds later never collides with
one of yours. Values may be references (`arco-ssm:...`, `arco-secret:...`).

### Variables Arco sets

| Variable | Where | Value | Status in 1.x |
|---|---|---|---|
| `ARCO` | web, console and worker functions; `arco init` and `arco scheduler:set` reading the schedule | `1` | stable: test it in your app (`env('ARCO')`) |
| `ARCO_APP` | web, console and worker functions | the app name | stable |
| `ARCO_ENV` | web, console and worker functions | the environment name | stable |
| `ARCO_BUILD` | the preset's build commands (`php artisan event:cache`) on your machine | `1` | stable: test it in code that runs during the build |
| `ARCO_QUEUES`, `ARCO_STORAGE` | web, console and worker functions, with queues / storage | JSON maps, see [Queues](#several-queues-and-onqueue) and [Laravel disks](#laravel-disks) | stable for `multiapi/arco-laravel`; their JSON shape may gain keys |
| `ARCO_CACHE_TABLE`, `ARCO_FAILED_JOBS_TABLE` | web, console and worker functions, with the tables | the table name, see [Cache and failed-jobs tables](#cache-and-failed-jobs-tables) | stable for `multiapi/arco-laravel` |
| `ARCO_TABLES` | web, console and worker functions, with `table()` | JSON map `{"<name>": "<table>"}`, see [Tables](#tables) | stable: read it in your app; its JSON shape may gain keys |
| `ARCO_WORKER_QUEUE`, `ARCO_QUEUE_TRIES`, `ARCO_QUEUE_MAX_RECEIVES`, `ARCO_BINARY_BUCKET`, `ARCO_BINARY_KEY`, `ARCO_BINARY_SHA256`, `ARCO_PHP_BINARY`, `ARCO_TMP`, `ARCO_S3_ENDPOINT` | the runtime of the console and worker functions | | internal: may change in any release, do not read them |

Arco also sets the Laravel variables of the features you use (`QUEUE_CONNECTION`, `SQS_*`, `FILESYSTEM_DISK`,
`AWS_BUCKET`, `AWS_URL`, `CACHE_STORE`, `DYNAMODB_CACHE_TABLE`, `QUEUE_FAILED_DRIVER`; see each section) and the
preset's defaults ([Laravel preset](#laravel-preset)); your own `variables()` win over those. `ARCO_LANG` is
not set by Arco: it is read on your machine to pick the CLI's language.

### Named arguments

`queue()`, `storage()`, `table()`, `cache()`, `failedJobsTable()`, `console()` and the `Runtime` methods documented with
named arguments (`workers()`, `binary()` with `php:`) are covered by the 1.x promise with those names: write
`->queue('emails', timeout: 120)` or `->console(timeout: 300)`. For every other method only the positional form
is covered (`->maxInstances(10)`, not `->maxInstances(count: 10)`), and the order of the options after a queue's,
a storage's or a table's name is not: name them (`->table('state', ttl: 'expires_at')`; a positional
`->table('state', 'expires_at')` is a type error). `toArray()` on `App`, `Environment` and `Runtime` and `Runtime::mode()` are
internal. See [The 1.x promise](upgrading.md#the-1x-promise).

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
| `console-timeout` | `console(timeout:)` | 3 to 900 |
| `console-memory` | `console(memory:)` | 128 to 10240 |

- Pass exactly one of `--env=<name>` or `--app`. With a single environment and neither flag, that
  environment is used (and Arco says so).
- The value is checked with the same rules as validation before anything is written.
- `set` adds or replaces the `->method(value)` call in the environment's `fn (Environment $env) => $env ...`
  closure (a bare `->environment('x')` becomes one), or on the `App::make(...)` chain with `--app`.
  `console-timeout` and `console-memory` write the `timeout:` and `memory:` options of `->console(...)`. With `--app`, Arco warns
  about environments that set their own value, since the app-level value does not apply there.
- `unset` removes the call so the app-level or default value applies. If the setting is not set at
  that level, it says so and changes nothing.
- The edit is tokenizer-based and safe: the new file is checked with `php -l`, written atomically,
  then loaded again to confirm that only that setting changed (otherwise the original is restored).
  When the call has a shape Arco does not edit safely (a value built from variables or functions,
  named arguments, the call written twice, an app built in several statements), nothing is written and the
  command prints the line to change by hand, exiting non-zero.
- Only `arco.php` changes: run `arco deploy --env=<env>` to apply. (`scheduler:set` and `scheduler:remove`
  can also apply their change at once with `--apply`, see [Scheduler](#scheduler).)

## Permissions

The managed execution role only gets what Arco itself needs: writing logs (web, console and queue workers), sending to and reading the queues in `arco.php`, reading and writing the buckets and tables of `storage()`, `cache()`, `failedJobsTable()` and `table()`, reading
the console function's FrankenPHP binary (only used when it downloads it) and the secrets your variables reference. When your app uses other AWS
resources (a bucket or table not in `arco.php`, SES), add IAM statements with `permissions()`, in the same shape as an IAM
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

### Permissions boundary

An IAM [permissions boundary](https://docs.aws.amazon.com/IAM/latest/UserGuide/access_policies_boundaries.html)
caps what the managed execution role can do, whatever its inline policy says. Accounts that hand the deploy
profile to someone else (a CI, a team, Arco Cloud) use it so that whoever deploys cannot give the app's role
more than the account owner allows: see
[Enforcing a permissions boundary](getting-started.md#enforcing-a-permissions-boundary).

```php
App::make('shop')
    ->region('us-east-1')
    ->permissionsBoundary('arn:aws:iam::123456789012:policy/arco-boundary')
    ->environment('production')
    ->environment('sandbox', fn (Environment $env) => $env
        ->permissionsBoundary(false));   // no boundary for this environment
```

- The value is a policy ARN, `arn:<partition>:iam::<12-digit account or aws>:policy/<path/name>`, on one line.
  AWS-managed policies (`arn:aws:iam::aws:policy/PowerUserAccess`) are allowed. Anything else stops `info` and
  `deploy` with `validation.boundary_arn`.
- It is a scalar setting: an environment's value replaces the app's, and `permissionsBoundary(false)` at environment
  level clears an app-level one.
- **The deploy host can set it without editing `arco.php`.** Precedence: `arco deploy --permissions-boundary=<arn>` >
  the environment variable `ARCO_PERMISSIONS_BOUNDARY` > `arco.php`. A CI that must enforce a boundary therefore wins
  over the repository's `arco.php`. The variable is read from the environment of the machine that runs Arco; it is
  not an app variable (`ARCO*` app variables stay reserved). An empty flag or variable (`--permissions-boundary=`,
  `ARCO_PERMISSIONS_BOUNDARY=`) counts as not given, as for `--profile` and `AWS_PROFILE`: a CI that writes
  `--permissions-boundary=$UNSET` deploys with whatever `arco.php` says, and an account that enforces the boundary
  (see the account guard) refuses that deploy at IAM. In `arco.php`, use `false` to clear it: an empty string
  there is not a policy ARN (`validation.boundary_arn`). The flag and variable apply to `arco deploy` and `arco info`
  only; other commands read `arco.php` alone.
- `arco info` shows the effective value in the `permissionsBoundary` row with its source (`app`, `environment`,
  `--permissions-boundary`, `ARCO_PERMISSIONS_BOUNDARY`, or `default` when unset); `arco info --json` has it as
  `permissions_boundary` (a string, or `null`).
- **Not with `role()`.** With your own role Arco creates none, so there is nothing to bound: a boundary from any
  source combined with `role(arn)` is an error (`validation.boundary_with_custom_role`). Set the boundary on your role
  in IAM instead.
- **Deploys.** A new role is created with the boundary. An existing role without it, or with another one, gets it
  (`iam:PutRolePermissionsBoundary`) before its inline policy is written, and the deploy prints
  `deploy.boundary_set`. A role that already has it costs no extra call. The deploy profile needs
  `iam:PutRolePermissionsBoundary` on `role/arco-*` (see
  [Minimal IAM permissions](getting-started.md#minimal-iam-permissions)).
- **Arco never removes a boundary.** When `arco.php` stops setting one, the role keeps the boundary it has and the
  deploy prints the note `deploy.boundary_kept`. Removing it is the account owner's call
  (`aws iam delete-role-permissions-boundary --role-name arco-<app>-<env>`).
- `arco remove` deletes a bounded role like any other.

**Effective permissions are the intersection.** IAM allows an action only when both the role's inline policy
(Arco's statements plus your `permissions()`) and the boundary allow it. An action you add with `permissions()`
that the boundary does not allow **silently does nothing**: the deploy succeeds, and the app gets `AccessDenied`
at runtime. When you add a statement to `permissions()` on a bounded environment, ask the account owner to allow
it in the boundary too.

## Console function

The console function is **opt-in**: by default `arco deploy` creates only the web function. Turn it on with
`->console()` in `arco.php` (or `arco console:add`); `->console(false)` (or `arco console:remove`) deletes it
on the next deploy, and a failed delete is a warning. `arco command`, `arco tinker`, `deployCommands()` and
`arco deploy --migrate` need it and fail before touching AWS when it is off, naming `arco console:add`.
`arco info` shows it as `on`, `off` or `not set` (in your language); in `--json`, `settings.console` is `true`,
`false` or `null`, `settings.scheduler` the minutes or `null` (with `settings.schedulerPaused`, see
Scheduler), `settings.consoleTimeout` the seconds, and `settings.logRetention` the days, `false`
(never expire) or `null` (not set: the 30-day default applies), as `arco.php` writes them.

An environment deployed with Arco 0.2 or 0.3 already has a console function and needs a `console()` setting:
see [Upgrading to 0.4](upgrading.md#04).

When on, `arco deploy` creates or updates `<app>-<env>-console` (runtime `provided.al2023`, arm64, 2048 MB by default (`console(memory:)`),
1024 MB of `/tmp`, timeout `console(timeout:)` (900 s by default); the name must fit Lambda's 64 characters), with the web
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

The console downloads FrankenPHP instead for a custom `binary()` without `php:` (the deploy output notes it), and with a
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

`arco scheduler:pause --env=<env>` disables the rule in AWS at once and `arco scheduler:resume --env=<env>`
enables it again. They do not edit `arco.php`, and a deploy keeps a paused rule paused (it prints a note);
new rules start enabled. Without a rule in AWS they fail and change nothing; a rule already paused (or
active) is "nothing to change". `arco info` shows a paused scheduler as `every 5 min (paused)`, and `--json`
adds `settings.schedulerPaused`: `true`, `false`, or `null` without a scheduler, a rule or EventBridge read
access.

`scheduler:set` and `scheduler:remove` can also apply the change without a deploy: after editing `arco.php`
they ask "Apply now in AWS without a deploy?" (default no), `--apply` applies without asking, and a
non-interactive run without `--apply` only edits `arco.php`. `scheduler:set --apply` puts the rule on the
console function's `live` alias (a paused rule stays paused); if the console is not deployed yet, nothing in
AWS changes and it asks for `arco deploy --env=<env>`. `scheduler:remove --apply` deletes the rule and the
alias's invoke permission. `--apply` needs `--env` (the app level spans every environment) and works when
`arco.php` already has the value, for example after editing it by hand.

### Log retention

`->logRetention(30)` (or `arco logs:retention 30`) sets the CloudWatch retention of the web function's log
group and, when the console is on, the console's, and each queue worker's; creating the groups first when they do not exist yet.
`->logRetention(false)` (`arco logs:retention never`) makes them never expire. With no setting the groups
keep 30 days (before 0.9 Arco left them alone, so logs never expired: set `->logRetention(false)` to keep that);
`arco logs:retention --remove` goes back to the default. Allowed days are the ones CloudWatch
accepts (1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922,
3288, 3653).

### Runtime binary clean-up

Each deploy that needs the FrankenPHP binary in the code bucket uploads it to `runtime/<build>/...`. After a
deploy Arco deletes `runtime/*` objects older than 24 hours that no published version of any Arco function in the region
(found by the `arco:app` tag, all versions, since any can be rolled back to) references; the 24-hour grace
period protects a deploy that has uploaded a binary but not yet published.
This is best effort: when it cannot list or delete, the deploy warns and goes on.

## Queues

`->queue('emails')` gives the environment an SQS queue and a **worker function** that runs its jobs:

```php
App::make('shop')
    ->region('us-east-1')
    ->queue('default')                                  // the default queue (the first one)
    ->queue('emails', timeout: 120, memory: 512, tries: 5)
    ->environment('staging', fn (Environment $env) => $env
        ->queue('emails', maxInstances: 2))              // merged over the app's options
    ->environment('production', fn (Environment $env) => $env
        ->queue('reports', timeout: 900, batchSize: 1)
        ->queue('legacy', existing: 'arn:aws:sqs:us-east-1:123456789012:legacy-jobs'));
```

### How it works

1. The app dispatches a job as usual (`SendEmail::dispatch($user)`). Arco sets `QUEUE_CONNECTION=sqs`, so
   Laravel's own SQS driver sends it to the queue.
2. An SQS event source mapping hands the messages to the queue's worker function,
   `<app>-<env>-queue-<name>` (for example `shop-production-queue-emails`): a classic Lambda function
   (`provided.al2023`, arm64) running the same code as the console function, from its `live` alias.
3. The worker boots Laravel **once per instance** and runs each message through Laravel's own queue
   `Worker`, as `queue:work` would: job middleware, events, `failed()`, `$backoff`, `$timeout`, `$tries`,
   `retryUntil()` and `release()` all work the usual way.

Retries are Laravel's. A job that throws is released and comes back after its `$backoff` (at once without
one); the queue's `tries` is the default for jobs without their own `$tries`. A job out of tries is failed
by Laravel: `failed()` runs and the job is stored in the app's **failed-jobs store** (`failed_jobs`, as
configured in `config/queue.php`, or the DynamoDB table of [`failedJobsTable()`](#failed-jobs)), so
`queue:failed` and `queue:retry` (through `arco command`) work as usual.

Each queue Arco creates gets a **dead-letter queue** (`<app>-<env>-<name>-dlq`, messages kept 14 days) with
`maxReceiveCount` = `tries` + 2. It is a safety net, not the retry mechanism: a message only reaches it when
the worker never answered for it (the function crashed, ran out of memory or hit its Lambda timeout). On the
last delivery before the dead-letter queue (receive `tries` + 1), a job that would be retried again is
failed through Laravel instead (`failed()` and `failed_jobs`, with a "max attempts exceeded" error), so it
is never lost in the dead-letter queue unseen. In practice a job whose own `$tries`, `retryUntil()` or a
releasing middleware asks for more attempts than the queue's `tries` + 1 ends in `failed_jobs` earlier than
it asked: raise the queue's `tries` for such jobs. Look at the dead-letter queue with `arco queue:dlq <name>`.

With a batch (`batchSize` above 1) each message succeeds or fails on its own (partial batch responses):
only the failed and released ones come back. Each job gets the time left before the Lambda timeout minus
3 seconds (or its own `$timeout`, when shorter); messages with too little time left come back untouched.
If a job does not give control back by one second before the deadline, the worker answers for the batch
(every message not finished comes back) and its process restarts.

**Maintenance mode.** While the app is down (`php artisan down`), jobs do not run and no attempt is spent:
on a standard queue each delivery is replaced by a fresh copy delayed 60 seconds; on a FIFO queue (which
has no per-message delay) the job is released for 60 seconds, and replaced by a fresh copy in the same
message group once its deliveries reach its tries or the last delivery before the dead-letter queue (the
copy goes to the back of its group).

**Requirements for the app.** `multiapi/arco-laravel` 0.5 or later (the worker's `arco:work` and the
queue name mapping) and `aws/aws-sdk-php` (Laravel's SQS driver needs it; Arco does not install it) must be
in `require`. With queues in `arco.php`, the deploy checks the package for both before any change and stops
with the `composer require` line to run when one is missing. Job time limits use `pcntl` signals, which Arco's PHP runtime has. A signal lands
between PHP instructions, so a job blocked inside one long C call (a `curl` request, a database query) only
times out when that call returns: give HTTP clients and database connections their own timeouts.

### Options

`queue()` takes named arguments; options left out keep their defaults, and repeated calls for the same
name add to each other.

| Argument | Default | What it does |
|---|---|---|
| `timeout` | 60 | Worker function timeout in seconds (10 to 900; the worker keeps 3 of them to answer for the batch): the longest a batch may run. The queue's visibility timeout is 6 times this |
| `memory` | 1024 | Worker memory in MB (128 to 10240) |
| `maxInstances` | unset | Maximum concurrent worker invocations (event source mapping `MaximumConcurrency`, at least 2); unset means no limit beyond the account's |
| `batchSize` | 1 | Messages per invocation: 1 to 10000 on a standard queue, 1 to 10 on FIFO. Above 10 the event source mapping waits up to 1 second to gather a batch (AWS requires a batching window there) |
| `tries` | 3 | Attempts of a job without its own `$tries`; the dead-letter queue's `maxReceiveCount` is `tries` + 2 |
| `dlq` | `true` | Create a dead-letter queue (never for an `existing` queue) |
| `fifo` | `false` | Create a FIFO queue (its name ends in `.fifo`) |
| `existing` | none | Name, URL or ARN of an SQS queue Arco does not manage (below) |
| `default` | first queue | The queue jobs go to without `onQueue()`; at most one queue may say `default: true` |

- App and environment queues merge by name: the environment's options go over the app's for that queue,
  new names are added, and `->queue('emails', false)` on an environment drops an app-level queue there.
- The **default queue** is the first queue (app-level queues come first) unless one says `default: true`.
- Queue names use lowercase letters, digits and hyphens and start with a letter. Arco names the SQS queue
  `<app>-<env>-<name>` and its dead-letter queue `<app>-<env>-<name>-dlq` (plus `.fifo` for FIFO queues):
  both must fit SQS's 80 characters, and the worker name `<app>-<env>-queue-<name>` Lambda's 64.
- A queue cannot take the SQS name of another queue's dead-letter queue (for example a queue `emails-dlq`
  next to `emails`): rename one, or turn that dead-letter queue off.

### Several queues and `onQueue()`

Each queue has its own worker, so a slow queue never holds up another. Send a job to a queue by its name in
`arco.php`; no app code is needed for the mapping (Laravel 11 to 13):

```php
SendEmail::dispatch($user)->onQueue('emails');
Queue::route(SendEmail::class, 'emails');   // Laravel versions with Queue::route()
```

Arco sets these variables on the web, console and worker functions:

| Variable | Value |
|---|---|
| `QUEUE_CONNECTION` | `sqs` |
| `SQS_PREFIX` | `https://sqs.<region>.amazonaws.com/<account>` |
| `SQS_QUEUE` | the URL of the default queue |
| `ARCO_QUEUES` | JSON map of every queue name in `arco.php` to its URL |

`multiapi/arco-laravel` replaces the `sqs` connector so that the names in `ARCO_QUEUES` (`emails`) reach
their queues (`.../shop-production-emails`); a queue URL passes through, and any other name keeps Laravel's
prefix behaviour. Your own `variables()` win over Arco's: setting `QUEUE_CONNECTION`, `SQS_PREFIX` or
`SQS_QUEUE` there overrides them, and the deploy prints a note (the same holds for the variables of
[Storage](#storage) and [Cache and failed-jobs tables](#cache-and-failed-jobs-tables)). `ARCO_QUEUES` cannot be
set: names starting with `ARCO` are reserved.

### FIFO queues

`->queue('orders', fifo: true)` creates `<app>-<env>-orders.fifo` (and a FIFO dead-letter queue). Jobs of one
message group run in order: in a batch, once a job is released or fails, the messages after it come back
without running (status `held`), so no later message overtakes it. Every message sent to a FIFO queue gets a message group and a
deduplication id: Laravel's own when it sets them (Laravel 12 and 13, with the job's `messageGroup` and
`deduplicationId`), else `multiapi/arco-laravel` uses the queue name as the group and the job's UUID as the
deduplication id (Laravel 11, `queue:retry`). FIFO queues take no per-message delay, so a job's `delay()`
does not apply (the job is sent at once), and `batchSize` is at most 10. A queue cannot switch `fifo` once Arco created it (see below).

### Existing queues

`->queue('legacy', existing: '...')` points a worker at an SQS queue Arco does not manage, by name (in the
environment's account and region), URL or ARN. Arco never creates, changes or deletes it, and never gives it
a dead-letter queue: its attributes (visibility timeout, redrive policy) stay as they are. Make sure that
its visibility timeout is at least the worker `timeout` (Lambda refuses the mapping otherwise), and that a
redrive policy of its own leaves room for the job's tries (without an Arco dead-letter queue there is no
last-delivery guard). A FIFO queue needs `fifo: true` (its name ends in `.fifo`). If the queue already has
other consumers, the deploy warns: jobs are then split between all of them. A queue in another account also
needs a queue policy that allows the worker's execution role.

### Removing a queue, and what stays

Queues are **kept** by Arco, so no message is lost. Removing a queue from `arco.php` (or
`arco queue:remove`) makes the next deploy delete its worker and event source mapping and keep the SQS queue
and its dead-letter queue in AWS, with their messages: the deploy names each kept queue, its URL and about
how many messages it holds. Read or move what you need, then delete them in the SQS console. `arco remove`
does the same for every queue of the environment.

The one exception: when the deploy that creates a queue fails and CloudFormation rolls it back, that new
queue is deleted (`DeletionPolicy: RetainExceptOnCreate`). The resources stack deploys before any code, so
no app has sent to it yet; keeping it would only make the next deploy stop on its name. Buckets and tables
follow the same rule (see [What stays](#what-stays)).

- Adding a queue back under a kept name stops the deploy before any change and prints the line to attach
  the kept queue with `existing:` (or delete the kept queue, or choose another name). The same happens for a
  kept dead-letter queue (for example after `dlq: false` and back), with the option to turn it off.
- Changing `fifo` on a queue Arco created stops the deploy before any change: CloudFormation would have to
  replace the queue. Remove it and add a queue with another name instead (or attach the kept one with
  `existing:`).

### Deploys

The queues Arco creates live in a CloudFormation stack per environment, `arco-<app>-<env>-resources`, with
the buckets and tables of [Storage](#storage) and [Cache and failed-jobs tables](#cache-and-failed-jobs-tables)
(tagged with the app and environment; Arco refuses a stack of that name that is not tagged as its own, and
leaves it alone when `arco.php` has nothing to put in it). The deploy updates it **before** any code, so a
new queue exists before code that sends to it; a failure there stops the deploy before traffic moves. Workers are created or updated with the rest and switched to the new
version (alias and event source mapping) only **after** the web revision is live and verified; a worker
that cannot be set up is a warning, and the queue keeps its previous worker or none. When a queue's
reference changes (another `existing:` queue, region or account), the worker's mapping on the old queue is
deleted once the new one exists, so a worker reads only the queue `arco.php` names. The workers run the
console package (a copy without the FrankenPHP binary), which is built whenever queues exist, even with the
console off: it must fit Lambda's 250 MB, or the deploy stops before any change. Each worker keeps its live
version plus the 4 newest, like the console.

`->logRetention()` also applies to each worker's log group, `/aws/lambda/<app>-<env>-queue-<name>`.

### Commands

```bash
vendor/bin/arco queue:add emails --env=production --timeout=120 --tries=5
vendor/bin/arco queue:add legacy --app --existing=arn:aws:sqs:us-east-1:123456789012:legacy-jobs
vendor/bin/arco queue:set emails max-instances 10 --env=production
vendor/bin/arco queue:set emails max-instances none --env=production   # no limit
vendor/bin/arco queue:remove emails --env=production
vendor/bin/arco queue:list --env=production
vendor/bin/arco queue:dlq emails --env=production
vendor/bin/arco queue:dlq emails --env=production --redrive
```

- `queue:add <name>` takes `--timeout`, `--memory`, `--max-instances`, `--batch-size`, `--tries`, `--no-dlq`,
  `--fifo`, `--existing=<name, URL or ARN>` and `--default`; `queue:set <name> <option> <value>` changes
  `timeout`, `memory`, `max-instances` (`none` clears it), `batch-size`, `tries`, `dlq` or `default` (`on` or
  `off`). `fifo` and `existing` cannot change once the queue exists: add a new queue instead.
  `queue:remove <name>` takes the queue out (on an environment, a queue defined at app level is dropped with
  `->queue('<name>', false)`). All three take `--env=<env>` or `--app`, edit `arco.php` only (checked like
  `arco set`) and need `arco deploy` to apply.
- `queue:list` shows each queue of the environment from AWS: its URL, whether Arco created it, messages
  waiting and in flight, the dead-letter queue and its message count, the worker's live version and its
  settings.
- `queue:dlq <name>` shows how many messages the dead-letter queue holds and a sample of up to 10 (id,
  receive count, sent time, body). Reading the sample raises their receive count but does not remove or
  delay them. `--redrive` moves them back to the queue (an SQS message move task, running in the
  background) once the cause is fixed; follow the counts with `queue:list`.
- `arco logs --queue=emails` shows one worker's logs, `--source=queues` every worker's, `--console` the
  console's (same as `--source=console`); the default `--source=all` includes the workers. Each job writes
  one JSON line with its queue, job class, message id, duration and status (`ok`, `failed`, `released`,
  `skipped`, `held`, `maintenance`, `maintenance_requeued`). CloudWatch Live Tail follows at most 10 log groups:
  with more, `--follow` polls and says so; narrow it with `--source`, `--console` or `--queue`.
- `arco info` lists each queue with its URL and the worker's live version; `--json` adds `queues`: a list of
  `{name, url, existing, dlq, worker: {function, version}}`, where `existing` is `true` or `false`, `dlq` the
  dead-letter queue's name or `null`, and `worker.error` appears when the version could not be read.

### Queue permissions

The managed execution role gets the `ArcoQueues` statement (`sqs:SendMessage`, `sqs:ReceiveMessage`,
`sqs:DeleteMessage`, `sqs:ChangeMessageVisibility`, `sqs:GetQueueAttributes`, `sqs:GetQueueUrl` on the ARN
of every queue in `arco.php`, `existing` ones included; not on the dead-letter queues) and may write the
worker log groups. With your own `role()`, give it the same:

```json
{"Effect": "Allow", "Action": ["sqs:SendMessage", "sqs:ReceiveMessage", "sqs:DeleteMessage", "sqs:ChangeMessageVisibility", "sqs:GetQueueAttributes", "sqs:GetQueueUrl"], "Resource": ["arn:aws:sqs:<region>:<account>:<app>-<env>-emails"]},
{"Effect": "Allow", "Action": ["logs:CreateLogGroup", "logs:CreateLogStream", "logs:PutLogEvents"], "Resource": ["arn:aws:logs:<region>:<account>:log-group:/aws/lambda/<app>-<env>-queue-emails:*"]}
```

plus `s3:GetObject` on `runtime/*` of the code bucket, as for the console (workers run the console
package). The deploy profile's additions are in [Permissions for queues](getting-started.md#permissions-for-queues).

## Storage

`->storage('uploads')` gives the environment an S3 bucket, which the app uses as a Laravel disk:

```php
App::make('shop')
    ->region('us-east-1')
    ->storage('files')                                  // the default disk (the first storage)
    ->storage('uploads', public: ['avatars/'], expire: ['tmp/' => 1], cors: ['https://shop.example.com'])
    ->environment('staging', fn (Environment $env) => $env
        ->storage('uploads', expire: ['tmp/' => 7]))    // merged over the app's options
    ->environment('production', fn (Environment $env) => $env
        ->storage('legacy', existing: 'shop-legacy-files'));
```

### Buckets

Arco names the bucket `<app>-<env>-<name>-<account>` (for example `shop-production-uploads-123456789012`):
S3 bucket names are global, so the account id keeps them unique. The name must fit S3's 63 characters;
a longer one is a validation error (the account's 12 digits are counted even before the first deploy
knows them): use a shorter app, environment or storage name. Storage names use lowercase letters, digits
and hyphens and start with a letter.

Every bucket Arco creates is private: all four S3 Block Public Access settings on, encrypted with SSE-S3,
a lifecycle rule `arco-abort-incomplete-uploads` (multipart uploads left incomplete are aborted after 7 days)
plus one rule per `expire` prefix, no CORS without `cors`, and tagged `arco:app`, `arco:environment` and
`arco:resource` (`storage:<name>`). Buckets live in the resources stack with the queues (see
[Deploys](#deploys)): it is updated before any code, so a new bucket exists before code that writes to it.

### Options

`storage()` takes named arguments; options left out keep their defaults, and repeated calls for the same
name add to each other.

| Argument | Default | What it does |
|---|---|---|
| `public` | none | Key prefixes anyone can read, each ending in `/` (for example `['avatars/']`); see [Public prefixes](#public-prefixes) |
| `expire` | none | Key prefix (ending in `/`) => days (at least 1): a lifecycle rule deletes the objects under the prefix that many days after they were created |
| `cors` | none | Origins a browser may call the bucket from: `https://example.com`, `http://localhost:5173` or `*`. One CORS rule allows `GET`, `PUT`, `POST` and `HEAD` with any header (max age 3000 seconds) |
| `existing` | none | Name of an S3 bucket Arco does not manage (below) |
| `default` | first storage | The storage behind Laravel's default disk; at most one storage may say `default: true` |

- App and environment storage merge by name: the environment's options go over the app's for that storage
  (an environment's `public` list replaces the app's list as a whole), new names are added, and
  `->storage('uploads', false)` on an environment drops an app-level storage there.
- The **default storage** is the first one (app-level storage comes first) unless one says `default: true`. If every storage says `default: false`, there is no default storage: Arco injects only `ARCO_STORAGE` and Laravel keeps its own default disk.
- `public`, `expire` and `cors` apply only to buckets Arco creates. On an `existing` bucket they are
  ignored, with a validation warning (also from `storage:public`, `storage:expire` and `storage:cors`):
  configure that bucket yourself.
- Storage cannot be named `s3`, `public` or `local` (a validation error): those are the disks of Laravel's
  stock `config/filesystems.php`, which keep their own settings, so `Storage::disk('public')` would stay the
  local disk, on the function's temporary disk, lost when Lambda starts a new instance.
- Public and expire prefixes cannot contain `*` or `?` (a validation error), so a wildcard never makes more
  of a bucket public than the prefix names.

### Laravel disks

Arco sets these variables on the web, console and worker functions:

| Variable | Value |
|---|---|
| `FILESYSTEM_DISK` | `s3` |
| `AWS_BUCKET` | the default storage's bucket |
| `AWS_URL` | `https://<bucket>.s3.<region>.amazonaws.com` of the default storage |
| `ARCO_STORAGE` | JSON map of every storage name to its bucket and URL |

- The default storage is Laravel's stock `s3` disk, and the default disk: `Storage::put(...)` writes to it
  and `Storage::url($path)` gives `https://<bucket>.s3.<region>.amazonaws.com/<path>` (readable by anyone only
  under a public prefix).
- Every storage is also a disk named after it, which `multiapi/arco-laravel` (0.6 or later) registers from
  `ARCO_STORAGE`: `Storage::disk('uploads')->put(...)`. It uses the `s3` driver, the function's region and its
  temporary credentials.
- A disk your `config/filesystems.php` already defines under the same name stays as the app defines it, and
  `multiapi/arco-laravel` writes a log line `{"arco":"disk_skipped", ...}`. The stock names `local`, `public`
  and `s3` are refused by the validation for that reason. A default storage still feeds the stock `s3` disk
  through `AWS_BUCKET`, whatever its name.
- For an `existing:` bucket whose name contains dots, or in a `cn-*` region, the `AWS_URL` Arco builds
  (`https://<bucket>.s3.<region>.amazonaws.com`) is wrong (certificate mismatch, different domain): set `AWS_URL`
  yourself in `variables()`.
- Your own `variables()` win over Arco's: setting `FILESYSTEM_DISK`, `AWS_BUCKET` or `AWS_URL` there overrides
  them, and the deploy prints a note (`ARCO_STORAGE` cannot be set: names starting with `ARCO` are reserved).
- Without a default storage (every storage says `default: false`), Arco sets none of `FILESYSTEM_DISK`,
  `AWS_BUCKET` and `AWS_URL`: only `ARCO_STORAGE`, so the named disks exist and Laravel's own default disk stays.
- Laravel's S3 driver needs `league/flysystem-aws-s3-v3` and `aws/aws-sdk-php` in `require`
  (`composer require league/flysystem-aws-s3-v3 "^3.0"`), and the named disks need `multiapi/arco-laravel`
  0.6 or later. With storage in `arco.php`, the deploy checks the package for all three before any change
  and stops with the `composer require` line to run when one is missing.

### Public prefixes

`public: ['avatars/']` adds a bucket policy that lets anyone read (`s3:GetObject`) the objects under each
prefix, and nothing else: no listing, no writes, the rest of the bucket private. Files are then at
`https://<bucket>.s3.<region>.amazonaws.com/<key>`, which the deploy prints. For that policy Arco turns off
only the two Block Public Access settings about policies on that bucket (`BlockPublicPolicy` and
`RestrictPublicBuckets`); `BlockPublicAcls` and `IgnorePublicAcls` stay on, so an object ACL never makes a
file public.

- When S3 Block Public Access is on **for the whole account** (`BlockPublicPolicy`, "block public access
  granted through new public bucket policies"), it overrides the bucket's own setting: CloudFormation rolls
  the change back and the deploy stops with an error that names the setting. Turn it off in the S3 console
  (Block Public Access settings for this account) and deploy again, or remove the public prefixes. A plain
  access denial on the policy is reported with both possible causes: that setting, or a profile without
  `s3:PutBucketPolicy`.
- Removing the last public prefix (or the storage) deletes the bucket policy, so the bucket is private
  again. A bucket kept after its storage left `arco.php` keeps the two policy settings off, with no policy
  that makes anything public; turn them back on in the S3 console if you want.

### Existing buckets

`->storage('legacy', existing: 'shop-legacy-files')` uses a bucket Arco does not manage, by name. Arco never
creates, changes or deletes it, and does not apply `public`, `expire` or `cors` to it. The bucket must be in
the **app's region**: the disk's region and its URL are the app's. The execution role gets read and write
access to it (below); a bucket in another account also needs a bucket policy that allows that role.

### What stays

Buckets and tables are kept like [queues](#removing-a-queue-and-what-stays), so no file or item is lost:

- Removing a storage from `arco.php` (or `arco storage:remove`) makes the next deploy keep the bucket in AWS
  with its files and say whether it still has any. Copy what you need, then empty and delete it in the S3
  console. `arco remove` keeps every bucket of the environment the same way. Turning `cache()` or
  `failedJobsTable()` off keeps the table with its items (the deploy prints about how many).
- The one exception: a bucket or table created by a deploy that fails and rolls back is deleted
  (`DeletionPolicy: RetainExceptOnCreate`). The resources stack deploys before the app could use it, so it
  is new and empty.
- Adding a storage back under a kept name stops the deploy before any change and prints the line to use the
  kept bucket instead, `->storage('uploads', existing: '<bucket>')` (Arco then no longer manages it), or
  delete the bucket, or choose another storage name. A kept table stops the deploy the same way, with
  `->cache(existing: '<table>')` or `->failedJobsTable(existing: '<table>')`.
- Pointing a storage Arco created at its own bucket with `existing:` hands the bucket over to you: the deploy
  keeps it, says Arco no longer manages it, and the app goes on using it. The same works for a table.
- The bucket policy of public prefixes is not kept: it goes with the storage (or with its public prefixes).
  Re-attaching a formerly public bucket with `existing:` leaves it **private**, so its public URLs answer 403
  even if `arco.php` still lists `public:` prefixes (they are ignored on an `existing` bucket, with a
  warning). Add a bucket policy yourself in the S3 console if those files must stay public.
- S3 bucket names are global. Before creating a bucket the deploy checks its name: when another account
  (or this account, in another region) holds it, the deploy stops before any change. AWS answers the same
  way (403) when the profile lacks `s3:ListBucket` on a bucket of this account: allow it and deploy again.

### Commands

```bash
vendor/bin/arco storage:add uploads --env=production
vendor/bin/arco storage:add files --app --default
vendor/bin/arco storage:add legacy --env=production --existing=shop-legacy-files
vendor/bin/arco storage:public uploads avatars/ --env=production
vendor/bin/arco storage:public uploads avatars/ --env=production --remove
vendor/bin/arco storage:expire uploads tmp/ 1 --env=production
vendor/bin/arco storage:expire uploads tmp/ --env=production --remove
vendor/bin/arco storage:cors uploads https://shop.example.com http://localhost:5173 --env=production
vendor/bin/arco storage:cors uploads http://localhost:5173 --env=production --remove
vendor/bin/arco storage:remove uploads --env=production
vendor/bin/arco storage:list --env=production
```

- `storage:add <name>` takes `--existing=<bucket>` and `--default`. `storage:public <name> <prefix>` adds a
  public prefix, `storage:expire <name> <prefix> <days>` sets the days of a prefix's rule, and
  `storage:cors <name> <origin>...` adds the origins not already there; with `--remove` each takes the entry
  out. On an environment that inherits the storage from the app level, they write the environment's whole
  new value (an environment's option replaces the app's).
- `storage:remove <name>` takes the storage out (on an environment, a storage defined at app level is dropped
  with `->storage('<name>', false)`; with `--app`, Arco warns about environments that keep their own call).
  The bucket stays in AWS.
- All of them take `--env=<env>` or `--app`, edit `arco.php` only (checked like `arco set`) and need
  `arco deploy` to apply.
- `storage:list` shows each storage of the environment: bucket, URL, whether Arco created it, whether it is
  the default disk, public prefixes, expiry rules and CORS origins. Then it lists the **retained buckets**:
  buckets Arco created for this app and environment whose storage is no longer in `arco.php` (found by their
  tags with `tag:GetResources`; without it they show as unknown).
- `arco info` lists each storage with its bucket (or `<bucket> (existing, not managed by Arco)`), whether it
  is the default disk and its public prefixes. `--json` adds `storage`: a list of
  `{name, bucket, url, existing, default, public}`, where `bucket` and `url` are `null` for a bucket Arco
  creates while the account is unknown (not deployed yet, or not readable).

### Storage permissions

The managed execution role gets two statements for every bucket in `arco.php`, `existing` ones included:
`ArcoStorage` (`s3:GetObject`, `s3:PutObject`, `s3:DeleteObject`, `s3:GetObjectAcl`, `s3:PutObjectAcl`,
`s3:AbortMultipartUpload` on the bucket's objects) and `ArcoStorageList` (`s3:ListBucket` on the bucket).
With your own `role()`, give it the same:

```json
{"Effect": "Allow", "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject", "s3:GetObjectAcl", "s3:PutObjectAcl", "s3:AbortMultipartUpload"], "Resource": ["arn:aws:s3:::<app>-<env>-uploads-<account>/*"]},
{"Effect": "Allow", "Action": ["s3:ListBucket"], "Resource": ["arn:aws:s3:::<app>-<env>-uploads-<account>"]}
```

The deploy profile's additions are in
[Permissions for storage and tables](getting-started.md#permissions-for-storage-and-tables).

## Cache and failed-jobs tables

`->cache()` gives the environment a DynamoDB table for Laravel's cache, and `->failedJobsTable()` one for the
failed jobs of its queues:

```php
App::make('shop')
    ->region('us-east-1')
    ->cache()                                           // shop-<env>-cache
    ->failedJobsTable()                                 // shop-<env>-failed-jobs
    ->environment('staging', fn (Environment $env) => $env
        ->cache(false))                                 // no cache table on staging
    ->environment('production', fn (Environment $env) => $env
        ->cache(existing: 'shop-cache'));               // a table Arco does not manage
```

Each is one setting: an environment's call replaces the app's, `cache(false)` turns it off, and
`existing: '<table>'` names a table Arco does not manage. The tables Arco creates are on-demand (pay per
request), have DynamoDB TTL on `expires_at`, are tagged like the buckets, live in the resources stack and
are kept when they leave `arco.php` (see [What stays](#what-stays)):

| Table | Name | Keys |
|---|---|---|
| Cache | `<app>-<env>-cache` | partition key `key` (string): the layout of Laravel's DynamoDB cache store |
| Failed jobs | `<app>-<env>-failed-jobs` | partition key `application`, sort key `uuid` (strings): the layout of Laravel's DynamoDB failed-job provider |

Both need `aws/aws-sdk-php` in `require` (as queues do), and `multiapi/arco-laravel` 0.6 or later wires the
failed-jobs table and a cache table Arco creates. Before any change, the deploy checks the package for
`aws/aws-sdk-php` (cache and failed-jobs table) and `multiapi/arco-laravel` 0.6+ (failed-jobs table: without
it Laravel's failer has no region and no failed job is recorded), and stops with the `composer require` line
to run when one is missing. A cache table works with an older `multiapi/arco-laravel` as long as
`config/cache.php` keeps Laravel's default attributes.

### Cache

| Variable | Value |
|---|---|
| `CACHE_STORE` | `dynamodb` |
| `DYNAMODB_CACHE_TABLE` | the table's name |
| `ARCO_CACHE_TABLE` | the table's name, only for a table Arco created |

- With a table Arco created, `multiapi/arco-laravel` points Laravel's `dynamodb` store at it with the
  attributes the table uses (`key`, `value`, `expires_at`) at runtime, **whatever `config/cache.php` says**
  (for example `id` / `ttl`, copied from Bref's docs). An app that removed the `dynamodb` store gets one.
- With `existing:`, Arco sets only `CACHE_STORE` and `DYNAMODB_CACHE_TABLE`: the store uses the attributes of
  your `config/cache.php` (Laravel's default: `key`, `value`, `expires_at`), which must match the table, and
  Arco does not change the table's TTL.
- DynamoDB's TTL deletes expired items in the background, which can take a while (AWS gives no exact time);
  Laravel ignores an expired item meanwhile, so it only costs storage.
- Laravel's DynamoDB store cannot be flushed: `cache:clear` on it fails ("DynamoDb does not support flushing
  an entire table").
- Your own `variables()` win: setting `CACHE_STORE` or `DYNAMODB_CACHE_TABLE` there overrides Arco's value,
  with a note in the deploy output. Arco then leaves out `ARCO_CACHE_TABLE` (unless your value is the same as
  Arco's), so `multiapi/arco-laravel` does not change the `dynamodb` store.

### Failed jobs

| Variable | Value |
|---|---|
| `QUEUE_FAILED_DRIVER` | `dynamodb` |
| `ARCO_FAILED_JOBS_TABLE` | the table's name (Arco's or the `existing` one) |

Stock `config/queue.php` does not read the table's name from the environment, so `multiapi/arco-laravel`
sets `queue.failed` at runtime: driver `dynamodb`, the table, the region and the function's credentials
(other keys of `queue.failed` stay). Laravel's failed-job handling is unchanged otherwise: `failed()` runs,
and `queue:failed`, `queue:retry` and `queue:forget` work through `arco command`; `queue:flush` does not
(Laravel's DynamoDB provider refuses it and points to TTL).

Laravel writes each failed job with `expires_at` one week after it failed, and the table's TTL deletes it
after that: **failed jobs in DynamoDB disappear after about a week**. Retry or copy them before. An
`existing` table must have Laravel's layout (`application` + `uuid`), and TTL on `expires_at` for them to
expire.

Setting `QUEUE_FAILED_DRIVER` in your own `variables()` overrides Arco's, with a note; unless your value is
`dynamodb`, Arco then leaves out `ARCO_FAILED_JOBS_TABLE`, so `multiapi/arco-laravel` does not change
`queue.failed`.

### Commands

```bash
vendor/bin/arco cache:add --env=production
vendor/bin/arco cache:add --env=production --existing=shop-cache
vendor/bin/arco cache:remove --env=staging
vendor/bin/arco failed-jobs:add --app
vendor/bin/arco failed-jobs:add --env=production --existing=shop-failed-jobs
vendor/bin/arco failed-jobs:remove --env=staging
```

- `cache:add` and `failed-jobs:add` turn the table on (Arco's, or `--existing=<table>`); `cache:remove`
  and `failed-jobs:remove` turn it off. On an environment that inherits the table from the app level,
  turning it off writes `->cache(false)` (or `->failedJobsTable(false)`); otherwise the call is removed. The
  table stays in AWS.
- `queue:failed-table [--existing=<table>] [--remove]` is the deprecated spelling of `failed-jobs:add` /
  `failed-jobs:remove`: hidden from the command list, it prints a warning and is removed in 1.0.
- `cache(driver:)` takes only `'dynamodb'`, the default, so the editor never writes it (an existing
  `driver: 'dynamodb'` is dropped when it rewrites the call).
- They take `--env=<env>` or `--app`, edit `arco.php` only and need `arco deploy` to apply.
- `arco info` shows `Cache table` and `Failed jobs table` rows with the table's name (or
  `<table> (existing, not managed by Arco)`); `--json` adds `cache` and `failedJobsTable`, each
  `{table, existing}` or `null`.

### Table permissions

The managed execution role gets the `ArcoTables` statement (`dynamodb:GetItem`, `dynamodb:PutItem`,
`dynamodb:UpdateItem`, `dynamodb:DeleteItem`, `dynamodb:Query`, `dynamodb:BatchGetItem`,
`dynamodb:BatchWriteItem`, `dynamodb:DescribeTable` on both tables, `existing` ones included). With your own
`role()`, give it the same:

```json
{"Effect": "Allow", "Action": ["dynamodb:GetItem", "dynamodb:PutItem", "dynamodb:UpdateItem", "dynamodb:DeleteItem", "dynamodb:Query", "dynamodb:BatchGetItem", "dynamodb:BatchWriteItem", "dynamodb:DescribeTable"], "Resource": ["arn:aws:dynamodb:<region>:<account>:table/<app>-<env>-cache", "arn:aws:dynamodb:<region>:<account>:table/<app>-<env>-failed-jobs"]}
```

The deploy profile's additions are in
[Permissions for storage and tables](getting-started.md#permissions-for-storage-and-tables).

## Tables

`->table('<name>')` gives the environment a DynamoDB table of its own, for single-table designs your app reads
and writes through the AWS SDK (state, idempotency keys, sessions of an external API...):

```php
App::make('shop')
    ->region('us-east-1')
    ->table('state', ttl: 'expires_at')                 // shop-<env>-state, TTL on expires_at
    ->environment('staging', fn (Environment $env) => $env
        ->table('state', false))                        // no state table on staging
    ->environment('production', fn (Environment $env) => $env
        ->table('ledger', existing: 'shop-ledger'));    // a table Arco does not manage
```

Signature: `table(string $name, ttl: ?string, existing: ?string)`; write `ttl:` and `existing:` as named
arguments (the second positional argument is reserved for `table('<name>', false)`). Tables merge by name like
`storage()`: an environment's options for a table go over the app's, and `table('<name>', false)` drops it there.

- **Name.** Lowercase letters, digits and hyphens, starting with a letter (the rule of queue and storage names).
  `cache` and `failed-jobs` are reserved: they are the names of Arco's own [cache and failed-jobs
  tables](#cache-and-failed-jobs-tables). The table name `<app>-<env>-<name>` must fit DynamoDB's 255 characters.
- **The table Arco creates** is `<app>-<env>-<name>` in the resources stack: partition key `PK` and sort key `SK`,
  both strings, on demand (`PAY_PER_REQUEST`), tagged `arco:app`, `arco:environment` and `arco:resource` =
  `table:<name>`. Other key schemas and secondary indexes are not available in 0.10.
- **`ttl:`** turns on DynamoDB TTL on that attribute: write the item's expiry there as epoch seconds (a number),
  and DynamoDB deletes the item some time after it (AWS gives no exact time; filter expired items when you read
  them). Adding, removing or renaming the TTL attribute updates the table in place, and **DynamoDB allows one TTL
  change per table per hour**: a second TTL change within the hour makes that deploy's stack update fail and
  roll back. Deploy it again an hour later.
- **`existing:`** names a table Arco does not manage, by name or ARN: no table is created, the execution role
  gets access to it, and the name is passed on as given (an ARN as an ARN: the AWS SDK accepts both as
  `TableName`). `ttl:` with `existing:` is a warning: Arco never changes that table. Two tables of
  `arco.php` (`cache()`, `failedJobsTable()` and `table()` included) cannot name the same DynamoDB table.
- **Kept on remove.** Like the cache table, a table Arco created is kept when it leaves `arco.php` and by `arco
  remove`, with its items (the deploy prints about how many; see [What stays](#what-stays)). Adding a table back
  under a kept name stops the deploy before any change and prints the line that uses the kept table,
  `->table('<name>', existing: '<table>')`. Pointing a created table at itself with `existing:` hands it over to you.

### Using a table from Laravel

Arco sets `ARCO_TABLES` on the web, console and worker functions, a JSON map from each `table()` name to its
DynamoDB table (`{"state":"shop-production-state"}`; only with at least one `table()`). Nothing in
`multiapi/arco-laravel` is needed: read the map in a config file and use the AWS SDK (`aws/aws-sdk-php`, which
picks up the function's credentials and region by itself).

```php
// config/services.php
'arco' => [
    'tables' => json_decode((string) env('ARCO_TABLES', '{}'), true) ?: [],
],
```

```php
use Aws\DynamoDb\DynamoDbClient;

$dynamo = new DynamoDbClient(['region' => env('AWS_REGION'), 'version' => 'latest']);
$table = config('services.arco.tables.state');

$dynamo->putItem(['TableName' => $table, 'Item' => [
    'PK' => ['S' => "order#$orderId"],
    'SK' => ['S' => 'status'],
    'status' => ['S' => 'paid'],
    'expires_at' => ['N' => (string) now()->addDays(30)->getTimestamp()],
]]);

$item = $dynamo->getItem([
    'TableName' => $table,
    'Key' => ['PK' => ['S' => "order#$orderId"], 'SK' => ['S' => 'status']],
])['Item'] ?? null;
```

`examples/laravel` does the same in its `/e2e/table` route.

### Commands

```bash
vendor/bin/arco table:add state --ttl=expires_at --env=production
vendor/bin/arco table:add ledger --existing=shop-ledger --app
vendor/bin/arco table:remove state --env=staging
```

- `table:add <name> [--ttl=<attribute>] [--existing=<table>]` writes `->table(...)`; `table:remove <name>`
  removes it, or writes `->table('<name>', false)` on an environment that inherits it from the app level. The
  DynamoDB table stays in AWS.
- They take `--env=<env>` or `--app`, check the name and options with the validation rules before writing, edit
  `arco.php` only and need `arco deploy` to apply.
- `arco init` does not ask for tables.
- `arco info` shows a `Table <name>` row with the table's name (or `<table> (existing, not managed by Arco)`)
  and `· TTL <attribute>`; `--json` has `tables`: a list of `{name, table, existing, ttl}` (`[]` without
  tables). `arco info --resources` lists the created tables as `dynamodb:table`.

### Permissions of `table()` tables

The managed execution role gets the `ArcoAppTables` statement on each table, created or `existing`, and its
indexes: `dynamodb:GetItem`, `dynamodb:PutItem`, `dynamodb:UpdateItem`, `dynamodb:DeleteItem`,
`dynamodb:Query`, `dynamodb:Scan`, `dynamodb:BatchGetItem`, `dynamodb:BatchWriteItem`,
`dynamodb:ConditionCheckItem`, `dynamodb:DescribeTable`. No table management action: TTL is set by the
resources stack. With your own `role()`, give it the same:

```json
{"Effect": "Allow", "Action": ["dynamodb:GetItem", "dynamodb:PutItem", "dynamodb:UpdateItem", "dynamodb:DeleteItem", "dynamodb:Query", "dynamodb:Scan", "dynamodb:BatchGetItem", "dynamodb:BatchWriteItem", "dynamodb:ConditionCheckItem", "dynamodb:DescribeTable"], "Resource": ["arn:aws:dynamodb:<region>:<account>:table/<app>-<env>-<name>", "arn:aws:dynamodb:<region>:<account>:table/<app>-<env>-<name>/index/*"]}
```

The deploy profile needs the table actions of
[Permissions for storage and tables](getting-started.md#permissions-for-storage-and-tables); the
`arn:aws:dynamodb:*:*:table/<app>-*` resource there covers these tables too.

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
3. **DNS**: `A` and `AAAA` alias records to the distribution in Route 53, or a `CNAME` to it in Cloudflare.

The summary lists `https://<domain>` for every domain next to the endpoint URL.

### Where the DNS records go

| Config | DNS |
|---|---|
| nothing | Route 53 of the deploy account. A domain with no public hosted zone there falls back to manual. |
| `dns('profile')` | Route 53 of the account behind that AWS profile (for example a shared DNS account). `--dns-profile=` on `deploy` and `remove` overrides it. |
| `dns('cloudflare')` | Cloudflare, through its API with your API token (see [Cloudflare](#cloudflare)). A domain with no Cloudflare zone stops the deploy. |
| `dns(false)` | Not in Route 53. Arco prints the certificate validation `CNAME`s and waits up to 30 minutes for the certificate, then prints `CNAME <domain> → <distribution>.cloudfront.net` for you to create. |

For each record Arco picks the longest public hosted zone that is a suffix of the name. It never
takes over a live record: if a domain already has an `A`, `AAAA` or `CNAME` record that does not
point at this environment's distribution, `deploy` stops before creating anything and names the
record. `deploy --force-dns` replaces such records.

At a zone apex (`example.com`), most DNS providers do not allow a `CNAME`; in manual mode use your
provider's ALIAS/ANAME record type.

### Cloudflare

With `->dns('cloudflare')` (or `--dns=cloudflare` / the "Cloudflare" choice in `arco init` and `arco domain:add`),
Arco writes the records in Cloudflare through its API v4:

```php
->environment('production', fn (Environment $env) => $env
    ->domain(['example.com', 'www.example.com'])
    ->dns('cloudflare'))
```

- **Token.** Arco reads a Cloudflare API token from `CLOUDFLARE_API_TOKEN`, or from `--cloudflare-token=<token>`
  on `deploy`, `remove`, `init` and `domain:add` (the flag wins). It never writes the token anywhere
  (`arco.php`, `.arco/local.php`, logs, `--json` output) and never creates one: create it in the Cloudflare
  dashboard with the permissions **Zone → DNS → Edit** and **Zone → Zone → Read** on the zones of your domains.
  A missing or refused token stops `deploy` right after loading `arco.php`, before any AWS change, with a message
  naming the variable and those permissions. `remove` checks it before it asks, when the environment has domain
  resources.
- **Zones.** For each domain Arco uses the longest active Cloudflare zone the token can see that is a suffix
  of the name, the same rule as Route 53. A domain without such a zone stops the deploy before any change: unlike
  Route 53 there is no fallback to manual records (use `dns(false)` for that).
- **Records.** The certificate validation `CNAME`s, then `CNAME <domain> → <distribution>.cloudfront.net` for
  each domain, always with **proxied off** (DNS only, the grey cloud): CloudFront terminates TLS. A domain at
  the zone apex works too, through Cloudflare's CNAME flattening.
- **Ownership.** Each record Arco writes for a domain carries the comment `managed by arco: <app>/<env>`, the
  Cloudflare counterpart of the `arco:*` tags. A live `A`, `AAAA` or `CNAME` record at a domain without that
  comment stops the deploy before creating anything and is named in the message; `deploy --force-dns` replaces it
  (deleting `A`/`AAAA` records just before creating the `CNAME` leaves a few seconds without an answer). Keep the
  comment when you edit a record by hand, or Arco will no longer recognise it.
- **Validation `CNAME`s** carry the comment `managed by arco`, without app or environment: one validation
  record serves every environment and certificate of that name. An identical record is left as it is; one with
  other content (or proxied, unless Arco wrote it) at the validation name is refused unless `--force-dns`. Arco
  only sees that conflict once ACM gives it the validation name, that is after it requested the certificate: the
  deploy then stops with the certificate requested, and the next deploy reuses it.
- **Remove.** `arco remove` deletes only the records carrying this environment's comment. The validation `CNAME`s
  are kept, as with Route 53 (see [Remove](#remove)).
- **Reserved name.** `'cloudflare'` in `dns()` always means Cloudflare: an AWS profile named `cloudflare` can no
  longer be used as a DNS profile through `dns()`. Rename the profile in `~/.aws/config` if you had one.
- A Cloudflare request answered with HTTP 429 or 5xx is tried up to 4 times in all (the first try and 3 retries,
  with backoff). If a change fails
  part-way, the message lists what was already changed; deploy again to finish, Arco recognises its own records.
- `--dns-profile` cannot be combined with `dns('cloudflare')`. `arco info` shows the provider in a `dns.provider`
  row (`cloudflare`, `route53 (<profile>)`, `route53 (deploy account)` or `manual`), and `--json` in the `dns` key
  (`null` without domains).

### Remove

`remove` deletes the alias records that point at this environment's distribution (Route 53 modes; in
Cloudflare, the `CNAME`s carrying this environment's comment), then the stack, then the certificate if Arco requested it for this environment. Certificates
you created are never deleted. Certificate validation `CNAME`s (`_….<domain>`) are always kept: they
are harmless, and ACM reuses the same record for any certificate of that name in any region of the
account. Delete them yourself if no certificate needs them.

### Protecting the endpoint

With `domain()`, only the environment's CloudFront distribution can call the Web Function endpoint (0.11.4). Arco:

- sets the endpoint to `IamAuth`, whatever `auth()` says (`auth('iam')` with `domain()` is allowed and means the same);
- gives the distribution an origin access control that signs each request to the origin (SigV4);
- adds one statement, `ArcoCloudFront`, to the Web Function's resource-based policy: `cloudfront.amazonaws.com` may call
  `lambda:InvokeWebFunctionEndpoint` on the endpoint when `aws:SourceArn` is this distribution. Statements you added
  to that policy are left alone.

A request sent directly to the `*.lambda-web.<region>.on.aws` URL has no valid signature and answers 403. That is why
`arco-laravel`'s `TrustProxies::at('*')` is safe for `X-Forwarded-Host` with `domain()`: the header can only come from
CloudFront, and the `ForwardHost` function overwrites whatever the viewer sent. Without `domain()` the endpoint is
public, so do not use `X-Forwarded-Host` for security decisions there.

On an existing environment the deploy adds the access control, then the policy, waits until the distribution is
`Deployed`, and only then switches the endpoint to `IamAuth`. Removing `domain()` from `arco.php` removes the statement
first and, with `auth('public')`, makes the endpoint public again. `arco info` shows the effective endpoint auth, and
`arco info --json` has the key `endpoint_auth`. The deploy needs extra permissions (see
[Permissions for custom domains](getting-started.md#permissions-for-custom-domains)).

Because an unsigned request gets 403, the post-deploy check probes `/up` with a request signed with the deploy
credentials (SigV4, service `lambda`), so the deploy profile needs `lambda:InvokeWebFunctionEndpoint` on the endpoint.
`auth('iam')` without `domain()` keeps using the platform init report.

**Known residual.** CloudFront appends the viewer's address to an `X-Forwarded-For` the viewer sent, and Laravel (with
every proxy trusted) takes the leftmost address as `request()->ip()`. A viewer can therefore still choose the IP your
app sees. Do not rely on `request()->ip()` for rate limiting, audit logs or allowlists in security-sensitive code.

### Limits

- The origin read timeout is `timeout() + 2` seconds, at most 60 (CloudFront's default limit).
- One distribution per environment; no WAF, no apex redirects.

## VPC

```php
->environment('staging', fn (Environment $env) => $env
    ->vpc(subnets: ['subnet-0abc1234'], securityGroups: ['sg-0abc1234']))
```

`vpc()` (0.12) puts every compute piece of the environment inside your VPC, so the app can reach private
resources such as RDS, ElastiCache or an internal API: the Web Function, the console (and so `deployCommands()`
and the scheduler) and the queue workers. It can be set on the app or on an environment; `->vpc(false)` on an
environment clears an app-level one. Arco does not create VPCs, subnets, NATs, endpoints or security groups.

- **The Web Function** gets a Lambda *network connector*, one per environment, named like the function
  (`<app>-<env>`), and its revision pins the connector version. The connector is managed by an operator role
  `arco-<app>-<env>-network` (at most 64 characters; see Ownership below) that Arco creates, tags and guards like the execution role.
  The role trusts `lambda.amazonaws.com`; its inline policy (the one AWS documents for operator roles) grants
  `ec2:CreateNetworkInterface`, `ec2:CreateTags` on network interfaces (only when `ec2:ManagedResourceOperator` is
  `network-connectors.lambda.amazonaws.com`), and
  `ec2:DeleteNetworkInterface`, `ec2:DescribeNetworkInterfaces`, `ec2:DescribeSubnets` and
  `ec2:DescribeSecurityGroups`. It carries `permissionsBoundary()` if set. With `role()` Arco still creates the
  operator role (it is not the execution role), without a boundary, because `permissionsBoundary()` stays
  refused together with `role()`.
- **The console and the workers** are classic Lambda functions: they get `VpcConfig` with the same subnets and
  security groups. The managed execution role gets the network interface statements (`ArcoVpcAccess`:
  `ec2:CreateNetworkInterface`, `ec2:DescribeNetworkInterfaces`, `ec2:DescribeSubnets`,
  `ec2:DeleteNetworkInterface`, `ec2:AssignPrivateIpAddresses`, `ec2:UnassignPrivateIpAddresses`). With `role()`
  you add them yourself (`AWSLambdaVPCAccessExecutionRole` has them).

### Limits

- At least 1 and at most 16 subnets; at least 1 and at most 5 security groups (5 is the classic Lambda
  `VpcConfig` limit, the stricter one).
- Subnet ids look like `subnet-...`, security group ids like `sg-...`; no duplicates. Arco does not check that
  the subnets share a VPC; Lambda rejects that and Arco shows its error.
- IPv4 only. One network for the whole environment: the console cannot stay outside the VPC.
- **One subnet works** (checked on real AWS). Two subnets in different Availability Zones are recommended for
  production: with one, the environment loses VPC access while that zone is down.

### The VPC needs a NAT

A function in a VPC has no internet access by itself, and that includes the AWS APIs. Without a NAT gateway or
NAT instance (or VPC endpoints), calls to public addresses time out. Arco cannot judge your routing, so it does
not check it. What an Arco app calls at runtime:

- **SSM Parameter Store** and **Secrets Manager**, for `arco-ssm:` and `arco-secret:` references (read at
  startup);
- **SQS**, to dispatch jobs;
- **S3** and **DynamoDB** (storage, cache, tables, failed jobs): a free *gateway endpoint* is enough, a NAT also
  works. `endpoints:` makes Arco take care of them (see [Gateway endpoints](#gateway-endpoints));
- **S3, for the runtime binary:** a console or worker whose zip has no `bin/php` downloads the FrankenPHP binary
  from the code bucket at cold start, so without S3 access (gateway endpoint or NAT) it cannot start.

Whatever else your app calls (payment APIs, mail) needs the NAT too. For S3 and DynamoDB alone you can skip the
NAT: `endpoints: ['s3', 'dynamodb']` ([Gateway endpoints](#gateway-endpoints)). To reach a database in another VPC
or account (peering, transit gateway), the routes and security groups are yours as well.

### Gateway endpoints

```php
Environment::make('staging')->vpc(
    subnets: ['subnet-0abc1234'],
    securityGroups: ['sg-0abc1234'],
    endpoints: ['s3', 'dynamodb'],
);

// Your own S3 endpoint; Arco may add the missing route tables to it:
->vpc([...], [...], endpoints: ['s3' => 'vpce-0abc1234def567890', 'dynamodb']);
```

`endpoints` (0.12.1) makes sure every route table of the environment's subnets sends S3 and/or DynamoDB through a
*gateway endpoint*. Gateway endpoints are free; a NAT is not. With them, an app in a VPC without a NAT still reaches
the code bucket (including the runtime binary a console or worker downloads at cold start), `storage()`, `cache()`,
`table()` and the failed-jobs table. Without `endpoints` nothing changes: no new calls, no new permissions.

- **A list that mixes two forms:** a service name (`'s3'`, `'dynamodb'`: "make sure it is routed") or
  `service => 'vpce-...'` ("route it through this endpoint of mine"; this also authorizes Arco to add the missing
  route tables to it). Only `s3` and `dynamodb`, each at most once. An environment's `vpc()` replaces the app's as a
  whole, as for the rest of `vpc()`.
- **Checked at deploy:** a named endpoint must exist, be a Gateway endpoint, be for that service in the deploy region
  and be in the VPC of the subnets. Otherwise the deploy stops before anything changes. The subnets must share one VPC.
- **Not supported:** interface endpoints (SSM, Secrets Manager, SQS...: they cost money, keep using your NAT) and
  endpoint policies. Arco creates endpoints with the default full-access policy and does not read or judge the policy
  of one of yours.

**What Arco does.** For each service, on every deploy, it reads the route table of each subnet (its explicit
association, or the VPC's main route table) and the gateway endpoints of that service in the VPC. A route table
that any gateway endpoint lists is covered, whoever owns the endpoint (a route table belongs to one gateway endpoint
per service). For the route tables left uncovered:

1. **You named an endpoint** in `endpoints`: Arco adds them to it.
2. **The VPC has an endpoint Arco created** for the service: Arco adds them to it.
3. **The VPC has no gateway endpoint for the service:** Arco creates one with all of them and waits until it is
   available (with the elapsed time).
4. **The VPC has only endpoints that are not Arco's** (yours): a decision is needed. In a terminal, Arco lists the
   endpoint(s) and the route tables and asks: add them to your endpoint (you pick one if there are several), go on
   without (the app will time out on that service from those subnets), or cancel. Either way it prints the
   `endpoints: ['s3' => 'vpce-...']` line that makes it automatic next time. **In CI, with `--json`, or with
   `--yes`, the deploy fails** with `deploy.endpoint_routes_missing`, naming the endpoint, the route tables, the
   `aws ec2 modify-vpc-endpoint --vpc-endpoint-id ... --add-route-table-ids ...` command and the `arco.php` line.
   `--yes` never answers this question: "yes to everything" must not change a resource of yours without you having
   written it down.

An endpoint that already exists is used, never deleted. Arco never changes one of yours unless you named it, or said
yes in the question, and never tags it. This happens before anything else changes (before the code bucket and the
upload), so cancelling or failing in CI leaves AWS untouched. The changes (create, add route tables, tag) run after
the execution role and before the network connector and the new revision, so the first cold start already reaches S3.

**Sharing.** An endpoint Arco creates carries the tag `arco:managed = gateway-endpoint` plus the usual `arco:app` and
`arco:environment` tags. Every environment that relies on it, of any app in the VPC, adds its own usage tag
`arco:uses:<app>/<env>`; the creator has no special rights. EC2 allows 50 tags per resource, so about 47
environments can share one; past that, the deploy fails with `deploy.endpoint_tag_limit`. The environment also keeps
the ids it uses in the tag `arco:gateway-endpoints` of its Web Function, which is how a later deploy or `arco remove`
knows what to release without searching AWS (without `endpoints` and without that tag there are no new calls).

**Leaving.** An environment stops using an Arco endpoint when the service leaves `endpoints`, `vpc()` is removed, the
subnets move to another VPC, or `arco remove` runs. Arco removes the environment's usage tag, and deletes the endpoint
once no usage tag is left (it reads the tags again right before deleting). On `deploy` this runs after the new
revision is verified; on `arco remove`, after the functions are deleted. **Route tables are never removed** from an
endpoint that stays, yours or Arco's: something else may rely on the route, and a route left behind only sends S3 or
DynamoDB traffic through a free endpoint. To remove one by hand:
`aws ec2 modify-vpc-endpoint --vpc-endpoint-id vpce-... --remove-route-table-ids rtb-...`.

**Limitations.**

- A failed release is a warning that prints the manual commands (`aws ec2 delete-tags`, `aws ec2 delete-vpc-endpoints`).
  On `deploy` the next deploy tries again; during `arco remove` it is **not** retried, because the record lived on
  the function that was just deleted.
- If a first deploy fails after Arco created and tagged an endpoint but before the Web Function exists, the usage tag
  is not recorded on it. The next successful deploy picks it up; but `arco remove` after only failed deploys leaves
  it. Remove the tag and, if no `arco:uses:` tag is left, the endpoint by hand:
  `aws ec2 delete-tags --resources vpce-... --tags Key=arco:uses:<app>/<env>` and
  `aws ec2 delete-vpc-endpoints --vpc-endpoint-ids vpce-...`.
- Two deploys in the same VPC at the same time have no lock. One that conflicts (a route table already belongs to
  another endpoint of the service) reads again and retries once; if it still conflicts, it fails with
  `deploy.endpoint_conflict` and succeeds when run again.
- Behind an emulator endpoint endpoints are skipped, like `vpc()` itself.

`arco info` shows an Endpoints line (`s3 vpce-... (yours) · dynamodb vpce-... (Arco, 2 environments)`, with
`missing rtb-...` for a service with uncovered route tables); `arco info --json` has `vpc.endpoints`, a list of
`{service, id, arco, environments, route_tables, missing_route_tables}`, `[]` without `endpoints` and `null` when it
cannot be read. `info` only reads and never asks.

### Changing and removing `vpc()`

Changing subnets or security groups creates a new connector version and new revisions, and traffic moves as in any
deploy, without downtime. Old connector versions are deleted after a successful deploy, except the latest one and
those the live and previous revisions pin (the automatic rollback needs them). A failure there is a warning.

A connector that ends `FAILED` fails the deploy before any revision exists, with the reason: a wrong subnet or
security group, no free IP addresses, or missing operator role permissions. Fix `vpc()` and deploy again: a failed
connector that no live revision uses is deleted and created again (with a warning). One the live revision uses is
left alone, and the message prints the `aws lambda-core delete-network-connector` command to delete it by hand. The
first connector takes about 4 minutes to become active.

**Ownership.** Lambda cannot tag a connector, so Arco recognizes its own by the operator role: a connector named
`<app>-<env>` whose operator role is Arco's `arco-<app>-<env>-network`. With `vpc()`, a connector with that name and
another operator role stops the deploy before any change, **even with `--force`** (Arco cannot take it over):
remove or rename it, or change the app or environment name. The operator role is checked like the execution role
(its tags, `--force` rules as usual) before the deploy writes its policy and before a deploy or `arco remove`
deletes it. A name longer than 64 characters is shortened and keeps a short hash of `<app>-<env>`, so two
environments never share an operator role.

Removing `vpc()` (or `vpc(false)`) deploys the environment out of the VPC first. Only after that deploy is
verified does Arco delete the connector and then the operator role. If the verification fails, the next deploy
finishes the removal. If the delete itself fails, the deploy still succeeds, the warning prints the
`aws lambda-core` and `aws iam` commands to run by hand, and later deploys do not try again. Before the connector,
the same cleanup deletes the console and worker versions still in the VPC (they are no rollback target any more)
and waits until Lambda has deleted their network interfaces, usually a few minutes and up to about 20: Lambda
deletes them with the execution role, which keeps the network interface statements until the next deploy. Lambda
shares one interface among the functions of the account with the same subnets and security groups, so one that
another function (another environment or app) still uses is not waited for. An interface still there after 30
minutes is a warning: wait until it is gone before the next deploy. Older revisions that pinned
the connector cannot serve traffic once it is gone; this matters only if you move traffic by hand.

`arco remove` deletes the Web Function, the console and the workers, then waits until the connector is gone, then
deletes the operator role. When the console or a worker was in the VPC, it waits until Lambda has deleted their
network interfaces before it deletes the execution role (up to about 20 minutes): a role deleted earlier leaves
them in the VPC for good, where they block deleting its subnets and security groups. If they are still there
after 30 minutes, or cannot be read, the execution role is kept and the warning prints the commands that finish
the job. A connector that Arco did not create (its operator role is not Arco's) is left alone.
`arco info` shows the subnets, security groups, connector id, version and state; `arco info --json` has a `vpc`
object, or `null`.

Deploy permissions: see [Permissions for VPC](getting-started.md#permissions-for-vpc). Behind an emulator
endpoint the VPC is skipped with a note (see [Try your app locally with Floci](local-floci.md)).

## Runtime options

Set with `->runtime(fn (Runtime $r) => ...)`.

| Method | Default | Meaning |
|---|---|---|
| `php(string)` | `8.5` | PHP minor (`'8.5'`): the newest runtime build of that PHP version known to your Arco version. An unknown minor is a validation error that lists the known ones. `arco init` writes `->php('8.5')` |
| `frankenphp(string)` | unset | Only builds whose FrankenPHP version starts with this one (`'1.13'` or `'1.13.0'`); combines with `php()` |
| `build(string)` | unset | Exact runtime build id, such as `php8.5.11-fp1.13.0-r2`; cannot be combined with `php()` or `frankenphp()` |
| `binary(string $frankenphp, ?string $php = null)` | unset | Your own Linux arm64 FrankenPHP binary instead of a runtime build; with `php:`, a static PHP CLI (same PHP version and extensions) that the console function and the workers run as `bin/php` instead of downloading FrankenPHP at cold start. Both must exist and be arm64 ELF files |
| `workers(int)` | 4 | Octane worker count (>= 1) |
| `maxThreads(int)` | 64 | FrankenPHP `max_threads`, the ceiling the thread pool grows to (>= `workers`) |
| `threads(int)` | 8 | FrankenPHP `num_threads` for non-worker requests (>= 1) |
| `phpIni(array)` | `[]` | `php.ini` overrides, merged by key; applied to web requests and to commands on the console function (`arco command`, deploy commands, tinker). Arco sets `session.upload_progress.enabled=0` and `fatal_error_backtraces=0` underneath (php.net's hardening for CVE-2026-109618/109619); `phpIni()` can override them |

The selectors resolve per level: an environment's `build()` or `binary()` replaces the app's `php()` and
`frankenphp()`, and an environment's `php()` or `frankenphp()` replaces the app's `build()`. Mixing
`build()` with `php()` or `frankenphp()` at one level is a validation error.

### Runtime builds

A runtime build is an immutable release of `multi-api/arco-runtime`: a FrankenPHP binary and, since
`php8.5.11-fp1.13.0-r2`, the static PHP CLI of the console function. Its id is `php<php>-fp<frankenphp>-r<n>`
(for example `php8.5.11-fp1.13.0-r2`: PHP 8.5.11, FrankenPHP 1.13.0, revision 2). The ids of Arco 0.8 and
earlier, `1.13.0-r1` and `1.13.0-r2`, stay valid as aliases of `php8.5.11-fp1.13.0-r1` and
`php8.5.11-fp1.13.0-r2`: `build('1.13.0-r2')` and packages built with it deploy the same binaries.

Each Arco release lists the builds it knows in a manifest, so a given Arco version and `arco.php` always
deploy the same bytes. Without `php()`, `build()` or `binary()` Arco deploys the newest build of its default
PHP minor (8.5 in this release). **Default-build policy:** a 1.x release may move that default to a newer PHP
**patch** or a newer build of the same minor (security fixes, a newer FrankenPHP), never to another PHP minor:
a new minor is opt-in with `php()`. Pin `build()` to take no change at all on an Arco upgrade. `arco info`
shows the build that will deploy (`runtime.build`) and where the choice came from.

Downloads are verified by size and SHA-256 and cached in `~/.arco/cache/runtime/<build>/`.

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
