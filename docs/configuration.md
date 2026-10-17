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
`queue()` merges by queue name: an environment's options for a queue go over the app's, and `queue(name, false)` drops it there.
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
| `logRetention(int\|false)` | not managed | Days CloudWatch keeps the web, console and queue worker logs (1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922, 3288 or 3653); `false` = never expire; not set = Arco leaves the groups as they are |
| `consoleTimeout(int)` | 900 | Timeout of the console function in seconds (3 to 900) |
| `protected(bool = true)` | on for `production` | Ask for confirmation before `arco tinker`; `protected(false)` turns it off |
| `certificate(arn)` | looked up or requested | ACM certificate ARN in `us-east-1` to use for the domains |
| `queue(name, ...)` | none | An SQS queue and the worker function that runs its jobs (see [Queues](#queues)); `queue(name, false)` drops an app-level queue from an environment |

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

The managed execution role only gets what Arco itself needs: writing logs (web, console and queue workers), sending to and reading the queues in `arco.php`, reading
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
`false` or `null`, `settings.scheduler` the minutes or `null` (with `settings.schedulerPaused`, see
Scheduler), and `settings.logRetention` the days, `false`
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

## Queues

`->queue('emails')` gives the environment an SQS queue and a **worker function** that runs its jobs:

```php
App::make('shop')
    ->region('us-east-1')
    ->queue('default')                                  // the default queue (the first one)
    ->queue('emails', timeout: 120, memory: 512, tries: 5)
    ->environment('staging', fn (Environment $env) => $env
        ->queue('emails', concurrency: 2))              // merged over the app's options
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
configured in `config/queue.php`), so `queue:failed` and `queue:retry` (through `arco command`) work as
usual. Arco never touches that store.

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
| `concurrency` | unset | Maximum concurrent worker invocations (event source mapping `MaximumConcurrency`, at least 2); unset means no limit beyond the account's |
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
`SQS_QUEUE` there overrides them, and the deploy prints a note (an `ARCO_QUEUES` of your own overrides the
map without a note).

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

Queues are **never deleted** by Arco, so no message is lost. Removing a queue from `arco.php` (or
`arco queue:remove`) makes the next deploy delete its worker and event source mapping and keep the SQS queue
and its dead-letter queue in AWS, with their messages: the deploy names each kept queue, its URL and about
how many messages it holds. Read or move what you need, then delete them in the SQS console. `arco remove`
does the same for every queue of the environment.

- Adding a queue back under a kept name stops the deploy before any change and prints the line to attach
  the kept queue with `existing:` (or delete the kept queue, or choose another name). The same happens for a
  kept dead-letter queue (for example after `dlq: false` and back), with the option to turn it off.
- Changing `fifo` on a queue Arco created stops the deploy before any change: CloudFormation would have to
  replace the queue. Remove it and add a queue with another name instead (or attach the kept one with
  `existing:`).

### Deploys

The queues Arco creates live in a CloudFormation stack per environment, `arco-<app>-<env>-resources`
(tagged with the app and environment; Arco refuses a stack of that name that is not tagged as its own). The
deploy updates it **before** any code, so a new queue exists before code that sends to it; a failure there
stops the deploy before traffic moves. Workers are created or updated with the rest and switched to the new
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
vendor/bin/arco queue:set emails concurrency 10 --env=production
vendor/bin/arco queue:set emails concurrency none --env=production   # no limit
vendor/bin/arco queue:remove emails --env=production
vendor/bin/arco queue:list --env=production
vendor/bin/arco queue:dlq emails --env=production
vendor/bin/arco queue:dlq emails --env=production --redrive
```

- `queue:add <name>` takes `--timeout`, `--memory`, `--concurrency`, `--batch-size`, `--tries`, `--no-dlq`,
  `--fifo`, `--existing=<name, URL or ARN>` and `--default`; `queue:set <name> <option> <value>` changes
  `timeout`, `memory`, `concurrency` (`none` clears it), `batch-size`, `tries`, `dlq` or `default` (`on` or
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
