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

## `arco init`

```bash
vendor/bin/arco init
```

`init` detects the framework, asks for the app name and the AWS region (or take them from
`--name`, `--region`), and writes `arco.php` with one environment (`production` by default; pass
`--env` several times for more, for example `--env=staging --env=production`). It also adds
`.arco/` to `.gitignore`. Use `--force` to overwrite an existing `arco.php`. When it finishes it
prints the `arco secret:set` commands to run and the deploy command.

For every secret-like key in `.env` (`APP_KEY`, `*_KEY`, `*_SECRET`, `*_PASSWORD`, `*_TOKEN`),
`init` writes an `arco-ssm:` reference into `arco.php`. It skips keys whose value is empty, `null`
or `""` (nothing to store) and all `AWS_*` keys (Lambda reserves them, and the execution role
replaces static credentials).

Supported regions: us-east-1, us-east-2, us-west-2, ap-northeast-1, eu-west-1.

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
runtime settings in arco.php (mode, threads, workers, `phpIni`) changed since.

Environment variables are not in the zip: they belong to the revision and are read from the
current `arco.php` at deploy time, so changing a variable does not require rebuilding.

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
`log-level`. See [Changing settings from the CLI](configuration.md#changing-settings-from-the-cli).

## Inspect and remove

```bash
vendor/bin/arco info --env=production          # resolved settings and their source, URL, active revision
vendor/bin/arco info --env=production --json   # machine-readable output
vendor/bin/arco remove --env=production        # asks you to type the environment name
```

`remove` deletes the function, the console function and the endpoint and the execution role, and with custom domains the
CloudFront stack, its DNS aliases and a certificate Arco requested. The shared code bucket and
your SSM parameters are kept.

## Operating the app

Every `arco deploy` also deploys a **console function**, `<app>-<env>-console`: a regular Lambda function
with the same code, variables, secrets and role as the web function, used to run commands.

### Commands

```bash
vendor/bin/arco command "migrate:status" --env=production
vendor/bin/arco command "cache:clear" --env=production
vendor/bin/arco command "reports:build --month=10" --env=production --async   # returns at once
```

The whole command goes in one quoted string (it is split into arguments without a shell, so pipes
and redirects do not work). Arco prints the output and exits with the command's exit code, so it
works in scripts and CI. A command can run up to `consoleTimeout()` (15 minutes by default);
`--timeout=<s>` lowers it. Use `--async` for longer work and follow it with
`arco logs --source=console --follow`. For a plain PHP app, name the script: `arco command "bin/console app:sync"`.
The console function must exist: run `arco deploy` first.

The console function has no FrankenPHP binary in its zip (Lambda allows 250 MB for classic functions).
Each new execution environment downloads it once from the code bucket (`runtime/...`) and verifies its
SHA-256. Measured in the Lambda image: about 2.5 s for a 160 MB binary on a cold start, plus about 0.4 s
per command on a warm one.

### Migrations on deploy

```php
->deployCommands(['migrate --force'])   // app or environment level
```

or `arco deploy --migrate` for one deploy (Laravel only; `--no-deploy-commands` skips them, and the two
flags cannot be combined). `arco init` proposes `migrate --force` for Laravel apps with a server database.

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

If the console package is over 250 MB unzipped, the console function is skipped with a warning and
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
ask for confirmation first; `--force` skips it (it is required without a terminal). Every statement is
written to the console function's log, as an audit trail (with its SHA-256 and length, the code cut at
4000 bytes); results and variables are not, since they can hold secrets. `--execute` runs one statement and exits with
1 if it throws.

### Logs

```bash
vendor/bin/arco logs --env=production                         # last 10 minutes, web and console
vendor/bin/arco logs --env=production --follow
vendor/bin/arco logs --env=production --since=2h --level=error
vendor/bin/arco logs --env=production --request=<request id> -v
```

Laravel log lines show as `HH:MM:SS LEVEL message [requestId=...]`; `-v` adds the context and stack
traces, `--platform` shows Lambda's own events, `--json` prints raw events, `--source=all|web|console`
(default `all`) picks the function, `--filter="text"` searches on CloudWatch's side and `--level` sets a
minimum level.

`--since` and `--until` take `30s`, `10m`, `2h`, `1d` or a date (`"2026-10-15 14:00"`); bare numbers are
rejected. `--since` defaults to `10m`. `--until` cannot be combined with `--follow`. When more than
10,000 events match, the earliest 10,000 are shown with a notice: narrow the range.

`--follow` uses CloudWatch Live Tail (about one second of delay). If Live Tail is denied
(`logs:StartLiveTail`) or unreachable, or PHP's `allow_url_fopen` is off, Arco says so and polls every
2 seconds instead; CloudWatch Logs receives events 10-40 s after they happen, so expect that delay.
Live Tail does not backfill, and the session needs a few seconds after it starts before new events
stream: events written between the history read and that moment can be missed. An event that appears
in both the history and Live Tail is printed once. Ctrl+C ends `--follow` cleanly; a second Ctrl+C exits at once.

## Using Arco in CI

Provide AWS credentials through the environment (for example with
`aws-actions/configure-aws-credentials`) and make every command non-interactive:

```bash
vendor/bin/arco secret:set app-key --env=production --no-interaction < key.txt
vendor/bin/arco deploy --env=production --yes --no-interaction
vendor/bin/arco remove --env=production --force --no-interaction
```

`--yes` confirms creating a new environment; `--force` skips the typed confirmation of `remove`.
Arco's own end-to-end check, `.github/workflows/e2e.yml`, deploys `examples/laravel` to a real
account this way. It authenticates with GitHub OIDC and the role in the repository secret
`ARCO_E2E_ROLE_ARN`.

### Minimal IAM permissions

The role used by the E2E workflow lives in a dedicated test account and needs:

- `lambda:*WebFunction*`
- `iam:GetRole`, `iam:CreateRole`, `iam:PutRolePolicy`, `iam:DeleteRolePolicy`, `iam:DeleteRole`,
  `iam:PassRole` on `arn:aws:iam::*:role/arco-*`
- `s3:*` on `arn:aws:s3:::arco-*` (and its objects)
- `ssm:PutParameter`, `ssm:GetParameters` on `parameter/arco-example/*`
- `logs:FilterLogEvents`, `logs:GetLogEvents`
- `sts:GetCallerIdentity`
- for the console function (`arn:aws:lambda:*:*:function:*-console` and `...:*-console:*`):
  `lambda:CreateFunction`, `lambda:UpdateFunctionCode`, `lambda:UpdateFunctionConfiguration`,
  `lambda:GetFunctionConfiguration`, `lambda:PublishVersion`, `lambda:CreateAlias`,
  `lambda:UpdateAlias`, `lambda:GetAlias`, `lambda:PutFunctionEventInvokeConfig`, `lambda:TagResource`,
  `lambda:ListVersionsByFunction`, `lambda:DeleteFunction`, `lambda:InvokeFunction`
- `logs:StartLiveTail` for `arco logs --follow` (Live Tail; without
  `logs:StartLiveTail` follow polls instead). `logs:FilterLogEvents` covers `arco logs` without `--follow`

For your own apps, scope the SSM resource to your parameters and add Secrets Manager read access
if you use `arco-secret:` references. If a permission is missing, Arco reports which operation
AWS denied. The deploy keeps the live console version plus the 4 newest and deletes older ones on a best-effort
basis: without `lambda:ListVersionsByFunction` or `lambda:DeleteFunction` that step becomes a warning.

People who only run `arco command`, `arco tinker` or `arco logs` need `lambda:InvokeFunction` on the console
function (`arco logs` needs the `logs:` actions above). The function's execution role is separate: for the managed role Arco
adds `logs:CreateLogGroup`, `logs:CreateLogStream` and `logs:PutLogEvents` on the web and console log
groups, `s3:GetObject` on `runtime/*` of the code bucket, and read access to the
secrets you reference.

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
