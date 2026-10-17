# Arco

**The bridge between PHP and AWS Lambda Web Functions.**

Run Laravel and PHP on [AWS Lambda Web Functions](https://docs.aws.amazon.com/lambda/latest/dg/web-functions.html).

Arco is an open-source PHP CLI by [Multiapi](https://multiapi.cl). It builds your app, uploads it and
deploys it to Lambda Web Functions with [FrankenPHP](https://frankenphp.dev), with environments,
secrets, custom domains and rollback built in.

> **Status: early preview (0.x).** Lambda Web Functions launched in October 2026 and Arco is new.
> It already runs a production Laravel app, but expect changes between minor versions. Read the
> [changelog](CHANGELOG.md) before upgrading.

## Requirements

- PHP >= 8.3 and Composer on your machine (no Docker, no Node.js needed locally)
- An AWS account and a profile for it (SSO, assume-role and static-key profiles all work)
- For Laravel: Laravel >= 11 with `laravel/octane`

## Quick start

```bash
composer require multiapi/arco-laravel laravel/octane
composer require --dev multiapi/arco
vendor/bin/arco init
vendor/bin/arco secret:set app-key --env=production
vendor/bin/arco deploy --env=production
```

`init` asks for the app name, region, environments and (optionally) a custom domain, and writes
`arco.php`. The first deploy asks which AWS profile to use and remembers it.

## What you get

- **One command deploys.** `arco deploy` builds a copy of the project, packages it with a pinned,
  attestation-verified FrankenPHP arm64 binary, uploads it, creates a revision, shifts traffic,
  checks the health route and rolls back if it fails.
- **Laravel with Octane workers** on FrankenPHP. Node.js only launches FrankenPHP at start-up; it
  is not in the request path.
- **Environments** (`production`, `staging`, …), each its own Web Function, all in one `arco.php`.
- **Secrets** as `arco-ssm:` / `arco-secret:` references, resolved when the function starts and
  scoped in IAM to exactly what you reference.
- **Operations.** `arco logs` inspects the deployed app from your terminal. With the opt-in console
  function (`->console()`), `arco command` and `arco tinker` run on it and migrations can run on every deploy
  before traffic moves; `->scheduler(N)` runs Laravel's scheduler on a UTC clock-aligned EventBridge rule.
- **Queues.** `->queue('emails')` creates an SQS queue with a dead-letter queue and a worker function that
  runs the jobs with Laravel's own worker (retries, `failed()` and `failed_jobs` as usual);
  `->onQueue('emails')` works without app changes.
- **Storage, cache and failed jobs.** `->storage('uploads')` creates a private S3 bucket that is a Laravel
  disk (the first one is the default disk), with optional public prefixes, expiry rules and CORS;
  `->cache()` and `->failedJobsTable()` create DynamoDB tables for Laravel's cache and failed jobs. Each can
  point at one you already have (`existing:`), and removed ones stay in AWS with their data.
- **Custom domains** in one line: certificate, CloudFront and DNS records, with the DNS zone in the
  same AWS account, another account, or outside AWS.

## Configuring `arco.php`

`arco init` writes this file; edit it by hand or with `arco set` / `arco domain:add`. It is plain
PHP returning an `App`:

```php
<?php

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\Runtime;

return App::make('shop')                       // function name: shop-<environment>
    ->region('us-east-1')

    // App level: defaults for every environment
    ->timeout(28)
    ->variables(['APP_LOCALE' => 'es'])
    ->exclude(['docs/**'])                       // never packaged

    ->environment('production', fn (Environment $env) => $env
        ->profile('my-company-prod')             // AWS profile used to deploy this environment
        ->domain(['shop.example.com', 'api.shop.example.com'])
        ->dns('my-company-dns')                  // Route 53 zone lives in another account (optional)
        ->maxInstances(10)                       // cap on running instances (and DB connections)
        ->variables([
            'APP_ENV' => 'production',
            'APP_KEY' => 'arco-ssm:/shop/production/app-key',            // SSM parameter
            'DB_PASSWORD' => 'arco-secret:shop/production/db#password',  // Secrets Manager JSON key
        ])
        ->permissions([                          // extra IAM for the function (S3, SES, …)
            ['Effect' => 'Allow', 'Action' => 's3:GetObject', 'Resource' => 'arn:aws:s3:::shop-files/*'],
        ])
        ->runtime(fn (Runtime $r) => $r->phpIni(['memory_limit' => '512M'])))

    ->environment('staging', fn (Environment $env) => $env
        ->variables(['APP_ENV' => 'staging', 'APP_KEY' => 'arco-ssm:/shop/staging/app-key']));
```

**Inheritance:** a value set on an environment wins over the app level, which wins over Arco's
default. `variables()` and `permissions()` are merged (environment entries added to the app's).
`arco info --env=production` shows every resolved value and where it came from.

The main options:

| Option | Default | What it does |
|---|---|---|
| `region()` | — (required) | AWS region of the function |
| `concurrency(int)` | AWS default (64) | Cap on requests per instance (2 vCPU / 2 GB); Lambda adds instances as traffic grows |
| `maxInstances(int)` | no cap | Maximum running instances |
| `rateLimit(int)` | no limit | Requests per second; extra requests get 429 |
| `timeout(int)` | 30 | Seconds per request (3–900) |
| `variables([...])` | preset defaults | Environment variables; values may be secret references |
| `permissions([...])` | — | Extra IAM statements for the function's role |
| `domain(...)` / `dns(...)` | — | Custom domains and where their DNS zone is |
| `profile()` / `account()` | asked on first deploy | AWS profile, and an account lock so a wrong profile can't deploy |
| `auth('public'\|'iam')` | `public` | `iam` requires signed requests |
| `runtime(fn ...)` | | FrankenPHP/Octane: `workers()`, `threads()`, `phpIni()`, `build()` |
| `deployCommands([...])` | — | Commands run on every deploy before traffic moves, e.g. `['migrate --force']` |
| `console(bool = true)` | off | Deploy the console function (`arco command`, `arco tinker`, deploy commands); `console(false)` deletes it |
| `scheduler(int\|false)` | off | Run `schedule:run` every N minutes (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60), clock-aligned in UTC; needs the console |
| `logRetention(int\|false)` | not managed | Days CloudWatch keeps the logs; `false` = never expire |
| `queue(name, ...)` | none | An SQS queue and its worker function; named options `timeout`, `memory`, `concurrency`, `batchSize`, `tries`, `dlq`, `fifo`, `existing`, `default` |
| `storage(name, ...)` | none | An S3 bucket used as a Laravel disk; named options `public`, `expire`, `cors`, `existing`, `default` |
| `cache()` / `failedJobsTable()` | off | DynamoDB tables for Laravel's cache and failed jobs; `existing:` names your own table |
| `consoleTimeout(int)` | 900 | Seconds a console command may run (3–900) |
| `protected()` | `production` | Ask before `arco tinker` on this environment |

Every option, the packaging rules and the Laravel preset are in the
[configuration reference](docs/configuration.md).

## Commands

| Command | What it does |
|---|---|
| `arco init` | Create `arco.php` (asks name, region, environments, custom domain) |
| `arco deploy --env=<env>` | Build, upload, deploy commands, deploy, verify, attach domains; `--migrate` runs migrations first; `--package=<zip>` deploys a prebuilt zip |
| `arco console:add --env=<env>` / `console:remove` | Turn the console function on or off in `arco.php` (off deletes it on the next deploy) |
| `arco command "<args>" --env=<env>` | Run an artisan (or PHP) command on the console function (needs `console()`); exits with its exit code (`--async` to start it and return) |
| `arco tinker --env=<env>` | Tinker on your machine, statements run in Lambda (needs `console()`; `--execute="<code>"` for one statement) |
| `arco scheduler:set [N] --env=<env>` / `scheduler:remove` | Run Laravel's scheduler every N minutes (suggests N from your tasks; turns the console on) / turn it off; `--apply` (or answer yes) applies it in AWS without a deploy |
| `arco scheduler:pause --env=<env>` / `scheduler:resume` | Pause or resume the scheduler in AWS at once, without editing `arco.php` (deploys keep it paused) |
| `arco logs:retention <days\|never> --env=<env>` | Set how long CloudWatch keeps the logs (`--remove` stops managing it) |
| `arco queue:add <name> --env=<env>` / `queue:set` / `queue:remove` | Add, change or remove a queue in `arco.php` (removed queues stay in AWS with their messages) |
| `arco queue:list --env=<env>` | Each queue's messages, dead-letter queue and worker version |
| `arco queue:dlq <name> --env=<env>` | Show the messages in a queue's dead-letter queue; `--redrive` moves them back |
| `arco queue:failed-table --env=<env>` | Turn the DynamoDB failed-jobs table on (`--existing=<table>`) or off (`--remove`) in `arco.php` |
| `arco storage:add <name> --env=<env>` / `storage:remove` | Add or remove a storage in `arco.php` (`--existing=<bucket>`, `--default`; removed buckets stay in AWS with their files) |
| `arco storage:public` / `storage:expire` / `storage:cors` | Public prefixes, expiry rules and CORS origins of a storage (`--remove` takes one out) |
| `arco storage:list --env=<env>` | Each storage's bucket, URL and options, and the retained buckets no longer in `arco.php` |
| `arco cache:add --env=<env>` / `cache:remove` | Turn the DynamoDB cache table on (`--existing=<table>`) or off in `arco.php` |
| `arco logs --env=<env>` | Web, console and queue worker logs merged, filtered and colored (`--follow`, `--since`, `--level`, `--request`, `--filter`, `--queue`) |
| `arco package --env=<env>` | Build the zip only (for CI: build once, deploy later) |
| `arco info --env=<env>` | Resolved settings with their source, URL, active revision (`--json` too) |
| `arco secret:set <name> --env=<env>` | Store a secret in SSM and print the reference to use |
| `arco set <key> <value> --env=<env>` / `arco unset <key>` | Change `concurrency`, `max-instances`, `rate-limit`, `timeout`, `log-level` in `arco.php` |
| `arco domain:add <host> --env=<env>` / `domain:remove` | Change an environment's domains in `arco.php` |
| `arco profile --env=<env>` | Show or change the AWS profile remembered for an environment |
| `arco remove --env=<env>` | Delete the function, its endpoint, role, workers and domain resources (queues, buckets and tables are kept) |

Messages are in English or Spanish (`--lang=es` or `ARCO_LANG=es`).

## Documentation

- [Getting started](docs/getting-started.md)
- [Configuration reference](docs/configuration.md)

## Limits (of Lambda Web Functions)

- 2 vCPU and 2 GB per instance, 512 MB unzipped package, 512 MB `/tmp`, arm64 only
- Regions: us-east-1, us-east-2, us-west-2, ap-northeast-1, eu-west-1
- The console function (commands, tinker, scheduler) is opt-in and a classic Lambda function: 250 MB
  unzipped package, with a static PHP CLI instead of the FrankenPHP binary
- The queue workers are classic Lambda functions running the console package (250 MB unzipped), one per
  queue; a batch of jobs runs for at most the worker's `timeout` (up to 900 s)
- Arco deploys HTTP functions, an optional console function for commands and the scheduler, SQS queue
  workers, S3 buckets and DynamoDB tables for the cache and failed jobs; next on the roadmap is
  `arco init` detecting what the app uses and offering each piece

## Upgrading to 0.4

The console function is no longer deployed by default. If an environment already has one (apps deployed
with 0.2 or 0.3) and `arco.php` has no `console()` setting, `arco deploy` stops before changing anything:
add `->console()` to keep it, or `->console(false)` to delete it on the next deploy.

## Packages

| Package | Install as | Purpose |
|---|---|---|
| [`multiapi/arco`](https://github.com/multi-api/arco) | `require-dev` | The CLI |
| [`multiapi/arco-laravel`](https://github.com/multi-api/arco-laravel) | `require` | Laravel preset and service provider (ships with your app) |
| [`multiapi/arco-secrets`](https://github.com/multi-api/arco-secrets) | dependency | `arco-ssm:` / `arco-secret:` reference parsing |

The packages are published from one monorepo. Please open issues in
[`multi-api/arco`](https://github.com/multi-api/arco/issues).

## License

MIT
