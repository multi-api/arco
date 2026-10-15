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
init report in CloudWatch Logs. Arco searches the most recently written log streams and waits up
to about 30 seconds (and at most 40 CloudWatch Logs calls) for that report; if it has not arrived
by then, or CloudWatch Logs cannot be read (for example when throttled), the summary says
`Cold start: not available yet` and the deploy still succeeds. With `->auth('iam')` the report is
the only health signal, so a missing or unreadable report fails verification, with a message that
says which of the two happened.

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

`remove` deletes the function and endpoint and the execution role, and with custom domains the
CloudFront stack, its DNS aliases and a certificate Arco requested. The shared code bucket and
your SSM parameters are kept.

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
- `logs:DescribeLogStreams`, `logs:FilterLogEvents`, `logs:GetLogEvents`
- `sts:GetCallerIdentity`

For your own apps, scope the SSM resource to your parameters and add Secrets Manager read access
if you use `arco-secret:` references. If a permission is missing, Arco reports which operation
AWS denied.

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
