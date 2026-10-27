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
  composer require league/flysystem-aws-s3-v3:^3.0 aws/aws-sdk-php multiapi/arco-laravel:^0.11
```

Versions are not compared here; `arco deploy` checks them in the built package. Without a `composer.lock`
nothing is checked.

### Custom domains in `init`

For every environment `init` asks whether to use a custom domain (default: no), then for the
domains, comma separated, and where their DNS is. It proposes the deploy account (the default),
each of your local AWS profiles, Cloudflare, and "outside AWS". Arco then checks only the place you picked:

```
Use a custom domain for production? (yes/no) [no]:
Domain(s), comma separated: shop.example.com, api.shop.example.com
Where is the DNS for example.com?
  [@deploy        ] Route 53 in the deploy account
  [dns-account   ] Route 53 in profile dns-account
  [@cloudflare    ] Cloudflare
  [@manual        ] Outside AWS — I'll create the records myself
> dns-account
Checking Route 53 of profile dns-account…
  ✓ shop.example.com: found zone example.com in profile dns-account (account 0123…)
  ✓ api.shop.example.com: found zone example.com in profile dns-account (account 0123…)
```

The result is written into the environment as `->domain([...])` plus `->dns('<profile>')` for a
profile other than the deploy profile, `->dns('cloudflare')` for Cloudflare, or `->dns(false)` for DNS outside AWS.
For Cloudflare, `init` checks the zones when it has an API token (`CLOUDFLARE_API_TOKEN` or
`--cloudflare-token`), and otherwise says they will be checked at deploy; the token is never written to
`arco.php` (see [Cloudflare](configuration.md#cloudflare) for the token's permissions). If the profile lacks a
zone or cannot be used (for example an expired SSO session; Arco prints the
`aws sso login --profile …` command), `init` says why and asks again, so you can pick another
profile or the same one after logging in. The deploy profile is `AWS_PROFILE` or the one remembered
for the environment; when neither is set, choosing the deploy account skips the check and deploy
looks for the zones.

Without questions, use flags: `--domain=<host>` (repeatable; applies to every `--env`),
`--dns-profile=<profile>` (checked, `init` fails if a zone is missing), `--dns=cloudflare` or `--dns=manual`. With only
`--domain`, the deploy account is used and checked when the deploy profile is known.

### Adding and removing domains later

```bash
vendor/bin/arco domain:add www.example.com shop.example.com --env=production
vendor/bin/arco domain:remove shop.example.com --env=production
```

`domain:add` validates the hosts, merges them with the environment's list, asks where the DNS is
the same way as `init` (`--dns-profile`, `--dns=cloudflare` and `--dns=manual` answer it without a question;
`--profile` names the deploy profile), and edits `arco.php`. An explicit `->dns(...)` is never
changed without a flag or your answer: `->dns(false)` stays as is, `->dns('cloudflare')` stays when the
Cloudflare zones hold the new domain, and `->dns('profile')` is
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
`Cold start: not available yet` and the deploy still succeeds. With `domain()` (0.11.4) only CloudFront may
call the endpoint, so the health check is a request signed with the deploy credentials (SigV4, service `lambda`),
which needs `lambda:InvokeWebFunctionEndpoint`; Lambda's 403 names that permission. With `->auth('iam')` and no
`domain()` the report is the only health signal, so Arco waits up to about 75 seconds for it before deciding; a missing or
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
DynamoDB tables (cache, failed jobs and `table()`, with their items) are kept; `remove` lists each one it kept.

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
vendor/bin/arco table:add state --ttl=expires_at --env=production   # writes ->table('state', ttl: 'expires_at')
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
missing. `->table('<name>')` creates a DynamoDB table `<app>-<env>-<name>` (`PK`/`SK` strings) for your own code:
read its name from `ARCO_TABLES` and use the AWS SDK. Every one of them can point at a bucket or table you already have with `existing:`. Removed buckets and tables stay in AWS with their data. Details:
[Storage](configuration.md#storage), [Cache and failed-jobs tables](configuration.md#cache-and-failed-jobs-tables)
and [Tables](configuration.md#tables).

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

**Scripting.** `info`, `info --resources`, `logs`, `queue:list`, `storage:list` and `profile` take `--json`, and so
do `deploy` and `remove` ([Deploy and remove with `--json`](#deploy-and-remove-with---json)). Every
JSON document (each line, for `logs --json`, `deploy --json` and `remove --json`) starts with `"schema": 1`; within schema 1, keys are only ever
added, never renamed or removed. A failure exits non-zero; with `--json`, `queue:list`, `storage:list` and
`profile` report it as `{"schema": 1, "error": "..."}`, and `info` adds an `error` key when AWS could not be
read. Commands exit with 0 on success and 1 on failure, except `arco command` (the exit code of the command it
ran) and a deploy stopped by a failing deploy command (that command's exit code). Parse `--json` and exit codes,
never the message texts: those change between releases and depend on the language.

`info --json` also has `dns` (where the DNS records go: `cloudflare`, `route53 (<profile>)`,
`route53 (deploy account)` or `manual`; `null` without domains) and `tables` (the `table()` tables, a list of
`{name, table, existing, ttl}`, `[]` without any), both added in 0.10. 0.11 added `permissions_boundary`, and 0.11.4
adds `endpoint_auth` (`IamAuth` or `ApplicationManaged`: the endpoint's effective auth, `IamAuth` with `domain()`), last.

**Resource relations.** In `info --resources --json`, each resource has `relations`: a list of
`{"type": ..., "target": ...}` from that resource to another, `[]` when there is none or Arco cannot tell.
They are worked out from `arco.php` and the listing itself, with no extra AWS call:

| `type` | On | `target` |
|---|---|---|
| `worker_of` | a queue's worker function (`lambda:function`) | the queue's ARN |
| `dlq_of` | a dead-letter queue (`sqs`) | the ARN of the queue it serves |
| `invokes` | the scheduler rule (`events:rule`) | the console function's ARN, when it is listed |
| `serves` | the CloudFront distribution | each custom domain |
| `validates` | an ACM certificate | each custom domain |

`validates` is set on the certificate of `certificate(arn)`, or, without one, only when exactly one certificate
is listed: a certificate kept from an earlier set of domains makes two, and then neither gets it. A queue
Arco manages that is missing from the listing gives no `worker_of`/`dlq_of`; an `existing` queue is named by
its configured ARN when that ARN names the region and the account (otherwise it gets no relation).

```json
{"type":"lambda:function","name":"shop-production-queue-emails","arn":"arn:aws:lambda:us-east-1:111111111111:function:shop-production-queue-emails","retained":false,"relations":[{"type":"worker_of","target":"arn:aws:sqs:us-east-1:111111111111:shop-production-emails"}]}
```

### Deploy and remove with `--json`

`arco deploy --json` and `arco remove --json` print [NDJSON](https://github.com/ndjson/ndjson-spec) on stdout,
one JSON object per line, for CI systems and dashboards that follow a deploy as it runs:

```bash
vendor/bin/arco deploy --env=production --yes --json > deploy.ndjson
```

```
{"schema":1,"type":"step","key":"deploy.target","status":"note","at":"2026-10-21T14:03:05Z","params":{"app":"shop","environment":"production","account":"222222222222","profile":"acme-prod","region":"us-east-1"},"message":"..."}
{"schema":1,"type":"step","key":"package.writing","status":"started","at":"2026-10-21T14:03:06Z","params":{},"message":"Writing the zip (hashing and compressing every file)"}
{"schema":1,"type":"progress","key":"build.zip","percent":37,"at":"2026-10-21T14:03:07Z"}
{"schema":1,"type":"progress","key":"build.zip","percent":100,"at":"2026-10-21T14:03:09Z"}
{"schema":1,"type":"step","key":"package.writing","status":"done","at":"2026-10-21T14:03:09Z","params":{},"elapsed_ms":2750,"message":"..."}
{"schema":1,"type":"step","key":"deploy.prune_versions_failed","status":"warning","at":"2026-10-21T14:03:40Z","params":{"function":"shop-production-console","error":"AccessDenied"},"message":"..."}
{"schema":1,"type":"result","outcome":"deployed","app":"shop","environment":"production","revision":"rev-2","url":"https://abc.lambda-url.us-east-1.on.aws","domains":["shop.example.com"],"rolled_back":false,"cold_start_ms":800,"error":null}
```

Every line has `schema` (`1`) and `type`, one of:

- **`step`**: what the human output prints as a line. Keys, in this order: `key` (the step, such as
  `package.writing` or `deploy.target`), `status`, `at` (UTC time, RFC 3339 to the second, such as
  `2026-10-21T14:03:05Z`), `params` (an object, `{}` when empty: the step's values as they are, codes not
  translated), `elapsed_ms` (only on `done`) and `message` (the human text, in the CLI's language).
  `status` is one of:
  - `started`: a step began;
  - `done`: the running step finished, sent when the next step starts or the run succeeds, with `elapsed_ms`;
    a failing run leaves its last step without `done`;
  - `warning`: a warning, with its own key (the step that runs stays open);
  - `note`: an informational line, such as the target (`deploy.target`), where the DNS records go
    (`deploy.dns_target`, `deploy.dns_cloudflare`), an endpoint override, or what `remove` lists and keeps
    (`remove.resources`, `remove.resource_kept`).
- **`progress`**: `key`, `percent` (0 to 100) and `at`. The keys are `build.zip` (writing the package zip) and
  `binary.download` (downloading the PHP CLI binary of the console function); other keys may be added. Lines
  of one key come at most once a second, except the last one at `100`, which always comes and may follow the
  previous line by less than a second. The upload to S3 has no progress line.
- **`result`**: always the last line, exactly once: `outcome`, `app`, `environment`, `revision`, `url`, `domains`,
  `rolled_back`, `cold_start_ms` and `error`.
  - `app` is `null` when `arco.php` could not be loaded (`environment` is then the `--env` value).
  - `revision` and `url` are the deployed revision and the function URL. After a failed verification they are
    those of the revision this deploy created, which is no longer live when `rolled_back` is `true`.
  - `domains` lists the custom domains now serving the app; `cold_start_ms` is the verification request's
    cold start, or `null`.
  - `error` is `null` on success, otherwise `{"key": ..., "message": ...}`.
  - For `remove`, `revision`, `url` and `cold_start_ms` are always `null`, `domains` is `[]` and `rolled_back`
    is `false`.

`outcome` is one of:

| Outcome | Meaning |
|---|---|
| `deployed` | `deploy` finished: the new revision is live. |
| `removed` | `remove` finished. |
| `refused` | Arco declined **before changing anything in AWS**: invalid `arco.php`, a confirmation that needs `--yes`, no profile, the `account()` lock, expired credentials, an ownership or other guard, a missing or rejected Cloudflare token or zone, a `--package` zip it does not accept. Fix the cause and run again. For `remove`, anything before its first deletion. |
| `failed` | Something went wrong: a build error (composer or artisan), an AWS error, a Cloudflare outage or request error (`cloudflare.gave_up`, `cloudflare.request_failed`), a failed verification (`rolled_back` says whether the previous revision is live again), an unexpected error, and any error once AWS changes began, even a guard's. Check the AWS state before retrying. |

`error.key` names the error: a message key such as `aws.account_mismatch`, `deploy.confirm_required`,
`remove.yes_required`, `cloudflare.token_missing` or `build.command_failed`; for an invalid `arco.php`, the
key of the first validation error. Two keys are not messages: `error.user` (an error Arco reports with no key
of its own) and `error.unexpected` (an unexpected exception; `message` is the exception's message).
`common.aborted` is used when the command stops without a more specific error.

- `--json` never asks: it implies `--no-interaction`, so whatever would ask fails with `refused` instead
  (pass `--yes` for the first deploy of an environment, for `remove` and on protected environments).
- stdout carries only the JSON lines. The output of a failing deploy command, its log tail, PHP warnings and an
  unexpected error's message go to stderr; other human text is not printed.
- Exit codes do not change: 0, 1, or a failing deploy command's own exit code. An invalid option or missing
  option value, which the CLI rejects before the command runs, exits 1 with **no** result line: treat a
  non-zero exit without a result line as such an error (stderr says which).
- Secret values Arco resolved (`arco-ssm:`, `arco-secret:`), the Cloudflare token, `AWS_SECRET_ACCESS_KEY` and
  `AWS_SESSION_TOKEN` are replaced with `[redacted]`: a value of 8 characters or more wherever it appears in a
  line (and in what goes to stderr), a shorter one only where a whole string value (a param, a message, the
  error message) is exactly that value, so a short secret cannot mangle domains, URLs or messages that merely
  contain it. A value that is exactly the app name, the environment name or one of the custom domains is kept as
  it is (so are the result's `app`, `environment` and `domains`).
- **CloudFront-only endpoint keys** (0.11.4, only with `domain()` or when `domain()` was just removed): the steps
  `deploy.oac_policy` (params `{function, distribution}`), `deploy.endpoint_protected` (`{function}`, the endpoint
  becomes `IamAuth`), `deploy.endpoint_unprotected` (`{function}`, the endpoint becomes public again) and
  `deploy.oac_revoked` (`{function}`, only when Arco's statement was removed), the warning
  `deploy.domain_stack_unreadable` (`{stack, function, error}`), the step `deploy.protection_check` (`{url}`, the
  health path through the distribution after the switch), the warning `deploy.endpoint_still_public` (`{function}`,
  a deploy that failed before the switch), the errors `deploy.protection_reverted` (`{function, url, answer}`) and `deploy.protection_revert_failed`
  (`{function, error, command}`), the note `deploy.endpoint_unprotected_emulator`
  (`{function}`, only behind an emulator endpoint: see [Try your app locally with Floci](local-floci.md)), the errors `deploy.policy_unreadable` and
  `deploy.access_arn_invalid`, and the verification reason `deploy.verify_signed_forbidden` (`{function}`).
- **Permissions boundary keys** (0.11, only when a boundary is configured or the role has one; see
  [Permissions boundary](configuration.md#permissions-boundary)):
  - `deploy.step_role` gains the param `boundary` (the configured policy ARN) next to `role`. Without a boundary
    its params stay exactly `{"role": ...}`. This is the only line that carries the boundary when the role is new
    or already has it.
  - `deploy.boundary_set` (a step, `started`/`done`), params `{role, boundary}`: an existing role got the boundary
    (it had none, or another one).
  - `deploy.boundary_kept` (status `note`), params `{role, boundary}`: `arco.php` no longer sets a boundary, and the
    role keeps the one it has (`boundary` is the role's current one). Arco never removes it.
  - `deploy.boundary_denied` (the `error.key` of a `failed` result), params `{role, boundary, action, error}`:
    `action` is the IAM write AWS denied (`iam:CreateRole`, `iam:PutRolePermissionsBoundary` or
    `iam:PutRolePolicy`) and `error` its AWS error code (`AccessDenied` or `AccessDeniedException`); see
    [Enforcing a permissions boundary](#enforcing-a-permissions-boundary).

  The boundary is not a secret and is never redacted.
- **What is API.** Line types, the keys of each line, `status` and `outcome` values, and each step's and
  error's `key` with its `params` are covered by the [1.x promise](upgrading.md#the-1x-promise): a key keeps its
  meaning and its params, which may only gain entries. `message` texts are not API, and neither are which steps
  a run prints and in what order (a new Arco version can add steps).

### Minimal IAM permissions

A deploy role (like the one of Arco's own end-to-end run, in a dedicated test account) needs:

- `lambda:*WebFunction*`, plus `lambda:ListTags` (the ownership check reads the tags of the web
  function, the console function and the workers) and `lambda:TagResource` (only to tag a resource an older
  Arco left untagged)
- `iam:TagRole` on `arn:aws:iam::*:role/arco-*`, only to tag a role an older Arco left untagged (`iam:GetRole`
  already returns the role's tags)
- `iam:GetRole` (it also returns the `arco:region` tag the region check reads), `iam:CreateRole`,
  `iam:PutRolePolicy`, `iam:DeleteRolePolicy`, `iam:DeleteRole`, `iam:PassRole` on `arn:aws:iam::*:role/arco-*`
- with a permissions boundary (`permissionsBoundary()`, `--permissions-boundary` or `ARCO_PERMISSIONS_BOUNDARY`):
  `iam:PutRolePermissionsBoundary` on `arn:aws:iam::*:role/arco-*`. Grant it together with `iam:CreateRole`
  whenever a boundary is used, as AWS's own permissions-boundary examples do: Arco calls it on an existing role, and
  granting the pair means the profile works whether the role exists or not (see
  [Enforcing a permissions boundary](#enforcing-a-permissions-boundary) for the conditions an account owner adds,
  and for the Lambda scoping a profile handed to someone else needs)
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
- for storage and tables (only when `arco.php` has `storage()`, `cache()`, `failedJobsTable()` or `table()`; see
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
and write access to the buckets and tables in `arco.php` (`ArcoStorage`, `ArcoStorageList`, `ArcoTables`, `ArcoAppTables`), `s3:GetObject` on `runtime/*` of the code bucket (the console function only reads it when it
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

With `storage()`, `cache()`, `failedJobsTable()` or `table()`, the deploy profile also needs:

- the CloudFormation actions of [Permissions for queues](#permissions-for-queues) on
  `stack/arco-*-resources/*` (buckets and tables live in the same resources stack)
- on the buckets Arco creates (`arn:aws:s3:::<app>-*`): `s3:Get*` and `s3:Put*` (CloudFormation creates,
  reads back and updates each bucket setting with the profile's credentials: tags, encryption, Block Public
  Access, lifecycle, CORS, policy), `s3:CreateBucket`, `s3:DeleteBucketPolicy` (removing the last public
  prefix), and `s3:DeleteBucket`, used only when the deploy that creates a bucket fails and rolls back. In IAM
  `*` also matches `/`, so this resource covers the buckets' files too (`arn:aws:s3:::<app>-files-…/key`) and
  `s3:Get*`/`s3:Put*` let the profile read and write them. The deploy never touches files in these buckets, so
  you can close that with a deny on the object ARNs:
  `{"Effect": "Deny", "Action": ["s3:GetObject*", "s3:PutObject*", "s3:DeleteObject*"], "Resource": "arn:aws:s3:::<app>-*/*"}`
  (the deploy uploads only to the code bucket `arco-<account>-<region>`; skip the deny for an app named `arco`, whose `<app>-*/*` would match that bucket)
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
role's statements (and what to add to your own `role()`) are in [Storage permissions](configuration.md#storage-permissions),
[Table permissions](configuration.md#table-permissions) and [Permissions of `table()` tables](configuration.md#permissions-of-table-tables).

### Permissions for custom domains

With `domain()`, the deploy profile also needs:

- `acm:ListCertificates`, `acm:DescribeCertificate`, `acm:ListTagsForCertificate`,
  `acm:RequestCertificate`, `acm:AddTagsToCertificate`, `acm:DeleteCertificate` (region `us-east-1`)
- `cloudformation:*Stack*` on `arn:aws:cloudformation:*:*:stack/arco-*`
- `cloudfront:*Distribution*`, `cloudfront:*Function*`, `cloudfront:TagResource`,
  `cloudfront:UntagResource`, `cloudfront:ListTagsForResource`
- `cloudfront:CreateOriginAccessControl`, `cloudfront:GetOriginAccessControl`,
  `cloudfront:UpdateOriginAccessControl`, `cloudfront:DeleteOriginAccessControl` (0.11.4; CloudFormation calls them
  with the deploy credentials, and `cloudfront:*Distribution*` does not match them)
- `lambda:GetResourcePolicy`, `lambda:PutResourcePolicy` on `arn:aws:lambda:*:*:web-function/<app>-*` (0.11.4:
  Arco lets only the environment's CloudFront invoke the endpoint), and `lambda:DeleteResourcePolicy` (only when you
  remove `domain()` and Arco's statement was the only one). `lambda:*WebFunction*` does not match them
- `lambda:InvokeWebFunctionEndpoint` (0.11.4: the post-deploy `/up` check is a signed request, since only CloudFront
  and signed callers may invoke the endpoint). The minimal permissions' `lambda:*WebFunction*` already grants it; a
  scoped profile needs it on `arn:aws:lambda:*:*:web-function/<app>-*/endpoint/*`
- the Route 53 permissions below when the deploy account also hosts the DNS zones

The DNS profile (`dns('profile')` or `--dns-profile`) needs `route53:ListHostedZonesByName`,
`route53:ListResourceRecordSets`, `route53:ChangeResourceRecordSets` and `route53:GetChange`.
An expired SSO session of the DNS profile is reported with the `aws sso login` command to run.

With `dns('cloudflare')` no Route 53 permission is used. The Cloudflare API token (`CLOUDFLARE_API_TOKEN` or
`--cloudflare-token`) needs **Zone → DNS → Edit** and **Zone → Zone → Read** on the zones of your domains;
create it in the Cloudflare dashboard (Arco never creates or stores tokens). In CI, pass it as a secret
environment variable.

`arco remove` needs `cloudformation:DescribeStacks` (covered by `cloudformation:*Stack*` above) to
find the domain stack of the environment. Without it, the custom domain cleanup (CloudFront stack,
DNS aliases, certificate) is skipped.

The zone check of `init` and `domain:add` calls only the profile you choose and needs
`route53:ListHostedZonesByName` and `sts:GetCallerIdentity` there.

### Enforcing a permissions boundary

This part is for account owners who hand a deploy profile to someone else: a CI, a team, or a service such as
Arco Cloud. The deploy permissions above are admin-equivalent in two ways:

- They include `iam:CreateRole`, `iam:PutRolePolicy` and `iam:PassRole` on `role/arco-*`, plus creating and
  invoking Lambda functions. Whoever holds the profile can create an `arco-*` role with any inline policy and run
  code under it.
- Their Lambda resources are wide. `lambda:*WebFunction*` has no resource, and `function:*-console` and
  `function:*-queue-*` match any function whose name ends in `-console` or contains `-queue-`. With
  `lambda:UpdateFunctionCode` (or a handler or layer change through `lambda:UpdateFunctionConfiguration`) on a
  function that is not Arco's, the profile runs its own code under that function's role, which can be an admin
  role, and needs no `iam:PassRole` for it.

A [permissions boundary](configuration.md#permissions-boundary) with the account guard below closes the first
gap, and [scoping the Lambda writes to the app's functions](#lambda-writes-only-on-the-apps-functions) closes the
second. You need both. The account owner creates a boundary policy (Arco never creates IAM policies), and the
deploy profile may then write `arco-*` roles **only** when they carry exactly that boundary. Whatever inline
policy the profile writes, the role can do no more than the boundary allows.

The ARNs in this section and in the [recommended boundary policy](#a-recommended-boundary-policy) use the `aws`
partition. In AWS China or AWS GovCloud (US), change `arn:aws:` to `arn:aws-cn:` or `arn:aws-us-gov:` in every
ARN.

#### The account guard

Replace the unconditioned `iam:CreateRole` and `iam:PutRolePolicy` of the minimal permissions with these
statements in the deploy profile's policy (`<account>` is the account id, `<boundary arn>` the boundary policy,
exactly as the deploy passes it):

```json
[
  {
    "Sid": "ArcoRoleWritesOnlyWithTheBoundary",
    "Effect": "Allow",
    "Action": ["iam:CreateRole", "iam:PutRolePolicy", "iam:PutRolePermissionsBoundary"],
    "Resource": "arn:aws:iam::<account>:role/arco-*",
    "Condition": {"StringEquals": {"iam:PermissionsBoundary": "<boundary arn>"}}
  },
  {
    "Sid": "ArcoNeverRemoveTheBoundary",
    "Effect": "Deny",
    "Action": "iam:DeleteRolePermissionsBoundary",
    "Resource": "arn:aws:iam::<account>:role/arco-*"
  },
  {
    "Sid": "ArcoBoundaryPolicyIsFixed",
    "Effect": "Deny",
    "Action": ["iam:CreatePolicyVersion", "iam:SetDefaultPolicyVersion", "iam:DeletePolicy"],
    "Resource": "<boundary arn>"
  },
  {
    "Sid": "ArcoPassOnlyArcoRoles",
    "Effect": "Allow",
    "Action": "iam:PassRole",
    "Resource": "arn:aws:iam::<account>:role/arco-*"
  }
]
```

- For `iam:PutRolePolicy` and `iam:PutRolePermissionsBoundary`, `iam:PermissionsBoundary` is the boundary the role
  has, or gets, after the call. Arco puts the boundary on an existing role **before** it writes the role's policy,
  so the condition already holds when `PutRolePolicy` runs.
- `iam:CreateRole` and `iam:PutRolePermissionsBoundary` are granted together, as in AWS's own permissions-boundary
  examples: a new role is created with the boundary, an existing one gets it.
- `iam:GetRole`, `iam:TagRole`, `iam:DeleteRolePolicy` and `iam:DeleteRole` stay as in the minimal permissions:
  `arco remove` deletes a bounded role without removing the boundary first.
- Do not grant `iam:AttachRolePolicy` or `iam:UpdateAssumeRolePolicy`: Arco never uses them.
- Optionally, `ArcoPassOnlyArcoRoles` can also require that the role is passed to Lambda only:
  `"Condition": {"StringEquals": {"iam:PassedToService": "lambda.amazonaws.com"}}`. Arco passes the execution role
  only to Lambda: as `executionRoleArn` of `CreateWebFunction` and `CreateWebFunctionRevision` (Lambda Web
  Functions), and as `Role` of `CreateFunction` and `UpdateFunctionConfiguration` (the console and the workers).
  It passes no role to CloudFormation (no `RoleARN`) or to EventBridge, and the role it creates trusts only
  `lambda.amazonaws.com`. If a deploy with the condition fails with a `iam:PassRole` denial, check in CloudTrail
  which service the call named and adjust the value.
- **`lambda:PutResourcePolicy` lets the profile grant others access to the app's Web Function.** Arco only writes
  its `ArcoCloudFront` statement, but the action itself accepts any policy, so the profile holder could let another
  account invoke an `auth('iam')` endpoint or manage the function. This stays within the app's own functions. If
  that matters to you, condition the action (for example deny it outside your deploy pipeline's role) or watch
  the policy with IAM Access Analyzer, which reports Web Function policies that grant access outside your account.
- **The trust policy of a new role is not constrained.** No IAM condition key limits the
  `AssumeRolePolicyDocument` of `iam:CreateRole`, so the profile can create a bounded `arco-*` role that another
  AWS account can assume. That account then has lasting access to everything the boundary allows, and with one
  boundary shared by several apps (`<app>` replaced by `*`) that is every app's data. Arco itself always writes a
  trust policy for `lambda.amazonaws.com` only. To detect other trust policies, use
  [IAM Access Analyzer](https://docs.aws.amazon.com/IAM/latest/UserGuide/what-is-access-analyzer.html) (an
  external access analyzer reports a role that a principal outside your account or organization can assume); to
  prevent them, use an SCP or a resource control policy in AWS Organizations, for example one that denies
  `sts:AssumeRole` on `role/arco-*` to principals outside your organization. The `iam:PassedToService` condition
  above helps only partly: it limits where the profile passes the role, not who can assume it.

#### Every `arco-*` role must carry the boundary

No condition key on `iam:PassRole` can require the passed role to carry a boundary. `ArcoPassOnlyArcoRoles`
therefore reaches every role named `arco-*`, including roles that an Arco 0.10 or older deploy created without a
boundary, and any `arco-*` role made by another tool or person. One unbounded `arco-*` role with wide permissions is
enough to bypass the guard: the profile creates a function with it. Before the guard is relied on, that is before
the profile is handed out:

1. Put the boundary on every existing `arco-*` role. Deploy each environment once with the boundary configured
   (Arco sets it, with the step `deploy.boundary_set`), or set it yourself:
   `aws iam put-role-permissions-boundary --role-name <role> --permissions-boundary <boundary arn>`. A role that
   nothing deploys any more can be deleted instead.
2. Check that none is left without it. `ListRoles` does not return the boundary, so list the roles and read each
   one with `GetRole`:

   ```bash
   for role in $(aws iam list-roles --query "Roles[?starts_with(RoleName, 'arco-')].RoleName" --output text); do
     printf '%s\t%s\n' "$role" "$(aws iam get-role --role-name "$role" --query 'Role.PermissionsBoundary.PermissionsBoundaryArn' --output text)"
   done
   ```

   Every line must end in `<boundary arn>`. `None` is a role without a boundary.
3. Make sure that no principal outside the guard can create or change `arco-*` roles (another deploy profile, a
   person with `IAMFullAccess`, ...): give it the same guard, or deny it with an SCP. Run the check again after
   any change of that kind.

#### Lambda writes only on the app's functions

For the guard to hold, every Lambda write the deploy profile has must be scoped to this app's functions,
`<app>-*`: the Web Function `<app>-<env>` with its revisions and its endpoint, the console `<app>-<env>-console`
and the workers `<app>-<env>-queue-<queue>`. In a guarded profile, replace the Lambda entries of the minimal
permissions (the queue ones included) with these statements. Replace `<account>` and `<app>`; for a profile that
deploys several apps, list one resource per app.

```json
[
  {
    "Sid": "ArcoWebFunctionOfTheApp",
    "Effect": "Allow",
    "Action": [
      "lambda:CreateWebFunction", "lambda:CreateWebFunctionRevision", "lambda:UpdateWebFunctionEndpoint",
      "lambda:DeleteWebFunction", "lambda:GetWebFunction", "lambda:GetWebFunctionEndpoint",
      "lambda:GetWebFunctionRevision", "lambda:ListTags", "lambda:TagResource",
      "lambda:GetResourcePolicy", "lambda:PutResourcePolicy", "lambda:DeleteResourcePolicy",
      "lambda:InvokeWebFunctionEndpoint"
    ],
    "Resource": "arn:aws:lambda:*:<account>:web-function/<app>-*"
  },
  {
    "Sid": "ArcoFunctionsOfTheApp",
    "Effect": "Allow",
    "Action": [
      "lambda:CreateFunction", "lambda:UpdateFunctionCode", "lambda:UpdateFunctionConfiguration",
      "lambda:GetFunctionConfiguration", "lambda:PublishVersion", "lambda:CreateAlias", "lambda:UpdateAlias",
      "lambda:GetAlias", "lambda:PutFunctionEventInvokeConfig", "lambda:TagResource", "lambda:ListTags",
      "lambda:DeleteFunction", "lambda:InvokeFunction", "lambda:AddPermission", "lambda:RemovePermission",
      "lambda:GetPolicy"
    ],
    "Resource": "arn:aws:lambda:*:<account>:function:<app>-*"
  },
  {
    "Sid": "ArcoWorkerMappingsOfTheApp",
    "Effect": "Allow",
    "Action": ["lambda:CreateEventSourceMapping", "lambda:UpdateEventSourceMapping", "lambda:DeleteEventSourceMapping"],
    "Resource": "*",
    "Condition": {"ArnLike": {"lambda:FunctionArn": "arn:aws:lambda:*:<account>:function:<app>-*"}}
  },
  {
    "Sid": "ArcoLambdaReads",
    "Effect": "Allow",
    "Action": ["lambda:ListEventSourceMappings", "lambda:GetEventSourceMapping", "lambda:ListVersionsByFunction"],
    "Resource": "*"
  }
]
```

- **The function ARNs.** The console and the workers are classic Lambda functions,
  `arn:aws:lambda:<region>:<account>:function:<name>`; their versions and the `live` alias add `:<qualifier>`,
  which `<app>-*` covers (in IAM `*` also matches `:`). The Web Function is another resource type: Arco calls the
  Lambda Web Functions API by function name and reads the function's ARN from AWS (`functionArn`), and the AWS
  SDK's model spells it `arn:aws:lambda:<region>:<account>:web-function/<name>`. `function:<app>-*` does not
  match it. Before you rely on the guard, check the resource types of the Web Function actions in the
  [Service Authorization Reference for AWS Lambda](https://docs.aws.amazon.com/service-authorization/latest/reference/list_awslambda.html)
  and the ARN of your web function (`arco info --resources --json` lists it). If either spells it differently,
  or lists revision or endpoint resources of their own, use that spelling in `ArcoWebFunctionOfTheApp`.
- **What cannot be scoped.** An event source mapping has no function in its ARN (it is a UUID), and
  `lambda:CreateEventSourceMapping` takes no resource, so `ArcoWorkerMappingsOfTheApp` uses `"Resource": "*"` with
  the condition `lambda:FunctionArn`: the function the mapping invokes. `lambda:ListEventSourceMappings` and
  `lambda:GetEventSourceMapping` cannot be scoped; they only read. If the Service Authorization Reference lists a
  Web Function write with no resource type, that action cannot be scoped either: the profile then has it on every
  web function of the account, and the guard holds only in an account where no web function other than the app's
  exists.
- **`lambda:ListVersionsByFunction` stays account-wide** as a read. The runtime clean-up reads every version of
  every function tagged `arco:app` in the region (the code bucket is shared by all of the account's Arco apps).
  It changes nothing, but it returns each version's configuration, environment variables included. If that
  matters more to you, scope it to `function:<app>-*` too: the clean-up then deletes nothing and the deploy warns
  (`deploy.prune_runtime_failed`).
- **`<app>-*` is a prefix.** It also matches the functions of an app whose name starts with `<app>-` (`shop-*`
  matches `shop-admin-production`). Give apps names where none is another's name followed by `-`, or scope each
  environment: `<app>-<env>` and `<app>-<env>-*`.
- `lambda:InvokeFunction` on `function:<app>-*` is what `arco command`, `arco tinker` and the deploy commands use
  on the console.

**What the profile must not have, or the guard is bypassed.** The guard only works when nothing else in the
profile's policies (inline, managed, group, or an AWS-managed policy such as `AdministratorAccess`,
`IAMFullAccess` or `AWSLambda_FullAccess`) allows what it restricts:

- `iam:CreateRole`, `iam:PutRolePolicy` or `iam:PutRolePermissionsBoundary` on any resource without the
  `iam:PermissionsBoundary` condition, including through `iam:*` or `iam:*Role*`, and on roles not named `arco-*`
  (an unbounded role of another name, passed to Lambda, is the same gap);
- `iam:DeleteRolePermissionsBoundary` (denied above, but check for an explicit allow you rely on elsewhere);
- `iam:PassRole` on roles other than `role/arco-*`: an existing powerful role passed to a function the profile
  creates runs code with that role's permissions;
- Lambda write or invoke actions on functions other than the app's (outside `function:<app>-*` and
  `web-function/<app>-*`): `lambda:UpdateFunctionCode`, `lambda:UpdateFunctionConfiguration`,
  `lambda:CreateFunction`, the `*WebFunction*` writes, `lambda:CreateEventSourceMapping` without the
  `lambda:FunctionArn` condition, `lambda:InvokeFunction`, `lambda:AddPermission`, including through `lambda:*`
  or a resource of `*`. `lambda:UpdateFunctionCode` on someone else's function runs the profile's code under that
  function's role, with no `iam:PassRole`;
- CloudFormation on stacks other than `arco-*` (`arn:aws:cloudformation:*:<account>:stack/arco-*`). Arco's
  stacks run with the profile's own credentials (Arco passes no `RoleARN`), so the guard and the Lambda scoping
  apply to everything they create: an `AWS::IAM::Role` in a stack is a `CreateRole` by the profile. An update of
  another stack that has a service role runs with that role instead, outside the guard. A new stack with a
  `RoleARN` needs `iam:PassRole`, which the guard limits to `arco-*` (and the `iam:PassedToService` condition, if
  used, to Lambda);
- `iam:AttachRolePolicy`, `iam:DetachRolePolicy`, `iam:UpdateAssumeRolePolicy` and `iam:UpdateRole`;
- writing the boundary policy itself: `iam:CreatePolicyVersion`, `iam:SetDefaultPolicyVersion` and
  `iam:DeletePolicy` on it (denied above), and `iam:CreatePolicy` with the same name after a delete;
- creating or changing other principals: `iam:CreateUser`, `iam:CreateAccessKey`, `iam:CreateLoginProfile`,
  `iam:UpdateLoginProfile`, `iam:PutUserPolicy`, `iam:AttachUserPolicy`, `iam:CreateGroup`, `iam:PutGroupPolicy`,
  `iam:AttachGroupPolicy`, `iam:AddUserToGroup`, and `iam:CreateInstanceProfile`/`iam:AddRoleToInstanceProfile`;
- changing its own permissions: any `iam:Put*Policy`, `iam:Attach*Policy` or `iam:CreatePolicyVersion` on the
  profile's own user, group, role or the managed policies attached to them;
- `sts:AssumeRole` on roles more privileged than the boundary.

#### When AWS denies the role write

With a boundary configured, an `AccessDenied` (or `AccessDeniedException`) on `iam:CreateRole`,
`iam:PutRolePermissionsBoundary` or `iam:PutRolePolicy` stops the deploy with `deploy.boundary_denied`, params
`{role, boundary, action, error}` (`action` is the denied IAM action, `error` the AWS error code; the AWS message
is not included). No function, revision, console or worker was changed and traffic did not move, but earlier
steps may already have run: the resources stack (queues, buckets and tables), the code bucket and the code upload.
It has one of two causes:

1. **The account guard.** The account requires exactly a specific boundary (condition `iam:PermissionsBoundary`)
   and the configured one is not it. Check that the ARN in `arco.php`, `--permissions-boundary` or
   `ARCO_PERMISSIONS_BOUNDARY` is exactly the one the account owner gave you (`arco info` shows the effective value
   and its source). A deploy with no boundary at all against a guarded account fails at IAM with the plain AWS
   error, since Arco has no boundary to name.
2. **A missing deploy permission.** The profile lacks `action` on `role/arco-*`, most often
   `iam:PutRolePermissionsBoundary`, which only a boundary needs. Allow it, together with `iam:CreateRole`.

Without a boundary configured, the same denial is reported as AWS's own error, as before 0.11.

**A partial change is possible.** When `iam:PutRolePermissionsBoundary` succeeds and the following
`iam:PutRolePolicy` is denied, the role keeps the new boundary: it was already the boundary the deploy wanted, and
Arco never removes one. No function changed, and the app keeps running with its previous inline policy, now capped
by the new boundary. A re-run behaves the same way (the boundary is already there, so it goes straight to
`PutRolePolicy`) until the cause is fixed.

#### A recommended boundary policy

The boundary must allow everything the execution role needs at runtime, or the app gets `AccessDenied` where it
worked before. The statements below are every statement Arco's managed policy can contain (the `Sid`s match the
role's inline policy `arco`), widened from one environment's resource names to the app's prefix so that one
boundary covers every environment of `<app>`. Replace `<account>` and `<app>`; for one boundary shared by several
apps, replace `<app>` with a common prefix or `*` (a wider ceiling). The inline policy still narrows each role to
its own environment. The ARNs use the `aws` partition: in AWS China or AWS GovCloud (US), change every `arn:aws:`
to `arn:aws-cn:` or `arn:aws-us-gov:`.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ArcoLogs",
      "Effect": "Allow",
      "Action": ["logs:CreateLogGroup", "logs:CreateLogStream", "logs:PutLogEvents"],
      "Resource": [
        "arn:aws:logs:*:<account>:log-group:/aws/lambda/web/<app>-*",
        "arn:aws:logs:*:<account>:log-group:/aws/lambda/<app>-*"
      ]
    },
    {
      "Sid": "ArcoQueues",
      "Effect": "Allow",
      "Action": ["sqs:SendMessage", "sqs:ReceiveMessage", "sqs:DeleteMessage", "sqs:ChangeMessageVisibility", "sqs:GetQueueAttributes", "sqs:GetQueueUrl"],
      "Resource": "arn:aws:sqs:*:<account>:<app>-*"
    },
    {
      "Sid": "ArcoStorage",
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject", "s3:GetObjectAcl", "s3:PutObjectAcl", "s3:AbortMultipartUpload"],
      "Resource": "arn:aws:s3:::<app>-*/*"
    },
    {
      "Sid": "ArcoStorageList",
      "Effect": "Allow",
      "Action": "s3:ListBucket",
      "Resource": "arn:aws:s3:::<app>-*"
    },
    {
      "Sid": "ArcoTables",
      "Effect": "Allow",
      "Action": [
        "dynamodb:GetItem", "dynamodb:PutItem", "dynamodb:UpdateItem", "dynamodb:DeleteItem", "dynamodb:Query", "dynamodb:Scan",
        "dynamodb:BatchGetItem", "dynamodb:BatchWriteItem", "dynamodb:ConditionCheckItem", "dynamodb:DescribeTable"
      ],
      "Resource": ["arn:aws:dynamodb:*:<account>:table/<app>-*", "arn:aws:dynamodb:*:<account>:table/<app>-*/index/*"]
    },
    {
      "Sid": "ArcoRuntimeBinary",
      "Effect": "Allow",
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::arco-<account>-*/runtime/*"
    },
    {
      "Sid": "ArcoParameters",
      "Effect": "Allow",
      "Action": "ssm:GetParameters",
      "Resource": "arn:aws:ssm:*:<account>:parameter/<app>/*"
    },
    {
      "Sid": "ArcoSecrets",
      "Effect": "Allow",
      "Action": "secretsmanager:GetSecretValue",
      "Resource": "arn:aws:secretsmanager:*:<account>:secret:<app>/*"
    },
    {
      "Sid": "ArcoSecretsBatch",
      "Effect": "Allow",
      "Action": "secretsmanager:BatchGetSecretValue",
      "Resource": "*"
    },
    {
      "Sid": "ArcoDecrypt",
      "Effect": "Allow",
      "Action": "kms:Decrypt",
      "Resource": "*",
      "Condition": {"StringLike": {"kms:ViaService": ["ssm.*.amazonaws.com", "secretsmanager.*.amazonaws.com"]}}
    },
    {
      "Sid": "AppPermissions",
      "Effect": "Allow",
      "Action": ["<the actions of the app's permissions()>"],
      "Resource": ["<their resources>"]
    }
  ]
}
```

- **Logs**: the web function writes to `/aws/lambda/web/<app>-<env>` and `/aws/lambda/web/<app>-<env>.local`, the
  console to `/aws/lambda/<app>-<env>-console`, each worker to `/aws/lambda/<app>-<env>-queue-<queue>`. In IAM `*`
  also matches `:` and `/`, so the two patterns cover the groups and their streams.
- **Queues, buckets and tables**: `queue()`, `storage()`, `cache()`, `failedJobsTable()` and `table()` create
  `<app>-<env>-…` names (buckets `<app>-<env>-<storage>-<account>`). An `existing` queue, bucket or table has its own
  name: add its ARN (bucket: `arn:aws:s3:::<bucket>` and `arn:aws:s3:::<bucket>/*`) to the matching statement. The
  queue actions also cover the workers, which Lambda's event source mapping runs with the execution role. Dead-letter
  queues need nothing at runtime.
- **ArcoTables** merges the cache and failed-jobs statement (`ArcoTables` in the inline policy) with the `table()`
  one (`ArcoAppTables`, which adds `Scan`, `ConditionCheckItem` and the indexes).
- **ArcoRuntimeBinary**: the console function reads its FrankenPHP binary from the code bucket
  `arco-<account>-<region>`.
- **Secrets**: `arco secret:set` writes SSM parameters `/<app>/<env>/<name>`. An `arco-ssm:` reference elsewhere,
  and every `arco-secret:` (Secrets Manager) reference, have the names you gave them: change the `parameter/` and
  `secret:` resources to cover them (a Secrets Manager name gets a `-??????` suffix, so end the pattern with `*`).
  Without any reference, these three statements and `ArcoDecrypt` are never used.
- **No Lambda statement.** The execution role needs no `lambda:InvokeFunction`: the scheduler rule invokes the
  console through the function's resource policy, people and the deploy invoke it with their own credentials, and
  queue jobs reach the workers through SQS.
- **AppPermissions** is a placeholder. **The account owner must extend the boundary with whatever the app adds
  through `permissions()`** (SES, another bucket, ...): an action the boundary does not allow silently does nothing,
  however the inline policy grants it. A boundary allows only; a `Deny` in `permissions()` needs no counterpart.
- New Arco features may add statements to the managed policy; the changelog lists them, and the boundary then
  needs them too.

## Language

Messages are available in English and Spanish. Choose with `--lang=es`, the `ARCO_LANG`
environment variable, or `~/.arco/config.php`; the default is English.
