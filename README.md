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
- **Operations.** `arco command`, `arco tinker` and `arco logs` run and inspect the deployed app from your
  terminal; migrations can run on every deploy before traffic moves.
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
| `consoleTimeout(int)` | 900 | Seconds a console command may run (3–900) |
| `protected()` | `production` | Ask before `arco tinker` on this environment |

Every option, the packaging rules and the Laravel preset are in the
[configuration reference](docs/configuration.md).

## Commands

| Command | What it does |
|---|---|
| `arco init` | Create `arco.php` (asks name, region, environments, custom domain) |
| `arco deploy --env=<env>` | Build, upload, deploy commands, deploy, verify, attach domains; `--migrate` runs migrations first; `--package=<zip>` deploys a prebuilt zip |
| `arco command "<args>" --env=<env>` | Run an artisan (or PHP) command on the console function; exits with its exit code (`--async` to start it and return) |
| `arco tinker --env=<env>` | Tinker on your machine, statements run in Lambda (`--execute="<code>"` for one statement) |
| `arco logs --env=<env>` | Web and console logs merged, filtered and colored (`--follow`, `--since`, `--level`, `--request`, `--filter`) |
| `arco package --env=<env>` | Build the zip only (for CI: build once, deploy later) |
| `arco info --env=<env>` | Resolved settings with their source, URL, active revision (`--json` too) |
| `arco secret:set <name> --env=<env>` | Store a secret in SSM and print the reference to use |
| `arco set <key> <value> --env=<env>` / `arco unset <key>` | Change `concurrency`, `max-instances`, `rate-limit`, `timeout`, `log-level` in `arco.php` |
| `arco domain:add <host> --env=<env>` / `domain:remove` | Change an environment's domains in `arco.php` |
| `arco profile --env=<env>` | Show or change the AWS profile remembered for an environment |
| `arco remove --env=<env>` | Delete the function, its endpoint, role and domain resources |

Messages are in English or Spanish (`--lang=es` or `ARCO_LANG=es`).

## Documentation

- [Getting started](docs/getting-started.md)
- [Configuration reference](docs/configuration.md)

## Limits (of Lambda Web Functions)

- 2 vCPU and 2 GB per instance, 512 MB unzipped package, 512 MB `/tmp`, arm64 only
- Regions: us-east-1, us-east-2, us-west-2, ap-northeast-1, eu-west-1
- The console function (commands, tinker) is a classic Lambda function: 250 MB unzipped package, no
  FrankenPHP binary inside (it is downloaded once per new instance)
- Arco deploys HTTP functions and a console function for commands; queues and the scheduler are on the roadmap

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
