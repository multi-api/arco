# Arco for coding agents

This page is for a coding agent (Claude Code, Codex, Cursor, ...) working in a PHP or Laravel project that deploys
with Arco. It says how to drive the `arco` CLI safely without a human at the keyboard, what each command and
`arco.php` method does, and how to fix the errors you will meet. The human-facing docs have every detail; this page
links to them instead of repeating them:

- [Getting started](getting-started.md): install, `init`, profiles, secrets, deploys, CI, IAM permissions
- [Configuration reference](configuration.md): every `arco.php` method, its default and merge rules
- [Try your app locally with Floci](local-floci.md)
- [Upgrading](upgrading.md): breaking changes per version and the 1.x promise

In a user's project these files are in `vendor/multiapi/arco/docs/`. Read the one you need before changing an
unfamiliar setting: the reference is authoritative, this page is a map.

Working on Arco itself (the monorepo) instead? Read `AGENTS.md` at the root of the monorepo.

## Ground rules

Arco creates, changes and deletes real AWS resources that cost money and serve real users. Follow these rules
unless the user explicitly says otherwise for the case at hand.

1. **Ask before anything that touches AWS.** `deploy`, `remove`, `command`, `tinker`, `secret:set`,
   `queue:dlq --redrive`, `scheduler:pause`/`resume` and `scheduler:set`/`scheduler:remove --apply` change AWS or run
   code against real data. Run them only when the user asked for that action on that environment. Commands that only
   edit `arco.php` (`set`, `queue:add`, `storage:add`, ...) and read-only commands (`info`, `logs`, `queue:list`,
   `storage:list`, `profile`) are safe.
2. **Know the account.** Before the first AWS call of a session, find out which AWS profile the user wants
   (`vendor/bin/arco profile --json`, `arco.php`'s `profile()`/`account()`, or ask). Never guess a profile, and
   never fall back to `default`: Arco does not either.
3. **Never handle secret values.** Do not write secrets into `arco.php`, `variables()`, `.env` committed files,
   command arguments or chat. Store them with `arco secret:set` (the user types or pipes the value) and reference
   them as `arco-ssm:/<app>/<env>/<name>`. See [Secrets](#secrets).
4. **Never pass `--force` or `--force-dns` on your own.** `deploy --force` takes over a resource tagged for another
   app or environment; `--force-dns` replaces live DNS records. Both are for a human who has checked that the
   resource is theirs.
5. **Validate before deploying.** After editing `arco.php`, run `vendor/bin/arco info --env=<env> --json -n`: it
   loads and validates the file without changing anything (see [Validate without AWS](#validate-without-aws)).
6. **Parse `--json` and exit codes, never the message texts.** Messages change between releases and are
   translated (`--lang=es`, `ARCO_LANG=es`).
7. **Prefer the editing commands over hand edits** of `arco.php` for the settings they cover. They check the value
   with the same rules as validation, lint the result and restore the file if anything else changed. Hand edits
   are fine for the rest (`variables()`, `permissions()`, `runtime()`, `vpc()`, `deployCommands()`, `exclude()`).
8. **Removed data stays.** Removing a queue, storage or table from `arco.php` and `arco remove` never delete SQS
   queues, buckets, DynamoDB tables, SSM parameters or log groups. Do not delete them yourself; tell the user what
   was kept (the command output lists it).

## Mental model

- **One `arco.php`** at the project root returns `App::make('<app>')` with environments
  (`->environment('production', fn (Environment $env) => $env->...)`). Every setting resolves as Arco default <
  app level < environment level. `arco info --env=<env>` shows each resolved value and where it came from.
- **One environment = one Lambda Web Function** `<app>-<env>` in the app's single region, plus, when configured:
  a console function `<app>-<env>-console` (commands, tinker, migrations, scheduler), one worker function
  `<app>-<env>-queue-<name>` per queue, SQS queues, S3 buckets, DynamoDB tables, a CloudFront distribution for
  custom domains, a Lambda network connector for `vpc()`, and an execution role `arco-<app>-<env>`.
- **Ownership by tags.** Everything Arco creates carries `arco:app` and `arco:environment` tags. Before changing or
  deleting a resource Arco checks those tags and refuses (before any change) when they name another app or
  environment.
- **A deploy** builds a staging copy (`composer install --no-dev`, the preset's build commands), zips it, uploads it
  to the code bucket `arco-<account>-<region>`, updates the resources stack (queues, buckets, tables) first, creates
  a revision, runs deploy commands on the new console version, moves traffic, checks the health route (`/up` for
  Laravel, `/` for plain PHP) and rolls traffic back if it fails. Workers, scheduler and domains follow once the
  revision is verified.
- **Never packaged:** `.env` and `.env.*` (anywhere), `vendor/` (rebuilt), `node_modules/`, `.git/`, `.arco/`, editor
  and AI-assistant folders (`.claude`, `.cursor`, `.codex`, ...). The Laravel preset also excludes `storage/**` and
  `tests/**`.
- **`.arco/`** (git-ignored) holds the remembered profile per environment (`.arco/local.php`) and built zips
  (`.arco/build/`).

## Running Arco non-interactively

An agent's shell has no terminal, so Arco treats it as non-interactive (stdin is not a TTY). Pass `-n`
(`--no-interaction`) anyway, so the behaviour does not depend on how the shell is wired. Without a terminal, every
question either takes its default, is answered by a flag, or stops the command:

| Situation | Without a terminal | Flag that answers it |
|---|---|---|
| Several environments in `arco.php` and no `--env` | error `config.choose_environment` | `--env=<env>` (always pass it) |
| No AWS profile resolved | error `aws.no_profile_non_interactive` | `--profile=<name>`, `AWS_PROFILE`, `profile()` in `arco.php`, or `arco profile --env=<env> --set=<name>` |
| First deploy of an environment (function does not exist) | error `deploy.confirm_required` | `--yes` |
| `remove` (typed confirmation) | error `remove.yes_required` | `--yes` |
| `command`, `tinker`, `queue:dlq --redrive` on a protected environment (`production` by default, or `protected()`) | error `protected.needs_yes` | `--yes` |
| Offer to lock the environment to the account after the first deploy | skipped | add `->account('<id>')` by hand |
| `scheduler:set` / `scheduler:remove`: "apply now in AWS?" | only `arco.php` is edited | `--apply` (with `--env`) |
| `init` questions (console, migrations, scheduler, queues, ...) | only what the flags say; prints the `arco ...:add` lines for what it left out | `--console`, `--migrate`, `--scheduler=N`, `--queue=<name>`, ... |
| `domain:add`: where is the DNS? | error naming the flag to pass when the configured place lacks a zone | `--dns-profile=<p>`, `--dns=cloudflare`, `--dns=manual` |
| `secret:set` value | read from stdin | pipe the value in |
| `tinker` shell | not usable | `--execute="<code>"` |

`--yes` answers confirmations only; it never overrides a safety check (that is `--force`). `--json` on `deploy` and
`remove` implies `--no-interaction`, so a missing `--yes` makes the run end with `outcome: refused`.

Pass `--yes` only when the user asked for that exact action on that environment: it is the confirmation the user
would otherwise give.

## Output, JSON and exit codes

- **Exit codes:** `0` success, `1` failure. Two exceptions: `arco command` exits with the remote command's own exit
  code, and a deploy stopped by a failing deploy command exits with that command's code.
- **`--json`** exists on `info`, `info --resources`, `logs`, `queue:list`, `storage:list`, `profile`, `deploy` and
  `remove`. Every document (every line, for the NDJSON ones) starts with `"schema": 1`; within a schema keys are only
  added, never renamed or removed.
  - `info`, `queue:list`, `storage:list`, `profile` print one JSON document. On failure `queue:list`, `storage:list`
    and `profile` print `{"schema": 1, "error": "..."}`; `info` adds an `error` key when AWS could not be read.
  - `logs --json` prints raw events, one per line.
  - `deploy --json` and `remove --json` print NDJSON on stdout: `step` lines (`key`, `status`: `started`, `done`,
    `warning` or `note`, `params`, `message`), `progress` lines (`key`, `percent`), and exactly one final
    `result` line: `outcome` (`deployed`, `removed`, `refused`, `failed`), `app`, `environment`, `revision`, `url`,
    `domains`, `rolled_back`, `cold_start_ms` and `error` (`null` or `{"key", "message"}`). Human text, a failing
    deploy command's output and PHP warnings go to stderr.
  - `refused` means nothing changed in AWS: fix the cause and run again. `failed` means AWS may have changed: read
    `rolled_back` and check the state (`arco info`) before retrying.
  - A non-zero exit **without** a `result` line is a CLI usage error (unknown option, missing value); stderr says
    which.
  - Full format: [Deploy and remove with `--json`](getting-started.md#deploy-and-remove-with---json).
- Secret values Arco resolved, the Cloudflare token and AWS secret keys are replaced with `[redacted]` in `--json`
  output.

### Validate without AWS

`arco info --env=<env> --json -n` loads `arco.php` in a separate PHP process, validates it, and prints the resolved
settings. Without resolvable AWS credentials it still succeeds and simply has no live status (`url`, `revision` are
`null`); with credentials it also reads the deployed state. An invalid file exits 1 with the validation message
(`{"schema": 1, "error": "..."}`). Deprecations print a warning naming the replacement.

## Commands

Run every command as `vendor/bin/arco <command>` from the project root (where `arco.php` lives). Global options:
`-n` (no interaction), `--lang=en|es`, `-v`/`-vv`/`-vvv`, `-q`, `--no-ansi`.

Flags shared by many commands:

- `-e, --env=<env>`: the environment. Optional only when `arco.php` has exactly one environment.
- `-p, --profile=<profile>`: the AWS profile for this run. Credential order: `--profile` > credentials or
  `AWS_PROFILE` in the process environment > `profile()` in `arco.php` > `.arco/local.php` > (interactive only)
  a prompt. `default` is never used implicitly.
- `--app`: on the `arco.php`-editing commands, change the app level (all environments) instead of `--env`. Pass
  exactly one of `--env` and `--app`.

### Deploy, inspect, remove

| Command | What it does | Options |
|---|---|---|
| `deploy` | Build, upload, run deploy commands, deploy, verify (rollback on failure), then workers, scheduler, domains | `--env`, `--profile`, `-y/--yes` (create a new environment), `--json`, `--package=<zip>` (deploy a prebuilt zip), `--migrate` (add `migrate --force` to this deploy's deploy commands; Laravel only), `--no-deploy-commands` (not with `--migrate`), `--dns-profile=<p>`, `--cloudflare-token=<t>`, `--permissions-boundary=<arn>`, `--config-layer=<path>`, `--force` (dangerous, see ground rules), `--force-dns` (dangerous) |
| `package` | Build the zip only (`.arco/build/arco-<app>-<env>.zip`) | `--env`, `-o/--output=<path>`, `--config-layer=<path>` |
| `info` | Resolved settings with their source, URL, live revision; validation | `--env`, `--profile`, `--json`, `--resources` (every AWS resource tagged for the environment, each with `source` and `options`, plus `variables` and `config`; `tag:GetResources`), `--permissions-boundary=<arn>`, `--config-layer=<path>` |
| `remove` | Delete the web function, endpoint, console, workers, execution role, scheduler rule, network connector and the domain stack, DNS aliases and an Arco-requested certificate. Keeps queues, buckets, tables, SSM parameters, log groups and the code bucket | `--env`, `--profile`, `-y/--yes`, `--json`, `--dns-profile`, `--cloudflare-token`, `--config-layer=<path>`, `--force` (deprecated spelling of `--yes`) |
| `logs` | Web, console and worker logs merged | `--env`, `--profile`, `-f/--follow`, `--since=10m` (`30s`, `10m`, `2h`, `1d` or a date; bare numbers are rejected), `--until` (not with `--follow`), `--level=debug\|info\|warning\|error`, `--request=<id>`, `--filter=<text>`, `--source=all\|web\|console\|queues`, `--console`, `--queue=<name>`, `--platform`, `--config-layer=<path>`, `--json` |
| `profile` | Show or change the remembered profile per environment (`.arco/local.php`) | `--env`, `--set=<profile>`, `--json` (not with `--set`) |

`--follow` never ends by itself: do not run it in a foreground tool call. Use `--since`/`--until` to read a window.

#### Config layer

`--config-layer=<path>` (or `ARCO_CONFIG_LAYER`, the flag wins; empty or blank means the other one) on `deploy`,
`package`, `info`, `remove` and `logs` merges a JSON file over `arco.php`, or replaces a missing `arco.php`. It adds queues,
buckets, tables, variables, domains and settings and never overrides `arco.php`: a different value is
`config.layer_conflict` before any AWS call. Every other command ignores the flag and the variable, so do not expect
`queue:list` or the editing commands to see layer entries. A layer queue, storage or table with `adopt: true` imports
a retained resource of this app and environment. `--json` errors carry `error.params` when the key has params
(`config.layer_conflict`: `{kind, name}`). Format, merge rules and adoption: [Config layer](config-layer.md).

### Run code on the console function

Needs `->console()` in `arco.php` and a deploy that created the console function.

| Command | What it does | Options |
|---|---|---|
| `command "<args>"` | Run an artisan command (Laravel) or a PHP script with arguments (plain PHP: `"bin/console app:sync"`) on Lambda. The whole command is **one quoted string**, split without a shell (no pipes or redirects). Prints the output and exits with its exit code | `--env`, `--profile`, `-y/--yes` (protected environments), `--async` (start and return; follow with `logs --source=console`), `--timeout=<s>` (up to `console(timeout:)`, 900 by default) |
| `tinker` | PsySH on your machine, each statement run in Lambda (Laravel only; needs `laravel/tinker` in `require`) | `--env`, `--profile`, `--execute="<code>"` (one statement; exit 1 if it throws), `-y/--yes`, `--force` (deprecated spelling of `--yes`) |

Every command line and tinker statement is written to the console function's log as an audit trail. Do not pass
secrets as arguments.

### Edit `arco.php`

These commands only edit `arco.php` (except where noted) and make no AWS call. Each takes `--env=<env>` or `--app`,
checks the value with the validation rules, lints the result with `php -l` and restores the file if the reload
shows any other change. When a call has a shape it cannot edit safely (values built from variables, the call
written twice, an app built over several statements) it changes nothing, prints the line to write by hand and exits
1. Apply the change with `arco deploy --env=<env>`.

| Command | Writes | Notes |
|---|---|---|
| `init` | a new `arco.php`, `.arco/` in `.gitignore` | `--name`, `--region`, `-e/--env` (repeatable; default `production`), `--force` (overwrite `arco.php`), `--domain` (repeatable), `--dns-profile`, `--dns=manual\|cloudflare`, `--cloudflare-token`, `--console`/`--no-console`, `--migrate`/`--no-migrate`, `--scheduler=N`, `--log-retention=<days>`, `--queue=<name>` (repeatable), `--failed-jobs-table`, `--storage=<name>`, `--cache`. Writes `arco-ssm:` references for secret-like `.env` keys and prints the `secret:set` commands to run |
| `set <key> <value>` / `unset <key>` | `concurrency`, `max-instances`, `rate-limit`, `timeout`, `log-level`, `console-timeout`, `console-memory` | `unset` removes the call so the inherited value applies |
| `console:add` / `console:remove` | `->console()` / `->console(false)` | `console:remove` refuses while a scheduler runs |
| `scheduler:set [N]` / `scheduler:remove` | `->scheduler(N)` (and `->console()`) / scheduler off | N in 1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60; without N it suggests one from the app's schedule. `--apply` (with `--env`, `--profile`) also changes the EventBridge rule in AWS without a deploy |
| `scheduler:pause` / `scheduler:resume` | nothing: **AWS only** | Disable or enable the EventBridge rule now (`--env`, `--profile`); deploys keep a paused rule paused |
| `logs:retention <days\|never>` | `->logRetention(days\|false)` | `--remove` goes back to the 30-day default |
| `queue:add <name>` | `->queue('<name>', ...)` | `--timeout`, `--memory`, `--max-instances`, `--batch-size`, `--tries`, `--no-dlq`, `--fifo`, `--existing=<name\|URL\|ARN>`, `--default` (`--concurrency` is deprecated) |
| `queue:set <name> <option> <value>` | one queue option | `timeout`, `memory`, `max-instances` (`none` clears), `batch-size`, `tries`, `dlq` (`on`/`off`), `default` (`on`/`off`). `fifo` and `existing` cannot change |
| `queue:remove <name>` | drops the queue | The SQS queue and its messages stay in AWS |
| `failed-jobs:add` / `failed-jobs:remove` | `->failedJobsTable()` | `--existing=<table>`; `queue:failed-table` is the deprecated spelling |
| `storage:add <name>` / `storage:remove <name>` | `->storage('<name>', ...)` | `--existing=<bucket>`, `--default`. The bucket and its files stay in AWS |
| `storage:public <name> <prefix>` | public read prefix (`avatars/`) | `--remove` |
| `storage:expire <name> <prefix> <days>` | expiry rule | `--remove` (no days) |
| `storage:cors <name> <origin>...` | CORS origins | `--remove` |
| `cache:add` / `cache:remove` | `->cache()` | `--existing=<table>` |
| `table:add <name>` / `table:remove <name>` | `->table('<name>', ...)` | `--ttl=<attribute>`, `--existing=<name\|ARN>`; `cache` and `failed-jobs` are reserved names |
| `domain:add <host>...` / `domain:remove <host>...` | `->domain([...])` and `->dns(...)` | `--env` only (no `--app`); `domain:add` takes `--profile`, `--dns-profile`, `--dns=manual\|cloudflare`, `--cloudflare-token` and checks the zone in the place you name |

There is no command for `vpc()`, `variables()`, `permissions()`, `runtime()`, `deployCommands()`, `role()`,
`permissionsBoundary()`, `account()` or `exclude()`/`include()`: edit `arco.php` for those, then validate.

### AWS state of queues and storage

| Command | What it does | Options |
|---|---|---|
| `queue:list` | Each queue from AWS: URL, messages waiting and in flight, dead-letter queue and count, worker version | `--env`, `--profile`, `--json` |
| `queue:dlq <name>` | Count and a sample (up to 10) of the dead-letter queue | `--env`, `--profile`, `--redrive` (move the messages back: **changes AWS**), `-y/--yes` |
| `storage:list` | Each storage's bucket, URL and options, and retained buckets no longer in `arco.php` | `--env`, `--profile`, `--json` |

### Secrets

| Command | What it does |
|---|---|
| `secret:set <name>` | Store a value as an SSM SecureString at `/<app>/<env>/<name>` and print the reference to use. Without a terminal the value is read from stdin (trailing newline stripped). `--env`, `--profile` |

```bash
# The user runs this, or you pipe a value that never appears in the transcript:
php artisan key:generate --show | vendor/bin/arco secret:set app-key --env=production -n
```

Then reference it: `->variables(['APP_KEY' => 'arco-ssm:/<app>/production/app-key'])`. Other forms:
`arco-secret:<secret-id>` (Secrets Manager string) and `arco-secret:<secret-id>#<json-key>`. The deploy checks that
every reference exists and is readable before it builds (`deploy.secrets_missing`) and grants the execution role
read access to exactly those. Plain-text values of variables named `*_KEY`, `*_SECRET`, `*_PASSWORD`, `*_TOKEN`
produce the warning `validation.plaintext_secret`: treat it as a bug to fix.

## `arco.php` reference

```php
<?php

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\Runtime;

return App::make('shop')                                    // app name; functions are shop-<env>
    ->region('us-east-1')
    ->runtime(fn (Runtime $r) => $r->php('8.5'))
    ->console()
    ->queue('default')
    ->storage('files')
    ->environment('staging', fn (Environment $env) => $env
        ->variables(['APP_ENV' => 'staging', 'APP_KEY' => 'arco-ssm:/shop/staging/app-key']))
    ->environment('production', fn (Environment $env) => $env
        ->account('123456789012')
        ->domain('shop.example.com')
        ->deployCommands(['migrate --force'])
        ->variables(['APP_ENV' => 'production', 'APP_KEY' => 'arco-ssm:/shop/production/app-key']));
```

Environments are configured inside the `environment()` closure; there is no `Environment::make()`. The file is
loaded in its own PHP process and must `return` the `App`.

**Merge rules.** Scalar settings are replaced level by level. `variables()` and `phpIni()` merge by key.
`permissions()` statements are combined (app first). `domain()` and `deployCommands()` at environment level
replace the app's list. `queue()`, `storage()` and `table()` merge by name (`queue('x', false)` drops an app-level
one on an environment). `cache()`, `failedJobsTable()` and `vpc()` are single settings (`false` turns them off on
an environment).

**Named arguments.** Use named arguments for the options of `queue()`, `storage()`, `table()`, `cache()`,
`failedJobsTable()`, `console()` and `Runtime::workers()`/`binary()` (the 1.x promise covers those names), and write
`vpc(subnets: [...], securityGroups: [...])` as the docs do. Use the positional form for every other method
(`->maxInstances(10)`).

### App level only

| Method | Notes |
|---|---|
| `App::make(string $name)` | Lowercase letters, digits, hyphens, starting with a letter; `<app>-<env>` at most 56 characters; must not end in `-console`, `-resources`, `-scheduler`, `-host` or contain `-queue-` |
| `region(string)` | Required. One of `us-east-1`, `us-east-2`, `us-west-2`, `ap-northeast-1`, `eu-west-1`. Cannot change for a deployed environment (`deploy.region_changed`): use a new environment name instead |
| `environment(string $name, ?callable $configure)` | Environment names follow the app name rules |
| `exclude(array)` / `include(array)` | Packaging globs anchored at the project root (`docs/**`, `**/*.log`); `.env*` can never be included |

### Environment level only

| Method | Notes |
|---|---|
| `account(string $id)` | 12-digit account lock: every command refuses a profile of another account (`aws.account_mismatch`) |

### App or environment level

| Method | Default | Notes |
|---|---|---|
| `concurrency(int)` | AWS (64) | 1 to 128 requests per instance |
| `timeout(int)` | 30 | Request timeout, 3 to 900 s |
| `maxInstances(int)` | unset | 2 to 10000 running instances |
| `rateLimit(int)` | unset | 100 to 1000 in steps of 100, or 2000 to 10000 in steps of 1000 requests/s |
| `auth('public'\|'iam')` | `public` | With `domain()` the endpoint is always `IamAuth` (only CloudFront may call it) |
| `logLevel(string)` | `info` | trace, debug, info, warn, error, fatal |
| `variables(array)` | preset defaults | Names starting with `ARCO` and Lambda's reserved names are refused. Values may be `arco-ssm:`/`arco-secret:` references |
| `permissions(array)` | `[]` | Extra IAM statements (`Effect`, `Action`/`NotAction`, `Resource`/`NotResource`, optional `Sid` not starting with `Arco`, `Condition`) for the managed execution role; ignored with `role()` |
| `role(string $arn)` | managed role | Your own execution role; then you add every permission Arco would have added |
| `permissionsBoundary(string\|false $arn)` | unset | Boundary on the managed role; overridden by `--permissions-boundary` > `ARCO_PERMISSIONS_BOUNDARY`; not with `role()` |
| `kmsKey(string $arn)` | unset | KMS key for the revision |
| `profile(string)` | unset | AWS profile for the environment (else `.arco/local.php`) |
| `console(bool $enabled = true, ?int $timeout = null, ?int $memory = null)` | off | Console function; `timeout:` 3 to 900 (900), `memory:` 128 to 10240 MB (2048). `console(false)` deletes it on the next deploy |
| `deployCommands(array)` | `[]` | Artisan command lines (Laravel: `'migrate --force'`, never `'php artisan migrate'`) or script lines (plain PHP), run on the new console version before traffic moves; needs the console |
| `scheduler(int\|false)` | off | `schedule:run` every N minutes (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60), UTC clock-aligned; turns the console on |
| `logRetention(int\|false)` | 30 days | Days CloudWatch accepts; `false` = never expire |
| `protected(bool = true)` | on for `production` | Confirmation before `command`, `tinker`, `remove`, `queue:dlq --redrive` (`--yes` answers) |
| `queue(string $name, ...)` | none | Named options `timeout` (60; 10 to 900), `memory` (1024), `maxInstances`, `batchSize` (1), `tries` (3), `dlq` (true), `fifo` (false), `existing` (name, URL or ARN), `default`. `queue('x', false)` drops it |
| `storage(string $name, ...)` | none | Named options `public` (prefixes ending in `/`), `expire` (`['tmp/' => 1]`), `cors` (origins), `existing` (bucket name), `default`. Names `s3`, `public`, `local` are refused |
| `table(string $name, ...)` | none | DynamoDB `PK`/`SK` strings, on demand; named options `ttl` (attribute), `existing`. Read the names from `ARCO_TABLES` |
| `cache(bool = true, ?string $existing = null)` | off | DynamoDB cache table `<app>-<env>-cache`, `CACHE_STORE=dynamodb` |
| `failedJobsTable(bool = true, ?string $existing = null)` | off | DynamoDB failed-jobs table (items expire after about a week) |
| `domain(string\|array)` | none | Up to 10 lowercase hostnames, no wildcards; CloudFront + ACM (us-east-1) + DNS |
| `dns(string\|false)` | Route 53 of the deploy account | `'<profile>'` (Route 53 in another account), `'cloudflare'` (token in `CLOUDFLARE_API_TOKEN`), `false` (Arco prints the records) |
| `certificate(string $arn)` | looked up or requested | Issued ACM certificate in us-east-1 covering every domain |
| `vpc(array\|false $subnets, array $securityGroups = [], array $endpoints = [])` | none | `vpc(subnets: ['subnet-...'], securityGroups: ['sg-...'])`: 1 to 16 subnets, 1 to 5 security groups; `endpoints: ['s3', 'dynamodb']` (or `'s3' => 'vpce-...'` for the user's own endpoint) routes S3/DynamoDB through free gateway endpoints; `vpc(false)` clears an app-level one. See [VPC](#add-a-vpc) |
| `runtime(callable)` | PHP 8.5 | See below |

### Runtime (`->runtime(fn (Runtime $r) => $r->...)`)

| Method | Default | Notes |
|---|---|---|
| `php(string $minor)` | `8.5` | PHP minor; an Arco upgrade never changes the minor |
| `frankenphp(string)` | unset | Restrict to a FrankenPHP version prefix |
| `build(string $id)` | unset | Exact runtime build (`php8.5.11-fp1.13.0-r2`); not with `php()`/`frankenphp()` at the same level |
| `binary(string $frankenphp, ?string $php = null)` | unset | Your own Linux arm64 FrankenPHP binary (and static PHP CLI) |
| `workers(int)` | 4 | Octane workers |
| `maxThreads(int)` | 64 | FrankenPHP `max_threads` (>= workers) |
| `threads(int)` | 8 | FrankenPHP `num_threads` |
| `phpIni(array)` | `[]` | `php.ini` overrides (merged by key) |

### Variables the app sees

Arco sets `ARCO=1`, `ARCO_APP`, `ARCO_ENV` on every function, `ARCO_BUILD=1` during the build, `ARCO_TABLES` with
`table()`, and the Laravel variables of each feature (`QUEUE_CONNECTION=sqs`, `SQS_PREFIX`, `SQS_QUEUE`,
`FILESYSTEM_DISK=s3`, `AWS_BUCKET`, `AWS_URL`, `CACHE_STORE=dynamodb`, `DYNAMODB_CACHE_TABLE`,
`QUEUE_FAILED_DRIVER=dynamodb`) plus the Laravel preset's defaults (`LOG_CHANNEL=stderr`, `SESSION_DRIVER=cookie`,
`LARAVEL_STORAGE_PATH=/tmp/arco/storage`). Your `variables()` win over Arco's, with a note in the deploy output.
Details: [Variables Arco sets](configuration.md#variables-arco-sets).

### What the app needs in `composer.json`

The deploy checks the built package and stops before any change (`deploy.needs_packages`, `build.needs_packages`)
naming the `composer require` to run. In `require`, not `require-dev` (the build runs `composer install --no-dev`):

| Feature | Packages |
|---|---|
| Laravel at all | `laravel/octane`, `multiapi/arco-laravel` (same minor as `multiapi/arco`) |
| `queue()` | `aws/aws-sdk-php`, `multiapi/arco-laravel` 0.5+ |
| `storage()` | `league/flysystem-aws-s3-v3:^3.0`, `aws/aws-sdk-php`, `multiapi/arco-laravel` 0.6+ |
| `cache()`, `table()` | `aws/aws-sdk-php` |
| `failedJobsTable()` | `aws/aws-sdk-php`, `multiapi/arco-laravel` 0.6+ |
| `arco tinker` | `laravel/tinker`, `multiapi/arco-laravel` |

`multiapi/arco` itself goes in `require-dev`.

## Workflows

### First deploy of a Laravel app

1. Install: `composer require multiapi/arco-laravel laravel/octane` and `composer require --dev multiapi/arco`.
2. Write `arco.php` without questions:
   ```bash
   vendor/bin/arco init -n --name=shop --region=us-east-1 --env=staging --env=production --console --migrate
   ```
   Add `--queue=<name>`, `--storage=<name>`, `--cache`, `--failed-jobs-table`, `--scheduler=N` for what the app uses.
   `init` prints the `arco ...:add` lines for detected features it did not add, the `composer require` line for
   missing packages, and the `secret:set` commands for the `arco-ssm:` references it wrote.
3. Install the packages it names. Validate: `vendor/bin/arco info --env=staging --json -n`.
4. Ask the user which AWS profile deploys each environment; save it with
   `vendor/bin/arco profile --env=staging --set=<profile>` (or pass `--profile`).
5. Have the user store each secret (`vendor/bin/arco secret:set app-key --env=staging`), or pipe generated values as
   in [Secrets](#secrets).
6. With the user's go-ahead, deploy: `vendor/bin/arco deploy --env=staging --yes --json -n`. Read the `result` line;
   `url` is the endpoint. Then production the same way.
7. Suggest locking the environment to its account: `->account('<id>')` (the id is in the `deploy.target` step's
   params).

### Change a setting or add a feature

```bash
vendor/bin/arco set max-instances 20 --env=production -n
vendor/bin/arco queue:add emails --env=production --timeout=120 -n
vendor/bin/arco storage:add uploads --env=production -n
vendor/bin/arco info --env=production --json -n      # validate
vendor/bin/arco deploy --env=production --json -n    # only when the user asked
```

A new queue, storage or table may need packages (see above) and deploy permissions (see
[AWS permissions](#aws-permissions)).

### Run migrations

- Every deploy: `->deployCommands(['migrate --force'])` (needs `->console()`). One deploy only:
  `arco deploy --migrate`. Deploy commands run before traffic moves; a failure stops the deploy with the previous
  revision still serving and exits with the command's code.
- Arco never rolls migrations back. If verification fails after migrations ran, traffic returns to the old revision
  but the schema stays: write migrations both versions accept (expand, then contract).
- Once, by hand: `vendor/bin/arco command "migrate --force" --env=<env> -n` (add `--yes` on a protected
  environment, only with the user's go-ahead).

### Add a VPC

For an app that must reach RDS, ElastiCache or another private resource:

1. Get from the user the subnet ids (two Availability Zones for production) and a security group allowing the
   egress the app needs (database port, 443).
2. Make sure the subnets have a **NAT gateway** (or VPC endpoints): a function in a VPC has no internet, and the app
   calls SSM and Secrets Manager (secret references), SQS, S3 and DynamoDB at runtime. For S3 and DynamoDB add
   `endpoints: ['s3', 'dynamodb']`: Arco creates or reuses free gateway endpoints (never deletes one it did not
   create). SSM, Secrets Manager and SQS still need a NAT or interface endpoints the user creates. Arco does not check
   that routing; a missing NAT shows up as timeouts.
3. Make sure the deploy profile has the [VPC permissions](getting-started.md#permissions-for-vpc)
   (`lambda:*NetworkConnector*`, `lambda:PassNetworkConnector`, `iam:PassRole` on `arco-<app>-*-network`,
   `ec2:Describe*` for subnets, security groups, VPCs and network interfaces, `lambda:ListFunctions`; with
   `endpoints`, also `ec2:DescribeRouteTables`, `ec2:DescribeVpcEndpoints`, `ec2:CreateVpcEndpoint`,
   `ec2:ModifyVpcEndpoint`, `ec2:DeleteVpcEndpoints`, `ec2:CreateTags`, `ec2:DeleteTags`).
4. Edit `arco.php` (no CLI command for this):
   ```php
   ->environment('production', fn (Environment $env) => $env
       ->vpc(subnets: ['subnet-0abc1234', 'subnet-0def5678'], securityGroups: ['sg-0abc1234'],
           endpoints: ['s3', 'dynamodb']))
   ```
5. Validate, then deploy. The first deploy creates the network connector and waits about 4 minutes. `arco info`
   shows the connector id and state, and the gateway endpoints (`vpc` and `vpc.endpoints` in `--json`).
   If the VPC only has the user's own endpoint and it misses some route tables, a deploy without a terminal (`-n`,
   `--json`, `--yes`) fails with `deploy.endpoint_routes_missing`; the message gives the `aws ec2 modify-vpc-endpoint`
   command and the `endpoints: ['s3' => 'vpce-...']` line. Ask the user which one; never pick for them.
6. To leave the VPC remove `vpc()` (or `->vpc(false)` on the environment) and deploy: Arco deletes the connector
   and its operator role after the deploy is verified, and may wait up to about 20 minutes for Lambda to delete the
   network interfaces. Arco's gateway endpoints are shared by the environments of the VPC and deleted when the last
   one leaves; route tables are never removed.

With `role()`, the user must add the network interface permissions (`AWSLambdaVPCAccessExecutionRole`) to that
role. Floci skips `vpc()` with the note `deploy.vpc_unsupported_emulator`. Details: [VPC](configuration.md#vpc),
[Gateway endpoints](configuration.md#gateway-endpoints).

### Add a custom domain

```bash
vendor/bin/arco domain:add shop.example.com --env=production -n                   # zone in the deploy account
vendor/bin/arco domain:add shop.example.com --env=production --dns-profile=dns -n  # Route 53 in another account
vendor/bin/arco domain:add shop.example.com --env=production --dns=cloudflare -n   # needs CLOUDFLARE_API_TOKEN
vendor/bin/arco domain:add shop.example.com --env=production --dns=manual -n       # Arco prints the records
```

The deploy then requests or reuses an ACM certificate in us-east-1, creates the CloudFront distribution (several
minutes the first time) and writes the DNS records. It never takes over a live DNS record that points elsewhere: it
stops before creating anything and names it. Replacing it needs `--force-dns`, which is the user's call. Extra
deploy permissions: [Permissions for custom domains](getting-started.md#permissions-for-custom-domains).

### Remove an environment

1. Confirm with the user the environment and the account. `vendor/bin/arco info --env=<env> --resources --json -n`
   lists what is tagged for it.
2. `vendor/bin/arco remove --env=<env> --yes --json -n`.
3. Report what was kept from the `remove.resource_kept` notes: SQS queues (with messages), buckets (with files),
   DynamoDB tables (with items), SSM parameters, log groups and the shared code bucket. Delete those only if the user
   asks, outside Arco.
4. Remove the `environment('<env>')` block from `arco.php` if the environment is gone for good.

### Debug a failed deploy

1. Read the `result` line: `error.key` names the cause (see [Errors](#errors-and-how-to-fix-them)); `rolled_back:
   true` means the previous revision serves again.
2. Verification failures print the recent logs. Read more with
   `vendor/bin/arco logs --env=<env> --since=15m --level=error -n` (add `-v` for context and stack traces,
   `--platform` for Lambda's own events, `--source=console` for deploy commands).
3. Typical causes of a failed health check: `APP_KEY` or another secret missing, a database the function cannot
   reach (VPC, security group), a route `/up` that is not 2xx, code that writes into the read-only `/var/task`
   (`config:cache`, `route:cache`, `storage:link`).

### CI

Credentials come from the environment (`aws-actions/configure-aws-credentials`); every command gets `-n`, and
`--yes` where a confirmation is expected:

```bash
vendor/bin/arco deploy --env=production --yes --json -n > deploy.ndjson
vendor/bin/arco command "migrate:status" --env=production --yes -n
```

Build once, deploy later: `arco package --env=production --output=.arco/build/app.zip`, then
`arco deploy --env=production --package=.arco/build/app.zip`. Variables are not in the zip; they come from
`arco.php` at deploy time.

## AWS permissions

Two different identities matter:

- **The deploy profile** (the human's or CI's credentials). The full lists are in
  [Minimal IAM permissions](getting-started.md#minimal-iam-permissions), with additions per feature:
  [queues](getting-started.md#permissions-for-queues),
  [storage and tables](getting-started.md#permissions-for-storage-and-tables),
  [custom domains](getting-started.md#permissions-for-custom-domains), [VPC](getting-started.md#permissions-for-vpc)
  and [enforcing a permissions boundary](getting-started.md#enforcing-a-permissions-boundary). In short: Lambda Web
  Function actions, IAM role actions on `role/arco-*`, S3 on `arco-*`, SSM on `parameter/<app>/*`, CloudWatch Logs,
  `sts:GetCallerIdentity`, the console/worker Lambda actions, EventBridge for the scheduler, `tag:GetResources`,
  and CloudFormation on `stack/arco-*` for resources and domains.
- **The execution role** `arco-<app>-<env>` (what the app may do at runtime). Arco writes its policy: logs, the
  queues, buckets and tables in `arco.php`, the referenced secrets, and the VPC network interfaces. Anything else the
  app calls (SES, a bucket not in `arco.php`) goes in `permissions()`.

When AWS denies a call, Arco names the operation (`aws.access_denied`). Tell the user which permission to add to
which identity; do not try to work around it.

## Local testing with Floci

Floci is a local AWS emulator. With `AWS_ENDPOINT_URL` set, `deploy`, `info`, `logs`, `command` and `remove` go to it
instead of AWS:

```bash
docker run --rm -d --name floci -p 4566:4566 -e FLOCI_BASE_URL=http://localhost:4566 \
  -v /var/run/docker.sock:/var/run/docker.sock floci/floci:nightly-10192026
export AWS_ENDPOINT_URL=http://localhost:4566 AWS_ACCESS_KEY_ID=test AWS_SECRET_ACCESS_KEY=test \
  AWS_REGION=us-east-1 AWS_CONFIG_FILE=/dev/null AWS_SHARED_CREDENTIALS_FILE=/dev/null
vendor/bin/arco deploy --env=local --yes -n
```

- Use a dedicated environment without `account()` (Floci's account is `000000000000`), with literal test values
  instead of `arco-ssm:` references.
- `AWS_CONFIG_FILE=/dev/null` and `AWS_SHARED_CREDENTIALS_FILE=/dev/null` keep real profiles out of reach; do not set
  `AWS_PROFILE`. The deploy prints `deploy.endpoint_override` naming the endpoint: if you see it in a shell meant
  for real AWS, stop and unset `AWS_ENDPOINT_URL`.
- Floci does not enforce IAM like AWS, has no VPC, no CloudFront endpoint protection, no `auth('iam')` verification,
  no Cloudflare and no real DNS or TLS. A deploy that works there can still fail on AWS.
- `arco info` prints the local URL (`http://<id>.001.lambda-web.<region>.localhost:4566`).

Details: [Try your app locally with Floci](local-floci.md).

## Errors and how to fix them

`error.key` in `--json` (and the message without it) names the problem. Keys are stable within 1.x; messages are
not. The most common ones:

### Configuration and validation

| Key | Cause | Fix |
|---|---|---|
| `config.not_found` | No `arco.php` in the current directory | Run from the project root, or `arco init` |
| `config.failed`, `config.invalid_return` | `arco.php` threw, or does not return the `App` | Fix the PHP error shown; end the file with `return App::make(...)...;` |
| `config.choose_environment` | Several environments and no `--env` | Pass `--env=<env>` |
| `config.unknown_environment` | `--env` names no environment | Use one of the names listed |
| `config.layer_not_found` | `--config-layer` or `ARCO_CONFIG_LAYER` points to a file that is not there | Fix the path |
| `config.layer_schema`, `config.layer_invalid` | The layer has no `schema: 1`, or an unknown key, a wrong type or bad JSON (`path` is a JSON pointer) | Fix the layer at `path` ([Config layer](config-layer.md)) |
| `config.layer_missing` | No `arco.php` and the layer lacks `name` or `region` | Add the key to the layer |
| `config.layer_environment` | `--env` is not the layer's environment | Drop `--env` or use the layer's |
| `config.layer_conflict` | The layer sets differently something `arco.php` sets (`kind`: `name`, `region`, `setting`, `variable`, `queue`, `storage`, `table`) | Remove it from one of them; every conflict is listed |
| `config.layer_adopt_waiting` (note) | A layer `adopt: true` entry waits because `arco.php` still declares the name | Remove the name from `arco.php` |
| `config.removed_*`, `config.changed_*` | API removed or renamed in 0.9 | Apply the replacement the message gives ([Upgrading](upgrading.md)) |
| `validation.*` | A value out of range or a bad name (`validation.region`, `validation.timeout`, `validation.queue_name`, `validation.bucket_name_length`, `validation.vpc_id`, ...) | The message names the rule; fix the value in `arco.php`. Name length errors need a shorter app, environment or resource name |
| `validation.reserved_arco_variable` | A variable named `ARCO*` | Rename it |
| `validation.plaintext_secret` (warning) | A secret-looking variable holds plain text | Move it to `arco secret:set` and an `arco-ssm:` reference |
| `validation.scheduler_needs_console` | `scheduler()` with `console(false)` | Remove one of them |
| `validation.boundary_with_custom_role` | `permissionsBoundary()` (or the flag/variable) with `role()` | Drop one; put the boundary on the custom role in IAM |
| `binary.unknown_php`, `binary.unknown_build`, `binary.build_and_version` | Runtime selection this Arco version does not know, or `build()` mixed with `php()` | Use a PHP minor or build the message lists; upgrade Arco for newer ones |

### Credentials and accounts

| Key | Cause | Fix |
|---|---|---|
| `aws.no_profile_non_interactive` | No profile resolved without a terminal | Ask the user; pass `--profile` or run `arco profile --env=<env> --set=<p>` |
| `aws.credentials_expired`, `aws.credentials_expired_generic` | Expired SSO or keys | The message gives the command (`aws sso login --profile <p>`): the user must run it |
| `aws.account_mismatch` | The profile points to another account than `account()` | Wrong profile: ask the user. Never edit `account()` to make it pass |
| `aws.access_denied` | The deploy profile lacks an action | Report the operation named to the user; see [AWS permissions](#aws-permissions) |

### Confirmations

| Key | Fix |
|---|---|
| `deploy.confirm_required` | First deploy of the environment: re-run with `--yes` once the user confirmed creating it |
| `remove.yes_required` | Re-run with `--yes` once the user confirmed the removal |
| `protected.needs_yes` | Protected environment: `--yes` once the user confirmed that action there |

### Ownership and guards (nothing was changed)

| Key | Cause | Fix |
|---|---|---|
| `deploy.not_owned`, `ownership.not_owned`, `remove.not_owned` | A resource with Arco's name is tagged for another app or environment (`shop-eu`/`prod` and `shop`/`eu-prod` share names) | Rename the app or environment. Only the user may decide to use `--force` |
| `deploy.untagged_resource`, `ownership.untagged_resource`, `remove.untagged_resource` | A resource with Arco's name but no Arco tags | Same: rename, or the user confirms it is theirs and deploys with `--force` |
| `deploy.tags_unreadable`, `ownership.tags_unreadable`, `remove.tags_unreadable` | The profile cannot read tags | Allow the action named (`lambda:ListTags`, `events:ListTagsForResource`, ...) |
| `deploy.region_changed` | `region()` changed for a deployed environment | Restore the region, or deploy under a new environment name |
| `stack.not_ours` | A CloudFormation stack with Arco's name not tagged for this environment | Rename or remove that stack, or change the app/environment name |
| `deploy.network_foreign` | A network connector named `<app>-<env>` with another operator role (refused even with `--force`) | Remove or rename it, or change the app/environment name |

### Build and package

| Key | Cause | Fix |
|---|---|---|
| `build.needs_packages`, `laravel.octane_missing` | The preset needs packages `composer.json` does not require | Run the `composer require` given |
| `deploy.needs_packages` | Features in `arco.php` need packages missing from the built package | Run the `composer require` given (in `require`, not `require-dev`) |
| `build.command_failed` | `composer install --no-dev` or a preset build command failed | Read the output; reproduce locally (`composer install --no-dev`, `php artisan event:cache`) |
| `package.too_large` | Unzipped package over 512 MB | `->exclude([...])` what the app does not need at runtime (the message lists the largest directories) |
| `deploy.workers_package_too_large`, `deploy.console_skipped_size` (warning) | Console/worker package over Lambda's 250 MB | `->exclude([...])` large folders |
| `package.not_arco`, `package.wrong_target`, `package.wrong_preset`, `package.corrupt` | `--package` zip unusable for this target | Rebuild with `arco package --env=<env>` |

### Deploy

| Key | Cause | Fix |
|---|---|---|
| `deploy.secrets_missing` | An `arco-ssm:`/`arco-secret:` reference does not exist or is unreadable | Have the user run `arco secret:set <name> --env=<env>`, or fix the reference |
| `deploy.command_failed` | A deploy command exited non-zero; traffic did not move | Read its output (stderr), fix, deploy again |
| `deploy.migrate_conflict`, `deploy.migrate_laravel_only` | `--migrate` with `--no-deploy-commands`, or not Laravel | Drop one flag; use `deployCommands()` for other apps |
| `console.disabled` | `command`, `tinker` or deploy commands with the console off | `arco console:add --env=<env>`, then deploy |
| `console.not_deployed` | Console on but not deployed yet | `arco deploy --env=<env>` first |
| `deploy.verify_failed` (`rolled_back: true`) | The new revision failed its health check | See [Debug a failed deploy](#debug-a-failed-deploy) |
| `deploy.verify_iam_no_report` | `auth('iam')` without a domain: no init report in time | Check the logs; Floci never writes it |
| `deploy.verify_signed_forbidden` | With `domain()`, the signed `/up` probe got 403 | Allow `lambda:InvokeWebFunctionEndpoint` in the deploy profile |
| `deploy.queue_retained`, `deploy.queue_dlq_retained`, `deploy.storage_retained`, `deploy.table_retained`, `deploy.generic_table_retained` | A kept queue, bucket or table already has the name | Attach it with the `existing:` line the message prints, or choose another name. Do not delete the kept resource without the user |
| `deploy.import_failed` | CloudFormation could not import a retained resource adopted with a layer's `adopt: true`; it rolled the import back and the resource is untouched | Read the reason, fix it, deploy again. Reason `cloudformation:GetTemplate`: refused before any change because the running template cannot be read; allow it. Steps `deploy.step_import` (`deploy.step_import_one` for one resource), `deploy.queue_adopted`, `deploy.queue_dlq_adopted` (an imported dead-letter queue, after its queue's step), `deploy.storage_adopted` and `deploy.generic_table_adopted` are the import's progress, not errors |
| `deploy.queue_replaced`, `deploy.resource_replaced` | A change CloudFormation would do by replacement (FIFO on/off, ...) | Undo it; add a new queue or resource under another name |
| `deploy.bucket_taken` | S3 bucket name taken in another account or region (or missing `s3:ListBucket`) | Shorter/other storage name, or allow `s3:ListBucket` |
| `deploy.public_blocked`, `deploy.public_policy_denied` | Account-level S3 Block Public Access refuses public prefixes | The user turns off `BlockPublicPolicy` for the account, or drop `public:` |
| `deploy.lookup_denied` | Cannot check whether a resource exists | Allow the permission named |
| `stack.failed`, `stack.not_updatable`, `domains.stack_failed`, `domains.stack_not_updatable` | CloudFormation stack failed or stuck (`UPDATE_ROLLBACK_FAILED`) | Read the reason; a stuck stack needs "Continue update rollback" in the console (the user's call) |
| `domains.certificate_*` | `certificate()` missing, not issued, unsupported, or validation failed | Fix the ARN, or check validation and CAA records |
| `cloudflare.token_missing`, `cloudflare.token_rejected`, `cloudflare.zone_missing` | `dns('cloudflare')` without a usable token or zone | The user sets `CLOUDFLARE_API_TOKEN` (Zone → DNS → Edit, Zone → Zone → Read) |
| `deploy.network_failed` | The VPC connector ended `FAILED` (bad subnet or security group, no free IPs) | Fix `vpc()` and deploy again |
| `deploy.network_timeout` | The connector was not ready in time | Deploy again once it settles |
| `deploy.endpoint_routes_missing` | Only the user's own S3/DynamoDB gateway endpoint exists and it misses route tables; no terminal to ask | Show the user both fixes from the message (the `aws ec2 modify-vpc-endpoint` command, or naming the endpoint in `vpc()`); let them choose |
| `deploy.endpoint_not_found`, `_wrong_type`, `_wrong_service`, `_wrong_vpc`, `_vpc_mixed` | A `vpce-...` in `endpoints` is not a gateway endpoint for that service in the subnets' VPC, or the subnets span VPCs | Fix the id (or use the service name to let Arco manage it), or use subnets of one VPC |
| `deploy.endpoint_conflict`, `deploy.endpoint_timeout` | Another deploy changed the endpoint at the same time, or it was not available in time | Deploy again |
| `deploy.endpoint_tag_limit` | About 47 environments already share the endpoint (EC2's 50-tag limit) | Remove stale `arco:uses:*` tags, or name another endpoint |
| `deploy.boundary_denied` | IAM denied a role write under a permissions boundary | The boundary passed must be exactly the one the account requires; ask the account owner |

Warnings whose message starts with "The deploy is live and verified" (`deploy.scheduler_failed`,
`deploy.worker_failed`, `deploy.log_retention_failed`, ...) mean the web revision is fine but a follow-up step
failed, usually for a missing permission the message names.

### Other commands

| Key | Fix |
|---|---|
| `command.empty`, `command.invalid` | Give the command as one quoted string with balanced quotes |
| `command.invalid_timeout` | `--timeout` from 1 to the console timeout |
| `tinker.laravel_only`, `tinker.psysh_missing` | Tinker needs Laravel with `laravel/tinker` in `require` |
| `logs.invalid_time`, `logs.until_with_follow`, `logs.invalid_source`, `logs.source_conflict`, `logs.invalid_level` | Use `30s`/`10m`/`2h`/`1d` or a date; no `--until` with `--follow`; one of `--source`, `--console`, `--queue` |
| `queue.unknown`, `queue.option_fixed`, `queue.unknown_option` | Use a queue name from `arco.php`; `fifo` and `existing` cannot change |
| `queue.dlq_missing` | Deploy first |
| `scheduler.no_rule`, `scheduler.apply_needs_env`, `scheduler.apply_needs_deploy` | `scheduler:set` and deploy first; `--apply` needs `--env` |
| `editor.lint_failed`, `editor.verify_failed` | The edit was refused and `arco.php` restored: make the change by hand with the line printed |
| `remove.nothing` | The environment is not deployed; nothing to do |
| `error.unexpected` | An unexpected exception (`message` has it); report it with the command and Arco version (`vendor/bin/arco --version`) |

## Using this page as a Claude Code skill

Arco ships a skill that points Claude Code at this page. Copy it into the project:

```bash
mkdir -p .claude/skills && cp -R vendor/multiapi/arco/skills/arco .claude/skills/
```

`.claude/` is never packaged into the deploy. Other agents can read `vendor/multiapi/arco/llms.txt`, which links to
these docs.
