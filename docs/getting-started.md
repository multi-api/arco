# Getting started

## Install

```bash
composer require multiapi/arco-laravel laravel/octane   # Laravel apps only
composer require --dev multiapi/arco
```

`multiapi/arco-laravel` is a regular (non-dev) dependency: its service provider ships with your
app and trusts the Lambda front end's `X-Forwarded-*` headers, so Laravel sees `https` and the
real client IP. Arco installs production dependencies only (`composer install --no-dev`), so a
dev-only install would leave it out of the deployed package.

Arco needs PHP >= 8.3 and Composer locally. Laravel apps need Laravel >= 11 and Octane (the
Laravel preset tells you to install it if it is missing). Arco builds for Linux arm64 and
downloads a checksum-verified FrankenPHP binary on first use (cached under `~/.arco/cache`).

Keep `multiapi/arco` and `multiapi/arco-laravel` on the same release line (0.9 with 0.9; any 1.x with any 1.x).
Upgrading an app deployed with an earlier Arco: read [Upgrading](upgrading.md) first.

## `arco init`

```bash
vendor/bin/arco init
```

`init` detects the framework, asks for the app name and the AWS region (or take them from
`--name`, `--region`), and writes `arco.php` with one environment (`production` by default; pass
`--env` several times for more, for example `--env=staging --env=production`). It also adds
`.arco/` to `.gitignore`. Use `--force` to overwrite an existing `arco.php`. When it finishes it
prints the `arco secret:set` commands to run and the deploy command. The app it writes pins the PHP minor of
the default runtime build, `->runtime(fn (Runtime $r) => $r->php('8.5'))`, so an Arco upgrade never moves it to
another PHP minor (see [Runtime builds](configuration.md#runtime-builds)).

For every secret-like key in `.env` (`APP_KEY`, `*_KEY`, `*_SECRET`, `*_PASSWORD`, `*_TOKEN`),
`init` writes an `arco-ssm:` reference into `arco.php`. It skips keys whose value is empty, `null`
or `""` (nothing to store) and all `AWS_*` keys (Lambda reserves them, and the execution role
replaces static credentials).

Supported regions: us-east-1, us-east-2, us-west-2, ap-northeast-1, eu-west-1.

### What `init` detects and asks

For Laravel apps `init` also reads the project (static scan of `app/`, `routes/`, `bootstrap/app.php`, `config/cache.php`,
`composer.json` and `.env`, and the schedule from `php artisan arco:schedule-intervals`, which has a 60 second
timeout; when artisan cannot boot, the schedule
is read from the code) and asks, in this order:

1. Console function (default yes): `arco command`, tinker, deploy commands and the scheduler.
2. `migrate --force` on every deploy, only with the console and a server database (not SQLite).
3. Scheduler, only when the app has a schedule, or when a schedule was found but its times could not be read
   (then it suggests every minute): suggests the interval its tasks need.
4. SQS queues with their worker functions, from the queues the code uses.
5. Failed-jobs table (DynamoDB), only with queues; the default is yes when the app has no SQL database.
6. S3 storage, when the app uses S3 (name defaults to `files`).
7. DynamoDB cache table, when the app uses the DynamoDB cache.
8. CloudWatch log retention (default 30 days, or never delete).

Other frameworks are asked only the console and log retention questions. Whatever you answer, the written
`arco.php` is validated for every environment first, and nothing is written when it would not validate.
Declining the console skips the migrate and scheduler questions.

Each question has a flag: `--console` / `--no-console`, `--migrate` / `--no-migrate`, `--scheduler=<minutes>`,
`--log-retention=<days>`, `--queue=<name>` (repeatable), `--failed-jobs-table`, `--storage=<name>` and
`--cache`. `--scheduler` and `--migrate` turn the console on; combined with `--no-console` they are an error.
With `--no-interaction` (or no terminal) `init` writes only what the flags say, then prints
`Not added (no terminal to ask). Add each one with:` and one `vendor/bin/arco ...` line (`console:add`,
`scheduler:set`, `logs:retention`, `queue:add`, `storage:add`, `cache:add`) for each detected piece it left out.

### Packages the features need

After writing `arco.php`, `init` reads `composer.lock` and, when the chosen features need a package the lock
does not list under `packages` (`packages-dev` is not deployed), prints one line to run, for example:

```
Install the Composer packages these features need before deploying:
  composer require league/flysystem-aws-s3-v3:^3.0 aws/aws-sdk-php multiapi/arco-laravel:^0.9
```

Versions are not compared here; `arco deploy` checks them in the built package. Without a `composer.lock`
nothing is checked.

### Custom domains in `init`

For every environment `init` asks whether to use a custom domain (default: no), then for the
domains, comma separated, and where their DNS is. It proposes the deploy account (the default),
each of your local AWS profiles, and "outside AWS". Arco then checks only the profile you picked:

```
Use a custom domain for production? (yes/no) [no]:
Domain(s), comma separated: shop.example.com, api.shop.example.com
Where is the DNS for example.com?
  [@deploy        ] Route 53 in the deploy account
  [dns-account   ] Route 53 in profile dns-account
  [@manual        ] Outside AWS — I'll create the records myself
> dns-account
Checking Route 53 of profile dns-account…
  ✓ shop.example.com: found zone example.com in profile dns-account (account 0123…)
  ✓ api.shop.example.com: found zone example.com in profile dns-account (account 0123…)
```

The result is written into the environment as `->domain([...])` plus `->dns('<profile>')` for a
profile other than the deploy profile, or `->dns(false)` for DNS outside AWS. If the profile lacks a
zone or cannot be used (for example an expired SSO session; Arco prints the
`aws sso login --profile …` command), `init` says why and asks again, so you can pick another
profile or the same one after logging in. The deploy profile is `AWS_PROFILE` or the one remembered
for the environment; when neither is set, choosing the deploy account skips the check and deploy
looks for the zones.

Without questions, use flags: `--domain=<host>` (repeatable; applies to every `--env`),
`--dns-profile=<profile>` (checked, `init` fails if a zone is missing) or `--dns=manual`. With only
`--domain`, the deploy account is used and checked when the deploy profile is known.

### Adding and removing domains later

```bash
vendor/bin/arco domain:add www.example.com shop.example.com --env=production
vendor/bin/arco domain:remove shop.example.com --env=production
```

`domain:add` validates the hosts, merges them with the environment's list, asks where the DNS is
the same way as `init` (`--dns-profile` and `--dns=manual` answer it without a question;
`--profile` names the deploy profile), and edits `arco.php`. An explicit `->dns(...)` is never
changed without a flag or your answer: `->dns(false)` stays as is, and `->dns('profile')` is
checked in that profile only; if it lacks a zone, `domain:add` asks (with that profile preselected)
or, with `--no-interaction`, fails and tells you which flag to pass. `domain:remove` takes hosts out of the list and
removes `->domain()` when it becomes empty (`->dns()` stays; it is harmless). Both print the
resulting lines and only edit `arco.php`: run `arco deploy --env=<env>` to apply the change.

The edit is checked with `php -l` before it replaces `arco.php`. When the file has a shape Arco does
not edit safely (domains built from variables, or `domain()` set for the whole app), nothing is
written and the command prints the line to add by hand.

## Choosing the AWS profile

Arco picks credentials in this order:

| # | Source |
|---|---|
| 1 | `--profile` flag |
| 2 | Credentials or `AWS_PROFILE` in the process environment (CI) |
| 3 | `->profile('name')` for that environment in `arco.php` |
| 4 | The choice remembered in `.arco/local.php` (per environment, git-ignored) |
| 5 | Interactive prompt on the first deploy, listing your local profiles; the choice is saved to `.arco/local.php` |

In non-interactive mode, if nothing resolves, Arco fails with instructions. It never falls back
to the `default` profile. Change the remembered profile with
`vendor/bin/arco profile --env=production --set=my-profile`. If an SSO session has expired,
Arco prints the `aws sso login --profile <name>` command to run.

Profiles can live in `~/.aws/config` or `~/.aws/credentials`: static keys, IAM Identity Center
(SSO), `aws login`, `credential_process`, and role profiles (`role_arn` + `source_profile`, also
chained, with `external_id`, `role_session_name` and `duration_seconds`). Not supported:
`credential_source`, `mfa_serial` and `web_identity_token_file` profiles. Arco says so when one is
used.

**Account lock.** After the first successful deploy Arco offers to write `->account('123456789012')`
into the environment in `arco.php`. From then on, every command checks the caller's account
(`sts:GetCallerIdentity`) and stops if the profile points to a different account.

## Secrets

Never put secrets in `arco.php` or `.env`. `.env` and `.env.*` are never packaged, so they never
reach Lambda. Store secrets in AWS and reference them:

```bash
vendor/bin/arco secret:set app-key --env=production
```

The value is read from a hidden prompt or from stdin (never from a flag) and saved as an SSM
SecureString at `/<app>/<env>/<name>`. The command prints the reference to use:

```php
->variables(['APP_KEY' => 'arco-ssm:/myapp/production/app-key'])
```

Reference formats, resolved when the function starts:

- `arco-ssm:<parameter-name>`: an SSM parameter value.
- `arco-secret:<secret-id>`: a Secrets Manager string value.
- `arco-secret:<secret-id>#<json-key>`: one key of a JSON secret.

`arco deploy` checks that every reference exists and is readable before building, and grants the
execution role access to exactly the referenced parameters and secrets. Variables whose name ends
in `_KEY`, `_SECRET`, `_PASSWORD` or `_TOKEN` and whose value is plain text produce a warning
with a hint to use a reference; the deploy still proceeds, so treat the warning as a mistake to
fix.

## First deploy

```bash
vendor/bin/arco deploy --env=production
```

Arco prints the target (`Deploying myapp → production · account ... (profile) · region`), asks
before creating a new environment, builds, uploads, creates the Web Function and a revision,
routes traffic and verifies the health route (`/up` for Laravel, `/` for plain PHP). It ends
with the revision, the endpoint URL and the cold start time:

```
Deployed <revision>
https://<endpoint-url>
Cold start: <n> ms
```

The cold start is the init duration of the new revision's first instance, read from Lambda's
init report in CloudWatch Logs (in the streams named after the revision). Those events reach
CloudWatch Logs up to about 40 seconds late, so the health check alone decides whether the deploy
passed, and the cold start is read last, after the custom domains: Arco keeps looking for up to about
60 seconds from the start of that lookup, however long the domains took (at most 40 CloudWatch Logs calls). If the report has not
arrived by then, or CloudWatch Logs cannot be read (for example when throttled), the summary says
`Cold start: not available yet` and the deploy still succeeds. With `->auth('iam')` the report is
the only health signal, so Arco waits up to about 75 seconds for it before deciding; a missing or
unreadable report fails verification, with a message that says which of the two happened.

While building, Arco prints each phase (copying the project, `composer install --no-dev`, the
preset's build commands such as `php artisan event:cache`, writing the zip). Writing the zip shows
a progress bar in a terminal and `25%`/`50%`/`75%`/`100%` lines in CI logs.

The health route must answer with a 2xx status (redirects are not followed). With
`->auth('iam')` Arco cannot probe the endpoint without signing, so it relies on Lambda's init
report for the new revision instead.

If the new revision fails verification, traffic returns to the previous revision and the
recent logs are printed.

Before changing anything, the deploy checks that the function, role and scheduler rule it would update
belong to this app and environment (their `arco:app` and `arco:environment` tags), and stops otherwise:
see [Names and ownership](configuration.md#names-and-ownership). `--force` overrides that check
(dangerous: only for a resource you know is yours).

### Build once, deploy later

`arco package --env=production` builds the zip without deploying. It is written to
`.arco/build/arco-<app>-<env>.zip` (git-ignored); `--output` sets another path, and a path inside
the project is never packaged again. Deploy that zip later, for example from a CI job that built it in an earlier step:

```bash
vendor/bin/arco package --env=production --output=.arco/build/app.zip
vendor/bin/arco deploy --env=production --package=.arco/build/app.zip
```

`deploy --package` skips the build and runs the rest of the deploy as usual: secret checks,
upload (skipped when the same content is already in the code bucket), revision, traffic,
verification with rollback and custom domains. The zip carries an `arco-package.json` manifest
(app, environment, preset, runtime build, content hash, Arco version, creation time). Arco
refuses a zip without it, a damaged zip, or one built for another app, environment or preset. It
warns when the zip was built by another Arco version, with another runtime build, or when the
runtime settings in arco.php (`workers`, `maxThreads`, `threads`, `phpIni`) changed since.

Environment variables are not in the zip: they belong to the revision and are read from the
current `arco.php` at deploy time, so changing a variable does not require rebuilding. The same holds for
the PHP CLI of a custom `binary(..., php: ...)`: the console package of `deploy --package` takes it from the
current `arco.php`, not from the zip.

## Changing settings

```bash
vendor/bin/arco set concurrency 16 --env=production
vendor/bin/arco set max-instances 20 --env=production
vendor/bin/arco set log-level debug --env=production
vendor/bin/arco unset rate-limit --env=production
vendor/bin/arco set timeout 60 --app          # app level (all environments)
```

`set` and `unset` edit `arco.php` (the same way `domain:add` does) and make no AWS calls; run
`arco deploy --env=<env>` to apply. Keys: `concurrency`, `max-instances`, `rate-limit`, `timeout`,
`log-level`, `console-timeout`, `console-memory`. See [Changing settings from the CLI](configuration.md#changing-settings-from-the-cli).

## Inspect and remove

```bash
vendor/bin/arco info --env=production          # resolved settings and their source, URL, active revision
vendor/bin/arco info --env=production --json   # machine-readable output
vendor/bin/arco info --env=production --resources  # every AWS resource tagged for the environment
vendor/bin/arco remove --env=production        # asks you to type the environment name
```

`info` takes `--profile` like the other commands and, on an environment with an `account()` lock, refuses a
profile of another account.

`remove` first checks the tags of the functions, role and scheduler rule it deletes and stops, deleting
nothing, when one belongs to another app or environment. It then deletes the function, the console function, the queue workers and the endpoint and the execution role, and with custom domains the
CloudFront stack, its DNS aliases and a certificate Arco requested. The shared code bucket,
your SSM parameters, the SQS queues (with their messages), the storage buckets (with their files) and the
cache and failed-jobs tables (with their items) are kept; `remove` lists each one it kept.

## Operating the app

By default a deploy creates only the web function. Add `->console()` to `arco.php` (or run
`arco console:add --env=<env>`) to also deploy a **console function**, `<app>-<env>-console`: a regular Lambda
function with the same code, variables, secrets and role as the web function, used to run commands, deploy
commands and the scheduler. `arco command`, `arco tinker`, `deployCommands()` and `arco deploy --migrate`
need it and fail before touching AWS when it is off. `arco console:remove` (or `->console(false)`) deletes it
on the next deploy.

Apps deployed with Arco 0.2 or 0.3 have a console function and need a `console()` setting: see
[Upgrading to 0.4](upgrading.md#04).

### Scheduler and log retention

`arco scheduler:set 5 --env=<env>` (or `->scheduler(5)`) runs `php artisan schedule:run` every 5 minutes on
the console function, from an EventBridge rule aligned to the UTC clock (:00, :05, ...). Allowed intervals:
1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60. It turns the console on. Without a number the command suggests one
from your scheduled tasks, reading them with `php artisan arco:schedule-intervals` run with `ARCO=1` (so
your own `env('ARCO')` branches see it); a deploy warns if a task needs a shorter interval. `arco
scheduler:remove` turns it off and the next deploy deletes the rule. Both offer to apply the change in AWS
right away, without a deploy (`--apply` skips the question). `arco scheduler:pause --env=<env>` and `arco
scheduler:resume --env=<env>` stop and restart the rule at once without editing `arco.php`; deploys keep a
paused scheduler paused.

`arco logs:retention 30 --env=<env>` (or `->logRetention(30)`) keeps the web, console and queue worker logs for 30 days;
`never` stops them from expiring, `--remove` goes back to the default, 30 days (before 0.9 retention was unmanaged: use `never` to keep logs forever). Each deploy also
deletes unused `runtime/*` binaries from the code bucket (older than 24 hours, none referenced by a published
version), best effort with a warning.

### Queues

```bash
vendor/bin/arco queue:add emails --env=production      # writes ->queue('emails') in arco.php
vendor/bin/arco deploy --env=production
vendor/bin/arco queue:list --env=production             # messages, dead-letter queue, worker
vendor/bin/arco queue:dlq emails --env=production       # what ended in the dead-letter queue
vendor/bin/arco logs --env=production --queue=emails --follow
```

Each `->queue('<name>')` in `arco.php` gets an SQS queue (`<app>-<env>-<name>`) with a dead-letter queue, and a
worker function (`<app>-<env>-queue-<name>`) that Lambda feeds from it. The worker runs your jobs with
Laravel's own queue worker, booted once per instance, so retries, `$backoff`, `failed()` and the
`failed_jobs` table work as with `queue:work`. Arco sets `QUEUE_CONNECTION=sqs` and maps queue names, so
`dispatch(...)->onQueue('emails')` needs no other change. The app needs `multiapi/arco-laravel` 0.5+ and
`aws/aws-sdk-php` in `require` (`composer require aws/aws-sdk-php`); the deploy checks the package for both
and stops before any change when one is missing.
`arco queue:set` and `arco queue:remove` change a queue in `arco.php`; removed queues stay in AWS with their
messages. Options, FIFO, existing queues and permissions: [Queues](configuration.md#queues).

### Storage, cache and failed jobs

```bash
vendor/bin/arco storage:add uploads --env=production      # writes ->storage('uploads') in arco.php
vendor/bin/arco storage:public uploads avatars/ --env=production
vendor/bin/arco cache:add --env=production                # writes ->cache()
vendor/bin/arco failed-jobs:add --env=production          # writes ->failedJobsTable()
vendor/bin/arco deploy --env=production
vendor/bin/arco storage:list --env=production
```

Each `->storage('<name>')` gets a private S3 bucket, `<app>-<env>-<name>-<account>`. The first storage (or the
one with `default: true`) is Laravel's default disk (Arco sets `FILESYSTEM_DISK=s3`, `AWS_BUCKET` and
`AWS_URL`); when every storage says `default: false`, none is, and Laravel keeps its own default disk. Every
storage is also a disk of its own name, `Storage::disk('uploads')`, registered by `multiapi/arco-laravel` 0.6+. Laravel's S3
driver needs `league/flysystem-aws-s3-v3` (`composer require league/flysystem-aws-s3-v3 "^3.0"`) and
`aws/aws-sdk-php`. Public
prefixes, expiry rules and CORS are options of the storage (`storage:public`, `storage:expire`,
`storage:cors`).

`->cache()` creates a DynamoDB table `<app>-<env>-cache` and sets `CACHE_STORE=dynamodb`; `->failedJobsTable()`
creates `<app>-<env>-failed-jobs` and sends failed jobs there (they expire after about a week). Both need
`aws/aws-sdk-php`, and the failed-jobs table `multiapi/arco-laravel` 0.6+. The deploy checks the package for
what each of them needs and stops before any change, naming the `composer require` to run, when a package is
missing. Every one of them can point at a bucket or table you already have with `existing:`. Removed buckets and tables stay in AWS with their data. Details:
[Storage](configuration.md#storage) and [Cache and failed-jobs tables](configuration.md#cache-and-failed-jobs-tables).

### Commands

```bash
vendor/bin/arco command "migrate:status" --env=production   # production asks first; add --yes in scripts
vendor/bin/arco command "cache:clear" --env=production
vendor/bin/arco command "reports:build --month=10" --env=production --async   # returns at once
```

The whole command goes in one quoted string (it is split into arguments without a shell, so pipes
and redirects do not work); this one-string form is the one 1.x keeps. Arco prints the output and exits with the command's exit code, so it
works in scripts and CI. A command can run up to `console(timeout:)` (15 minutes by default);
`--timeout=<s>` lowers it. Use `--async` for longer work and follow it with
`arco logs --source=console --follow`. For a plain PHP app, name the script: `arco command "bin/console app:sync"`.
The console function must be on (`->console()`) and deployed: run `arco deploy` first.

Every command line is written to the console function's log (CloudWatch), as an audit trail. Values of
options whose name has a secret-looking word (`password`, `passwd`, `secret`, `token`, `key`, `apikey`,
`credential`, `auth`, `private`, in any case; words split on `-`, `_` and camelCase, so `--api-key`,
`--db-password` and `--auth-token` count but `--author` does not) are masked as `***`, in both
`--name=value` and `--name value` forms; the rest of the line is kept. A line with an unclosed quote is
logged only up to the first such option. The command's own output also goes to that log. Do not
pass secrets as arguments: put them in `variables()` with an `arco-ssm:` reference (`arco secret:set`)
and read them from the environment.

With the current runtime builds the console function carries a static PHP CLI (`bin/php`, about 91 MB) with the
same PHP version and extensions as the web runtime, and runs it directly: nothing is downloaded at cold
start. Measured in the Lambda base image in local Docker (not a Lambda guarantee): init about 0.2 s and
the first command 0.6 to 1 s, against about 3 s and 4 s when the binary is downloaded. The PHP CLI uses
about 91 MB of the 250 MB of unzipped code Lambda allows, which leaves about 159 MB for your app (sizes as
`arco package` prints them). The first deploy on a machine
(and `arco deploy --package`) fetches the PHP CLI from GitHub releases, once per runtime build, and
caches it in `~/.arco/cache/runtime/<build>/php`.

The console falls back to downloading FrankenPHP from the code bucket (`runtime/...`, checksum-verified,
once per execution environment) when you set a custom `binary()`, when the runtime build has no PHP CLI
(such as `1.13.0-r1`) or is unknown to your Arco version, when the console would pass 250 MB unzipped
only because of `bin/php`, or when the PHP CLI cannot be downloaded from GitHub (network, proxy, outage;
the deploy goes on). The deploy output says which case applies: a plain note for a custom `binary()`,
a warning for the others; in the size case, leave large
folders out with `->exclude([...])` to get the faster start back. A PHP CLI whose size or checksum does
not match stops the deploy before any AWS change.

### Migrations on deploy

```php
->deployCommands(['migrate --force'])   // app or environment level
```

or `arco deploy --migrate` for one deploy (Laravel only; `--no-deploy-commands` skips them, and the two
flags cannot be combined). `arco init` proposes `migrate --force` for Laravel apps with a server database.

Keep `--force`: in production Laravel asks to confirm `migrate`, nobody can answer and the deploy stops.
Write `migrate`, not `artisan migrate`: Arco runs artisan for you. Commands that write into the code
directory (`config:cache`, `route:cache`, `event:cache`, `optimize`, `storage:link`) fail on Lambda, where
`/var/task` is read-only; the build already runs `event:cache`. `config:clear`, `route:clear`,
`event:clear` and `optimize:clear` do nothing there. `arco deploy` warns about all of these (see
[Deploy commands](configuration.md#deploy-commands)).

Deploy commands run on the console version published by this deploy, after the new code is uploaded and
**before** traffic moves, one by one, with their output printed. If one fails, the deploy stops, the
previous revision keeps serving and `arco deploy` exits with that command's exit code. Arco never rolls
migrations back: if the health check fails after the traffic moved, traffic goes back to the previous
revision but the database keeps the new schema. Arco then lists the commands that ran and, for
migrations, suggests `arco command "migrate:rollback --force" --env=<env>` (`--force` because Laravel
asks for confirmation in production).

Because old and new code run against the same database for a moment, write migrations that both versions
accept (expand, then contract). For example, to rename `users.name` to `users.full_name`:

1. Deploy 1: add `full_name` (nullable), write both columns, backfill `full_name`.
2. Deploy 2: read `full_name` only.
3. Deploy 3: drop `name`.

Each step is safe to roll back to the previous deploy.

If the console package is over 250 MB unzipped even without `bin/php`, the console function is skipped with a warning and
deploys with deploy commands are refused before anything changes: leave large folders out with
`->exclude([...])`.

### Tinker

```bash
vendor/bin/arco tinker --env=staging
vendor/bin/arco tinker --env=production --execute="App\\Models\\User::count()"
```

The shell (PsySH: history, multi-line input, short class names) runs on your machine, from your project's
`vendor`, so `laravel/tinker` and `multiapi/arco-laravel` (which runs each statement in Lambda) must be in
the app's production dependencies (`require`). Each statement
runs in the console function as one invocation (about 100–400 ms). Variables are kept between
statements through a serialized context; values that cannot be serialized (closures, PDO connections,
open files) are dropped, and Arco names them. A database transaction cannot span statements.

Only the PsySH commands `help`, `exit`, `clear`, `history` and `buffer` are available; the others (`ls`,
`doc`, `show`, `timeit`, ...) are disabled because they would run on your machine instead of Lambda. The
history is a separate file per app and environment. `production` and environments marked `->protected()`
ask for confirmation first; `--yes` answers it (it is required without a terminal; `--force` still works but is deprecated). Every statement is
written to the console function's log, as an audit trail (with its SHA-256 and length, the code cut at
4000 bytes); results and variables are not, since they can hold secrets. `--execute` runs one statement and exits with
1 if it throws.

### Logs

```bash
vendor/bin/arco logs --env=production                         # last 10 minutes, web, console and queue workers
vendor/bin/arco logs --env=production --follow
vendor/bin/arco logs --env=production --since=2h --level=error
vendor/bin/arco logs --env=production --request=<request id> -v
```

Laravel log lines show as `HH:MM:SS LEVEL message [requestId=...]`; `-v` adds the context and stack
traces, `--platform` shows Lambda's own events, `--json` prints raw events, `--source=all|web|console|queues`
(default `all`) picks the functions (`queues`: every queue worker; `--queue=<name>` one worker, `--console`
the console), `--filter="text"` searches on CloudWatch's side and `--level` sets a
minimum level.

`--since` and `--until` take `30s`, `10m`, `2h`, `1d` or a date (`"2026-10-15 14:00"`); bare numbers are
rejected. `--since` defaults to `10m`. `--until` cannot be combined with `--follow`. When more than
10,000 events match, the earliest 10,000 are shown with a notice: narrow the range.

`--follow` uses CloudWatch Live Tail (about one second of delay). If Live Tail is denied
(`logs:StartLiveTail`) or unreachable, or PHP's `allow_url_fopen` is off, Arco says so and polls every
2 seconds instead; CloudWatch Logs receives events 10-40 s after they happen, so expect that delay.
Live Tail does not backfill, and the session needs a few seconds after it starts before new events
stream: events written between the history read and that moment can be missed. An event that appears
in both the history and Live Tail is printed once. Live Tail follows at most 10 log groups (the web function
uses two, the console one, each queue worker one): with more, `--follow` polls and says so; narrow it with
`--source`, `--console` or `--queue`. Ctrl+C ends `--follow` cleanly; a second Ctrl+C exits at once.

## Try it locally

With `AWS_ENDPOINT_URL` set, Arco deploys to a local AWS emulator instead of AWS. See
[Try your app locally with Floci](local-floci.md).

## Using Arco in CI

Provide AWS credentials through the environment (for example with
`aws-actions/configure-aws-credentials`) and make every command non-interactive:

```bash
vendor/bin/arco secret:set app-key --env=production --no-interaction < key.txt
vendor/bin/arco deploy --env=production --yes --no-interaction
vendor/bin/arco command "migrate:status" --env=production --yes --no-interaction
vendor/bin/arco remove --env=production --yes --no-interaction
```

`--yes` answers every confirmation: creating a new environment, the typed confirmation of `remove`, and the question protected environments (`production` by default) ask before `arco command`, `arco tinker` and `queue:dlq --redrive`. `--force` overrides a safety check instead (`init`, `deploy`, `deploy --force-dns`); `remove --force` and `tinker --force` are deprecated spellings of `--yes`.
Arco's own real-AWS end-to-end run deploys `examples/laravel` to a dedicated test account this way.

**Scripting.** `info`, `info --resources`, `logs`, `queue:list`, `storage:list` and `profile` take `--json`. Every
JSON document (each line, for `logs --json`) starts with `"schema": 1`; within schema 1, keys are only ever
added, never renamed or removed. A failure exits non-zero; with `--json`, `queue:list`, `storage:list` and
`profile` report it as `{"schema": 1, "error": "..."}`, and `info` adds an `error` key when AWS could not be
read. Commands exit with 0 on success and 1 on failure, except `arco command` (the exit code of the command it
ran) and a deploy stopped by a failing deploy command (that command's exit code). Parse `--json` and exit codes,
never the message texts: those change between releases and depend on the language.

### Minimal IAM permissions

A deploy role (like the one of Arco's own end-to-end run, in a dedicated test account) needs:

- `lambda:*WebFunction*`, plus `lambda:ListTags` (the ownership check reads the tags of the web
  function, the console function and the workers) and `lambda:TagResource` (only to tag a resource an older
  Arco left untagged)
- `iam:TagRole` on `arn:aws:iam::*:role/arco-*`, only to tag a role an older Arco left untagged (`iam:GetRole`
  already returns the role's tags)
- `iam:GetRole` (it also returns the `arco:region` tag the region check reads), `iam:CreateRole`,
  `iam:PutRolePolicy`, `iam:DeleteRolePolicy`, `iam:DeleteRole`, `iam:PassRole` on `arn:aws:iam::*:role/arco-*`
- `s3:*` on `arn:aws:s3:::arco-*` (and its objects)
- `ssm:PutParameter`, `ssm:GetParameters`, `ssm:AddTagsToResource` (`secret:set` tags the parameter for the
  environment) on your app's parameters (`parameter/<app>/*`)
- `logs:FilterLogEvents`, `logs:GetLogEvents`
- `sts:GetCallerIdentity`
- for the console function (`arn:aws:lambda:*:*:function:*-console` and `...:*-console:*`):
  `lambda:CreateFunction`, `lambda:UpdateFunctionCode`, `lambda:UpdateFunctionConfiguration`,
  `lambda:GetFunctionConfiguration`, `lambda:PublishVersion`, `lambda:CreateAlias`,
  `lambda:UpdateAlias`, `lambda:GetAlias`, `lambda:PutFunctionEventInvokeConfig`, `lambda:TagResource`,
  `lambda:ListVersionsByFunction`, `lambda:DeleteFunction`, `lambda:InvokeFunction`
- `logs:StartLiveTail` for `arco logs --follow` (Live Tail; without
  `logs:StartLiveTail` follow polls instead). `logs:FilterLogEvents` covers `arco logs` without `--follow`
- for the scheduler (`arn:aws:events:*:*:rule/arco-*-scheduler`): `events:PutRule`, `events:PutTargets`,
  `events:DescribeRule`, `events:ListTagsForResource` (the ownership check), `events:RemoveTargets`, `events:DeleteRule`, `events:TagResource`,
  `events:DisableRule`, `events:EnableRule` (`scheduler:pause` / `scheduler:resume`); on the console
  function: `lambda:AddPermission`, `lambda:RemovePermission`, `lambda:GetPolicy`
- for the version check of the runtime clean-up: `lambda:ListVersionsByFunction` on the Arco functions
  (it reads every tagged function's versions), and `tag:GetResources` (without it the clean-up warns on every deploy)
- for log retention, on every deploy (an environment without `logRetention()` gets the 30-day default):
  `logs:CreateLogGroup`, `logs:PutRetentionPolicy`, `logs:DeleteRetentionPolicy`, `logs:DescribeLogGroups` (Arco
  reads the current retention and changes, and reports, only a group whose retention differs; without it every
  deploy sets the retention and says so), and `logs:TagResource` (Arco tags each log group for the environment;
  without it the groups stay untagged, with one note per deploy)
- for the environment's Resource Group `arco-<app>-<env>` (a view of its tagged resources in the AWS console):
  `resource-groups:GetGroupQuery`, `resource-groups:CreateGroup`, `resource-groups:Tag`,
  `resource-groups:GetGroup`, `resource-groups:GetTags`, `resource-groups:UpdateGroupQuery` and, for
  `arco remove`, `resource-groups:DeleteGroup`. Optional: without them the deploy and the remove print a note
- for `arco info --resources` and the list `arco remove` prints before it asks: `tag:GetResources`
- for the runtime clean-up: `s3:ListBucket` on the code bucket and `s3:GetObject`, `s3:PutObject`,
  `s3:DeleteObject` on `runtime/*`
- for queues (only when `arco.php` has `queue()`; see [Permissions for queues](#permissions-for-queues))
- for storage and tables (only when `arco.php` has `storage()`, `cache()` or `failedJobsTable()`; see
  [Permissions for storage and tables](#permissions-for-storage-and-tables))

For your own apps, scope the SSM resource to your parameters and add Secrets Manager read access
if you use `arco-secret:` references. If a permission is missing, Arco reports which operation
AWS denied. Without `lambda:ListTags` or `events:ListTagsForResource` the ownership check cannot read
the tags: the deploy stops and names the permission (`--force` skips the check), and `arco remove` deletes
nothing. The deploy keeps the live console version plus the 4 newest and deletes older ones on a best-effort
basis: without `lambda:ListVersionsByFunction` or `lambda:DeleteFunction` that step becomes a warning.

People who only run `arco command`, `arco tinker` or `arco logs` need `lambda:InvokeFunction`,
`lambda:GetFunctionConfiguration` and `lambda:ListTags` on the console function (`command` and `tinker`
first check that it belongs to the app and environment, and refuse to run anything otherwise) (`arco logs` needs the `logs:` actions above). The function's execution role is separate: for the managed role Arco
adds `logs:CreateLogGroup`, `logs:CreateLogStream` and `logs:PutLogEvents` on the web, console and queue
worker log groups, the SQS actions the app and workers need on the queues in `arco.php` (`ArcoQueues`), read
and write access to the buckets and tables in `arco.php` (`ArcoStorage`, `ArcoStorageList`, `ArcoTables`), `s3:GetObject` on `runtime/*` of the code bucket (the console function only reads it when it
downloads the binary, but the role keeps it: a console version that downloads can be live after a
rollback), and read access to the
secrets you reference.

### Permissions for queues

With `queue()`, the deploy profile also needs:

- `cloudformation:CreateStack`, `UpdateStack`, `DeleteStack`, `DescribeStacks`, `DescribeStackEvents`,
  `DescribeStackResources` on `arn:aws:cloudformation:*:*:stack/arco-*-resources/*` (the queue stack;
  `cloudformation:*Stack*` on `stack/arco-*`, as for custom domains, covers it), plus `cloudformation:GetTemplate`
  to report which buckets, tables and queues an update changed (without it every updated resource is reported as updated)
- `sqs:CreateQueue`, `sqs:GetQueueUrl`, `sqs:GetQueueAttributes`, `sqs:SetQueueAttributes`, `sqs:TagQueue`,
  `sqs:UntagQueue`, `sqs:ListQueueTags` (CloudFormation creates and updates the queues with the profile's
  credentials), `sqs:ReceiveMessage` (the `queue:dlq` sample) and `sqs:StartMessageMoveTask` (`queue:dlq
  --redrive`, which also needs `sqs:DeleteMessage` on the dead-letter queue and `sqs:SendMessage` on the
  queue), on your queues and their dead-letter queues (`arn:aws:sqs:*:*:<app>-*`) and any `existing` queue
- `sqs:DeleteQueue` on the same queues, used only by CloudFormation when the deploy that creates a queue
  fails and rolls back (the new queue is deleted; queues that survived a deploy are always kept). Without it
  that rollback cannot finish and the stack needs fixing by hand
- `lambda:CreateEventSourceMapping`, `lambda:UpdateEventSourceMapping`, `lambda:DeleteEventSourceMapping`,
  `lambda:ListEventSourceMappings`, `lambda:GetEventSourceMapping` (resource `*`; `CreateEventSourceMapping`
  can be limited with the `lambda:FunctionArn` condition)
- on the workers (`arn:aws:lambda:*:*:function:*-queue-*` and `...:*-queue-*:*`) the console function's
  actions except `lambda:PutFunctionEventInvokeConfig` and `lambda:InvokeFunction`: `lambda:CreateFunction`,
  `lambda:UpdateFunctionCode`, `lambda:UpdateFunctionConfiguration`, `lambda:GetFunctionConfiguration`,
  `lambda:PublishVersion`, `lambda:CreateAlias`, `lambda:UpdateAlias`, `lambda:GetAlias`,
  `lambda:TagResource`, `lambda:ListVersionsByFunction`, `lambda:DeleteFunction`
- `tag:GetResources`, to find the workers of queues removed from `arco.php` (without it the deploy warns and
  leaves them)

Without queues in `arco.php` a deploy reads the queue stack only to clean up after removed queues, and a
profile without CloudFormation access is not asked for any of this. The execution role's statements (and
what to add to your own `role()`) are in [Queue permissions](configuration.md#queue-permissions).

### Permissions for storage and tables

With `storage()`, `cache()` or `failedJobsTable()`, the deploy profile also needs:

- the CloudFormation actions of [Permissions for queues](#permissions-for-queues) on
  `stack/arco-*-resources/*` (buckets and tables live in the same resources stack)
- on the buckets Arco creates, by their bucket ARN only (`arn:aws:s3:::<app>-*`, without `/*`, so none of
  these reaches the files): `s3:Get*` and `s3:Put*` (CloudFormation creates, reads back and updates each
  bucket setting with the profile's credentials: tags, encryption, Block Public Access, lifecycle, CORS,
  policy), `s3:CreateBucket`, `s3:DeleteBucketPolicy` (removing the last public prefix), and `s3:DeleteBucket`,
  used only when the deploy that creates a bucket fails and rolls back
- `s3:ListBucket` on the same buckets: the deploy checks a bucket name before creating it (`HeadBucket`, which
  answers 403 without it, read as "name taken") and whether a kept bucket still has files
- on the tables Arco creates (`arn:aws:dynamodb:*:*:table/<app>-*`): `dynamodb:CreateTable`,
  `dynamodb:UpdateTable`, `dynamodb:Describe*` (CloudFormation reads the table, its TTL, backups and streaming
  settings; the deploy also checks a table before creating it and counts the items of a kept one, and stops
  naming `dynamodb:DescribeTable` without it), `dynamodb:UpdateTimeToLive`, `dynamodb:TagResource`,
  `dynamodb:UntagResource`, `dynamodb:ListTagsOfResource`, and `dynamodb:DeleteTable` (only for the rollback
  of a deploy that created the table)
- `tag:GetResources`, for the retained buckets in `arco storage:list`
- `sts:GetCallerIdentity` (already in the minimal list): bucket names hold the account id

`existing` buckets and tables need nothing from the deploy profile: only the execution role uses them. If
CloudFormation reports an access denial for another action on a bucket or table, the failed stack event
names it: add that action to the profile (on the same bucket or table ARNs) and deploy again. The execution
role's statements (and what to add to your own `role()`) are in [Storage permissions](configuration.md#storage-permissions) and
[Table permissions](configuration.md#table-permissions).

### Permissions for custom domains

With `domain()`, the deploy profile also needs:

- `acm:ListCertificates`, `acm:DescribeCertificate`, `acm:ListTagsForCertificate`,
  `acm:RequestCertificate`, `acm:AddTagsToCertificate`, `acm:DeleteCertificate` (region `us-east-1`)
- `cloudformation:*Stack*` on `arn:aws:cloudformation:*:*:stack/arco-*`
- `cloudfront:*Distribution*`, `cloudfront:*Function*`, `cloudfront:TagResource`,
  `cloudfront:UntagResource`, `cloudfront:ListTagsForResource`
- the Route 53 permissions below when the deploy account also hosts the DNS zones

The DNS profile (`dns('profile')` or `--dns-profile`) needs `route53:ListHostedZonesByName`,
`route53:ListResourceRecordSets`, `route53:ChangeResourceRecordSets` and `route53:GetChange`.
An expired SSO session of the DNS profile is reported with the `aws sso login` command to run.

`arco remove` needs `cloudformation:DescribeStacks` (covered by `cloudformation:*Stack*` above) to
find the domain stack of the environment. Without it, the custom domain cleanup (CloudFront stack,
DNS aliases, certificate) is skipped.

The zone check of `init` and `domain:add` calls only the profile you choose and needs
`route53:ListHostedZonesByName` and `sts:GetCallerIdentity` there.

## Language

Messages are available in English and Spanish. Choose with `--lang=es`, the `ARCO_LANG`
environment variable, or `~/.arco/config.php`; the default is English.
