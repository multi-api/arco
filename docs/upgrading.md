# Upgrading

Upgrade one minor version at a time when you can, read its section below and the
[changelog](../CHANGELOG.md), then deploy a staging environment before production. Update `multiapi/arco`
(dev dependency) and `multiapi/arco-laravel` (app dependency) together: each Arco 0.x release goes with the
`multiapi/arco-laravel` release of the same minor, and from 1.0 any 1.x of one works with any 1.x of the other.

```bash
composer require --dev multiapi/arco:^0.12
composer require multiapi/arco-laravel:^0.12
vendor/bin/arco info --env=staging     # validation runs here: errors and deprecations name the fix
```

## Upgrading to 0.20.7

Nothing is required. **Redeploy** to give PHP the function's `timeout()` as its `max_execution_time` (until now PHP
stopped every web request at its own 30 s). Two effects: with `timeout()` over 30, long requests, streams and
deferred work now run until `timeout()`; with `timeout()` under 30, deferred work (`defer()`, terminating callbacks)
now stops at `timeout()` instead of 30 s. To keep the old limit, add `phpIni(['max_execution_time' => 30])`.

## Upgrading to 0.20.6

Nothing is required, but the runtime now sends **anonymous invocation counts**, on by default: one small UDP packet
per Lambda environment at most once a minute, with only the runtime, kind, Arco version, region, a count and, on the
first packet, a cold-start flag and a boot-time bucket. Nothing identifies you or your app; everything that is sent
is in [Telemetry](telemetry.md).

- **To opt out**, use any one of these, then deploy: `->telemetry(false)` in `arco.php` (`"telemetry": false` in
  `arco.json` or a config layer); `ARCO_TELEMETRY_DISABLE=1` in the shell that runs `arco deploy` (CI);
  `'ARCO_TELEMETRY_DISABLE' => '1'` in `variables()` (the one `ARCO*` name it accepts, also as a reference or
  bundled). `arco info` shows the setting.
- **Upgrade `multiapi/arco-laravel` together with `multiapi/arco`**: Laravel queue workers are counted by its
  `arco:work` loop, so an older one sends nothing for queues (everything else is still counted).
- Every package hash changes once on the first deploy with this version, because `arco-runtime.json` gains
  `arcoVersion` (and `telemetry: false` when off): that deploy publishes a new version of each function even when
  nothing else changed.
- The first successful `arco deploy` on a machine prints a one-line notice about telemetry, once (remembered in
  `~/.arco/config.php`; with `--json` it goes to stderr).
- **Redeploy environments with `domain()`** to get the fixed viewer-request function: until then a URL with `+` in its
  query string (for example an OAuth provider's error callback) answers 403 before reaching the app. See
  [`+` in query strings](configuration.md#-in-query-strings).

## Upgrading to 0.20.5

Nothing is required: `arco.php`, `arco.json` and every `--json` key keep working. A Go app installs Arco globally, so
it upgrades with `composer global require multiapi/arco:^0.20.5`. What changed since 0.20.0:

- **Redeploy Go apps** (0.20.2): the web function no longer ends with `Runtime.ExitError` when Lambda spins an
  instance down. An environment keeps its old runtime until you run `arco deploy`. PHP apps were not affected.
- `arco logs` no longer prints each web function line twice (0.20.2).
- `arco info --resources --json` gains `schedules`, with `paused` for each one (0.20.3). Keys are only added.
- `arco info --resources --json` gains `scheduler`, `{"every", "paused"}` for the Laravel scheduler or `null`
  (0.20.5), after `schedules`. Keys are only added.
- `config.layer_conflict` and `config.layer_adopt_waiting` gain the param `file` (`arco.php`, or `arco.json` for a
  process app), and their messages name it (0.20.3, 0.20.4). If you match the message text, match the key instead.
- A config layer schedule accepts `adopt` (0.20.4). To move a schedule from `arco.json` to a layer (Arco Cloud does
  this for you), add it to the layer with `"adopt": true` and the same settings, deploy, then remove it from
  `arco.json` and deploy again: the same EventBridge rule is kept, so the schedule never stops running. Without
  `adopt`, a name in both is still `config.layer_conflict`.

## Upgrading to 0.20

Nothing is required for PHP apps: `arco.php`, the Laravel package, the runtime files and every `--json` key behave
as before. The release adds [Go apps](configuration.md#process-runtime-go) (`arco.json`), and with them a few things
to know:

- A project with both `arco.php` and `arco.json` now fails with `config.both_files`. If you keep an unrelated
  `arco.json` at the root of a PHP project, rename it.
- A deploy driven by a config layer alone (no `arco.php`) in a repository that also has a `go.mod` deploys the Go
  app, unless `composer.json` requires both `multiapi/arco` and `multiapi/arco-laravel` (Laravel apps deployed with
  Arco do, so they keep deploying as PHP). A plain PHP app without `multiapi/arco-laravel`, configured by a layer
  alone, with a `go.mod` at its root: add an `arco.php` to keep it PHP.
- `arco info --json` gains `runtime` (`{"kind": "php"}` for PHP apps), `schedules` (`[]` for PHP apps) and
  `route` in each `queues` entry (`null` for PHP apps); `info --resources --json` queue options gain `route`. Keys
  are only added, as always.
- An environment cannot change kind in place: moving an environment from Laravel to Go (or back) needs
  `arco remove --env=<env>` first (`deploy.runtime_kind_changed`).

## Upgrading to 0.19.2 (security)

Upgrade and redeploy every environment. The default runtime build becomes `php8.5.12-fp1.13.5-r1`: FrankenPHP 1.13.5
fixes 17 vulnerabilities ([release notes](https://github.com/php/frankenphp/releases/tag/v1.13.5)). An already
deployed environment keeps its old binary until you run `arco deploy`.

- If `arco.php` pins a build (`$r->build(...)`) or a FrankenPHP version (`$r->frankenphp('1.13.1')`), remove the pin
  or move it to `php8.5.12-fp1.13.5-r1`.
- Worker mode (Octane): between requests FrankenPHP now resets only request state (superglobals except `$_ENV`, the
  body, response headers and output, the session). `error_get_last()`, `libxml_get_errors()`, `strtok()` and
  `http_get_last_response_headers()` keep their value from the previous request: call `error_clear_last()`,
  `libxml_clear_errors()` or `http_clear_last_response_headers()` before reading them if your code relies on a clean
  state.

## Upgrading to 0.19

Nothing is required. With the new `multiapi/arco-laravel`, the JSON log lines of console commands and queue jobs
carry `requestId` and `traceId` in their `context` (jobs also `job`), and web lines gain `traceId`
([Log context](configuration.md#log-context)): a metric filter or query that matches the whole `context` object may
need a look. The access log stays off until you add `->accessLog()` ([Access log](configuration.md#access-log)).

## Upgrading to 0.17

Nothing is required. `multiapi/arco-laravel` now loads Arco Cloud extensions: each `.arco-cloud/<name>/bootstrap.php`
in the deployed app that returns a service provider is registered, and one that fails is logged and skipped. Without
an `.arco-cloud/` directory nothing changes.

## Upgrading to 0.16

Nothing is required. The managed execution role gains a read-only statement on the environment's
[bundled variables](configuration.md#bundled-variables) (`ArcoVariables`), whether or not you use
`bundleVariables()`. With your own `role()`, grant `s3:GetObject` on `variables/<app>/<env>/*` of the code bucket
only if you turn `bundleVariables()` on.

## Upgrading to 0.15

Nothing is required: 0.15 only adds the `grants` relation on the execution role in `arco info --resources --json`
(see [Resource relations](getting-started.md)). It is worked out from `arco.php`, with no new AWS call or permission.
A tool that reads `relations` should ignore a `type` it does not know.

## Upgrading to 0.12.1

Nothing is required: 0.12.1 only adds `endpoints:` to `vpc()`. Without it the deploy makes no new calls and needs no
new permissions.

To use it, add the deploy permissions first (see [Permissions for VPC](getting-started.md#permissions-for-vpc)):
`ec2:DescribeRouteTables`, `ec2:DescribeVpcEndpoints`, `ec2:CreateVpcEndpoint`, `ec2:ModifyVpcEndpoint`,
`ec2:DeleteVpcEndpoints`, `ec2:CreateTags` and `ec2:DeleteTags`. A service that deploys for you (Arco Cloud) needs
them in its connect template. If the VPC already has gateway endpoints of your own, name them in `endpoints:`
(`'s3' => 'vpce-...'`) so a deploy in CI never stops on them; see
[Gateway endpoints](configuration.md#gateway-endpoints).

## Upgrading to 0.12

Nothing is required: 0.12 only adds an opt-in setting, `vpc()`. Without it, the deploy permissions do not
change and no network connector call is made. A deploy and `arco remove` of an existing web function now also read
its endpoint and live revision (`lambda:GetWebFunctionEndpoint`, `lambda:GetWebFunctionRevision`, which the deploy
permissions already include), to find a network connector it may still pin.

To use it:

- **Add the new deploy permissions first** (see [Permissions for VPC](getting-started.md#permissions-for-vpc)):
  `lambda:CreateNetworkConnector`, `lambda:GetNetworkConnector`, `lambda:UpdateNetworkConnector`,
  `lambda:DeleteNetworkConnector`, `lambda:ListNetworkConnectorVersions`, `lambda:PassNetworkConnector` on
  `arn:aws:lambda:<region>:<account>:network-connector:*`; `iam:PassRole` on `arn:aws:iam::<account>:role/arco-<app>-*-network`
  to `lambda.amazonaws.com`; and `ec2:DescribeSubnets`, `ec2:DescribeSecurityGroups`, `ec2:DescribeVpcs`, `ec2:DescribeNetworkInterfaces`, `lambda:ListFunctions`. A service
  that deploys for you (Arco Cloud) needs them in its connect template.
- **The subnets need a NAT** (or endpoints) for the AWS APIs the app calls; see
  [The VPC needs a NAT](configuration.md#the-vpc-needs-a-nat).
- With `role()`, add the network interface permissions (`AWSLambdaVPCAccessExecutionRole`) to your execution role.
- Require `multiapi/arco:^0.12` in every deploy path: an older Arco does not know the connector and would deploy a
  revision outside the VPC.

## Upgrading to 0.11.4 (security)

Upgrade and redeploy every environment that has `domain()`. Before 0.11.4, the Web Function endpoint of such an
environment was public: a request sent straight to its `*.lambda-web.<region>.on.aws` URL skipped CloudFront and
could carry any `X-Forwarded-Host` or `X-Forwarded-For` header, which `arco-laravel` trusts. Environments without
`domain()` are not changed.

- **Add the new deploy permissions first**, or the deploy stops with an access error (see
  [Permissions for custom domains](getting-started.md#permissions-for-custom-domains)):
  `cloudfront:CreateOriginAccessControl`, `cloudfront:GetOriginAccessControl`,
  `cloudfront:UpdateOriginAccessControl`, `cloudfront:DeleteOriginAccessControl`, and
  `lambda:GetResourcePolicy`, `lambda:PutResourcePolicy`, `lambda:DeleteResourcePolicy` on
  `arn:aws:lambda:*:<account>:web-function/<app>-*`. The post-deploy `/up` check is now a signed request, which needs
  `lambda:InvokeWebFunctionEndpoint`: the minimal permissions' `lambda:*WebFunction*` already grants it. If you
  scoped the Lambda writes (the account guard), add the four Lambda actions to `ArcoWebFunctionOfTheApp`. A service
  that deploys for you (Arco Cloud) needs them in its connect template too.
- **Require `multiapi/arco:^0.11.4` in every deploy path** (CI lock files, teammates, Arco Cloud's runner) before you
  rely on the protection. A deploy by 0.11.3 or older drops the origin access control and makes the endpoint public
  again, without a warning.
- **Nothing to change in `arco.php`.** The first deploy updates the domain stack (a CloudFront origin access
  control, which takes a few minutes), puts a statement in the Web Function's resource policy, waits for the
  distribution, and then makes the endpoint `IamAuth`. It then requests the health path through the distribution
  (retrying for about a minute). If CloudFront cannot reach the app, Arco makes the endpoint public again, the site
  keeps serving again, and the deploy fails with `deploy.protection_reverted`; deploy again once the cause is fixed.
  With a correct grant the site stays up during the upgrade. With a wrong one, viewers can get 403 for about 60 to 90
  seconds, until the automatic revert; run the first upgrade under light traffic. If even the revert fails, the
  deploy stops with `deploy.protection_revert_failed` and prints the command to run. To make an endpoint public by
  hand:
  `aws lambda-web update-web-function-endpoint --function-name <app>-<env> --endpoint-name default --auth-type ApplicationManaged`.
- **The raw endpoint URL now answers 403** with `domain()`. Your custom domains are unaffected. If something calls
  the `*.on.aws` URL directly (a health check, a webhook sender), point it at a custom domain.
- `auth('iam')` together with `domain()` is allowed now. With `domain()`, the post-deploy check still probes `/up`,
  signed with the deploy credentials. `auth('iam')` without `domain()` still uses the platform init report.
- Removing `domain()` from an `auth('iam')` environment removes Arco's CloudFront statement too, so the distribution
  left until `arco remove` can no longer reach the app.
- `arco info --json` has the new key `endpoint_auth`.
- Known residual: `request()->ip()` can still be spoofed through CloudFront with an `X-Forwarded-For` header (see
  [Protecting the endpoint](configuration.md#protecting-the-endpoint)).

## Upgrading to 0.11

Nothing is required: 0.11 only adds an opt-in setting. Without a permissions boundary, a deploy makes the same IAM
calls as 0.10.2 and its output, `--json` included, is unchanged, with one exception: a role whose boundary was set
by hand (outside Arco) keeps it, and every deploy now prints the note `deploy.boundary_kept` for it. The deploy
profile needs no new permission.
What is new:

- **`->permissionsBoundary(string|false $policyArn)`** puts an IAM permissions boundary on the managed execution
  role `arco-<app>-<env>`. The deploy host can set or override it without editing `arco.php`:
  `arco deploy --permissions-boundary=<arn>` > `ARCO_PERMISSIONS_BOUNDARY` > `arco.php`. Combining it with
  `role()` is an error. See [Permissions boundary](configuration.md#permissions-boundary).
- With a boundary, the deploy profile also needs `iam:PutRolePermissionsBoundary` on `role/arco-*` (grant it
  together with `iam:CreateRole`), and an account owner can tie role writes to the boundary with the account guard in
  [Enforcing a permissions boundary](getting-started.md#enforcing-a-permissions-boundary). That section also says
  what the guard needs to hold (every existing `arco-*` role bounded, Lambda writes scoped to the app's functions,
  no CloudFormation on other stacks) and has a recommended boundary policy. Before you set a boundary on an existing environment, check that it allows what the
  app's `permissions()` add: the effective permissions are the intersection.
- `arco info` shows a `permissionsBoundary` row (`-  default` when unset), and `arco info --json` has the key
  `permissions_boundary` (string or `null`) after `tables`.
- `deploy --json`: the steps `deploy.boundary_set` and `deploy.boundary_kept` (status `note`), the error
  `deploy.boundary_denied` with params `{role, boundary, action, error}`, and the param `boundary` on
  `deploy.step_role` when a boundary is configured.

## Upgrading to 0.10.1 (security)

Upgrade and redeploy every environment now. PHP 8.5.12 fixes two critical remote code execution vulnerabilities
(CVE-2026-109618, CVE-2026-109619; [php.net advisory](https://www.php.net/archive/2026.php#2026-10-21-5)) that affect
FrankenPHP and long-lived workers such as Octane's. The default runtime build becomes `php8.5.12-fp1.13.1-r1`; an
already deployed environment keeps its old binary until you run `arco deploy`.

- If `arco.php` pins a build (`$r->build(...)`) or a FrankenPHP version (`$r->frankenphp('1.13.0')`), remove the pin
  or move it to `php8.5.12-fp1.13.1-r1`; otherwise the environment stays on PHP 8.5.11.
- Arco now sets `session.upload_progress.enabled=0` and `fatal_error_backtraces=0` by default (php.net's interim
  hardening). If your app needs session upload progress, turn it back on with
  `->runtime(fn (Runtime $r) => $r->phpIni(['session.upload_progress.enabled' => true]))`.

## Upgrading to 0.10

Nothing is required: 0.10 only adds to the API that 0.9 froze. No `arco.php` method, CLI flag, `--json` key,
variable, AWS name or tag changes meaning, and the deploy profile needs no new permission for what you already
use. What is new:

- **Cloudflare DNS**: `->dns('cloudflare')` writes the custom domains' records in Cloudflare with an API token
  from `CLOUDFLARE_API_TOKEN` or `--cloudflare-token` (Zone → DNS → Edit and Zone → Zone → Read; it needs no AWS
  permission). See [Cloudflare](configuration.md#cloudflare). **Check one thing:** `'cloudflare'` is now
  reserved in `dns()`. If you used an AWS profile literally named `cloudflare` for Route 53 (`->dns('cloudflare')`),
  that line now means Cloudflare: rename the profile and write the new name in `dns()` before you deploy.
- **`arco deploy --json` and `arco remove --json`** stream NDJSON step events and a final result line
  ([Deploy and remove with `--json`](getting-started.md#deploy-and-remove-with---json)).
- **`relations`** on each resource of `arco info --resources --json`, and the keys `dns` and `tables` in
  `arco info --json`.
- **`->table('<name>', ttl: ..., existing: ...)`**: DynamoDB tables for your own code, named in `ARCO_TABLES`
  ([Tables](configuration.md#tables)); `table:add` and `table:remove`. A deploy profile that already has the
  table actions of [Permissions for storage and tables](getting-started.md#permissions-for-storage-and-tables)
  on `table/<app>-*` covers them.

## Upgrading to 0.9

0.9 is the API freeze before 1.0: the last release allowed to break `arco.php`, the CLI, the `--json` output
or the AWS names. What it renamed or removed is below; everything it deprecates still works in 0.9, prints one
warning per run naming the replacement, and is removed in 1.0. Fix the warnings now and 1.0 needs no change.

### Before your first 0.9 deploy: add these IAM permissions

**Breaking:** do this first. The 0.9 deploy reads the tags of the resources it updates, and without
`lambda:ListTags` and `events:ListTagsForResource` it stops before changing anything (so does `arco remove`).
Add these to the profile (or role) that deploys, then upgrade; the full list is in
[Minimal IAM permissions](getting-started.md#minimal-iam-permissions).

| Permission | Used by | Without it |
|---|---|---|
| `lambda:ListTags` (web, console and worker functions) | the ownership check | the deploy stops naming it (`--force` skips the check); `remove` deletes nothing |
| `events:ListTagsForResource` (`rule/arco-*-scheduler`) | the ownership check of the scheduler rule | as above |
| `lambda:TagResource`, `events:TagResource`, `iam:TagRole` | tagging resources an older Arco left untagged, and the `arco:region` tag of a role | a warning; the resource stays untagged until a deploy that may tag it |
| `lambda:GetFunctionConfiguration`, `lambda:ListTags` on the console function | `arco command` and `arco tinker` (also for people who only run them) | they refuse to run |
| `logs:CreateLogGroup`, `logs:PutRetentionPolicy` | the 30-day default, on every deploy | a warning after the deploy is live |
| `logs:DeleteRetentionPolicy` | `logRetention(false)` (never expire) on a group that had a retention | a warning after the deploy is live |
| `logs:DescribeLogGroups` | reading the current retention, so only a group whose retention changes gets the call and the deploy line | every deploy sets the retention of every group and prints the line |
| `logs:TagResource` | log group tags | a note; the groups stay untagged |
| `ssm:AddTagsToResource` (`parameter/<app>/*`) | `arco secret:set` tags the parameter | a warning; the value is stored |
| `tag:GetResources` | `arco info --resources`, the list `arco remove` prints | `info --resources` fails; `remove` says it cannot list them |
| `resource-groups:GetGroupQuery`, `CreateGroup`, `Tag`, `GetGroup`, `GetTags`, `UpdateGroupQuery`, `DeleteGroup` | the Resource Group | a note |
| `cloudformation:GetTemplate` (`stack/arco-*-resources/*`) | reporting what a resources-stack update changed | every updated resource is reported as updated |

### `arco.php`: removed (an error now)

An `arco.php` that still calls these stops before anything else runs, with a message naming the replacement.

`maxEnvironments()` was the old name of `maxInstances()`:

```php
// before
$env->maxEnvironments(10)
// 0.9
$env->maxInstances(10)
```

`consoleTimeout()` is an option of `console()`; giving `timeout:` (or `memory:`) also turns the console on:

```php
// before
$app->console()->consoleTimeout(300)
// 0.9
$app->console(timeout: 300)
$app->console(timeout: 300, memory: 4096)     // memory is new: 128 to 10240 MB, default 2048
```

`binary()`'s parameter is now `$frankenphp`, so the named form `binary(path: ...)` fails (the positional form
works as before). The new optional `php:` gives the console function and the workers your own static PHP CLI,
so they no longer download FrankenPHP at cold start:

```php
// before
$r->binary(path: 'build/frankenphp')
// 0.9
$r->binary('build/frankenphp')
$r->binary('build/frankenphp', php: 'build/php')
```

`dns()`'s parameter is now `$dns` (it was `$profile`), so only the named form changes:

```php
// before
$env->dns(profile: 'dns-account')
// 0.9
$env->dns('dns-account')
```

`cache()` and `failedJobsTable()` no longer take `null` (it meant `true`):

```php
// before
$app->cache(null)->failedJobsTable(null)
// 0.9
$app->cache()->failedJobsTable()
```

`variables()` refuses every name starting with `ARCO` (Arco reserves the prefix, see
[Variables Arco sets](configuration.md#variables-arco-sets)). Before, a value of yours replaced Arco's
`ARCO_QUEUES` or `ARCO_STORAGE` map, or reached the runtime's internal variables. Rename your own variables:

```php
// before
->variables(['ARCO_QUEUES' => '{"emails":"https://sqs.us-east-1.amazonaws.com/111111111111/mail"}', 'ARCO_REPORT_EMAIL' => 'ops@example.com'])
// 0.9
->variables(['APP_REPORT_EMAIL' => 'ops@example.com'])
```

### `arco.php`: deprecated (works in 0.9, removed in 1.0)

`queue(concurrency:)` is `queue(maxInstances:)`, the same name `maxInstances()` has for the web function.
Giving both is an error. `arco queue:set` and `queue:add` rewrite the argument when they edit the queue:

```php
// before
->queue('emails', concurrency: 10)
// 0.9
->queue('emails', maxInstances: 10)
```

`workers(min:, max:)` was never a minimum and a maximum of workers: `min` was the Octane worker count and
`max` FrankenPHP's `max_threads`. They are two methods now (defaults 4 and 64, as before). The positional
`workers(4, 64)` is deprecated the same way:

```php
// before
->runtime(fn (Runtime $r) => $r->workers(min: 4, max: 64))
// 0.9
->runtime(fn (Runtime $r) => $r->workers(4)->maxThreads(64))
```

### `arco.php`: changed behaviour

- **PHP version.** `->runtime(fn (Runtime $r) => $r->php('8.5'))` chooses the newest runtime build of a PHP
  minor, and `arco init` writes it. Without `php()`, Arco deploys the newest build of its default minor (8.5),
  and a 1.x release never moves that default to another PHP minor. `frankenphp('1.13')` now matches by prefix
  and combines with `php()`. `build()` is an exact pin and is an error next to `php()` (as it already was next to
  `frankenphp()`). Nothing to change unless you want to pin the minor:

  ```php
  // before: whatever this Arco release ships
  return App::make('shop')->region('us-east-1')
  // 0.9: PHP 8.5.x until you change it
  return App::make('shop')->region('us-east-1')->runtime(fn (Runtime $r) => $r->php('8.5'))
  ```

- **Runtime build ids** are `php<php>-fp<frankenphp>-r<n>`, for example `php8.5.11-fp1.13.0-r2`, in `arco info`,
  the deploy output and new packages. The old ids stay valid aliases forever: `build('1.13.0-r2')` and packages
  built with it deploy the same binaries, so nothing breaks. You may write the new id:

  ```php
  // before (still works)
  $r->build('1.13.0-r2')
  // 0.9
  $r->build('php8.5.11-fp1.13.0-r2')
  ```

  The local cache moves to `~/.arco/cache/runtime/<new id>/`, so each machine downloads the build once more. A
  console that downloads FrankenPHP at cold start (a build without a PHP CLI, such as `1.13.0-r1`) reads it from
  `runtime/<new id>/frankenphp` in the code bucket: the first 0.9 deploy uploads it once more.
- **Storage without a default disk.** When every `storage()` says `default: false`, there is no default storage:
  Arco sets only `ARCO_STORAGE` (no `FILESYSTEM_DISK`, `AWS_BUCKET` or `AWS_URL`) and Laravel keeps its own default
  disk. Before, the first storage was the default anyway. A storage without the option is still the default:

  ```php
  // 0.8: 'backups' became the default disk anyway; 0.9: no default storage, the app's disk stays
  ->storage('backups', default: false)
  ```

- **`cache(driver:)`** is accepted, with `'dynamodb'` as the only value, so another cache backend can come later
  without a rename. Nothing to change.
- **Names.** App and environment names may not end in `-console`, `-resources`, `-scheduler` or `-host`, nor
  contain `-queue-`, and `<app>-<env>` may not name another app's resource (app `shop-eu` with environment
  `console` is the console function of `shop`/`eu`). Validation names the rule; such an app needs another name.
  `<app>-<env>` is at most 56 characters (it always was, through the console function's name).
- **`mode()`** on `Runtime` is internal and out of the docs; `toArray()` on `App`, `Environment` and `Runtime` is
  internal. Do not call them.

### CLI and CI

`protected()` now guards `arco command`, `arco remove` and `arco queue:dlq --redrive`, not only `arco tinker`
(protected by default: environments named `production`). At a terminal they ask; without one they stop and
say to add `--yes`. Deploy commands (`deployCommands()`, `arco deploy --migrate`) are not asked. `--yes` / `-y`
answers every confirmation; `--force` only overrides a safety check (`init`, `deploy`, `deploy --force-dns`).

```bash
# before
vendor/bin/arco command "migrate --force" --env=production --no-interaction
vendor/bin/arco remove --env=production --force --no-interaction
vendor/bin/arco tinker --env=production --force
vendor/bin/arco queue:add emails --env=production --concurrency=10
vendor/bin/arco queue:set emails concurrency 10 --env=production
vendor/bin/arco queue:failed-table --env=production
vendor/bin/arco queue:failed-table --env=production --remove

# 0.9
vendor/bin/arco command "migrate --force" --env=production --no-interaction --yes
vendor/bin/arco remove --env=production --yes --no-interaction
vendor/bin/arco tinker --env=production --yes
vendor/bin/arco queue:add emails --env=production --max-instances=10
vendor/bin/arco queue:set emails max-instances 10 --env=production
vendor/bin/arco failed-jobs:add --env=production
vendor/bin/arco failed-jobs:remove --env=production
```

`remove --force`, `tinker --force`, `queue:add --concurrency`, `queue:set <queue> concurrency` and
`queue:failed-table` still work in 0.9 with a warning, and are removed in 1.0. `arco remove` without a terminal
needs `--yes` on every environment, protected or not.

Also new: `arco info --profile`, and `info` enforces an `account()` lock like `deploy` does (it used to read
whatever account the profile pointed to); `arco info --resources`; `--json` on `queue:list`, `storage:list` and
`profile`; `arco set console-timeout` and `console-memory`.

### `--json`

Every `--json` document (each line of `logs --json`) starts with `"schema": 1`; no key was renamed or removed,
except in `arco info --json`:

- `settings."runtime.workers"` is the Octane worker count as an integer (it was the string `"4-64"`), and the new
  `settings."runtime.maxThreads"` is FrankenPHP's `max_threads`.
- `settings."runtime.build"` shows the new build id.
- `settings.logRetention` is `null` when not set, which now means the 30-day default (it meant "not managed").

### Behaviour in AWS

- **Log retention defaults to 30 days.** An environment without `logRetention()`, new or already deployed, gets
  30 days on its web, console and queue worker log groups on the next deploy, which says so once; before, they
  kept logs forever. Every deploy now calls `logs:CreateLogGroup` and reads the current retention
  (`logs:DescribeLogGroups`); only a group whose retention changes gets `logs:PutRetentionPolicy` and the
  deploy line (without `logs:DescribeLogGroups`, every deploy sets it and prints the line). To keep the old
  behaviour:

  ```php
  // 0.8: no setting = logs never expire; 0.9: no setting = 30 days
  ->logRetention(false)      // never expire
  ```

- **Ownership.** Before a deploy updates, or `arco remove` deletes, the web function, the console function, a
  worker, the execution role or the scheduler rule, Arco reads its `arco:app` and `arco:environment` tags and
  refuses, before any change, one that belongs to another app or environment, or one with Arco's name but no
  tags and no Arco description. Resources an older Arco created carry its description: the first 0.9 deploy
  adopts and tags them. `arco deploy --force` overrides a refusal (only for a resource you know is yours);
  `arco remove` has none. `arco command`, `arco tinker`, `scheduler:pause`/`resume` and `scheduler:set/remove
  --apply` check the same way. See [Names and ownership](configuration.md#names-and-ownership).
- **Region guard.** New execution roles are tagged `arco:region`, and `arco deploy` refuses, before any change and
  even with `--force`, when an environment's role was created for another region than `region()`; `arco remove`
  refuses the same way before deleting anything (the role and CloudFront still serve the function in the old
  region). An app lives in one region; for a second region use another environment name. Roles from an older Arco get the tag on the
  next deploy.
- **Tags and a Resource Group.** Log groups and the SSM parameters `arco secret:set` writes are tagged too, and each
  environment gets a Resource Group `arco-<app>-<env>`, which `arco remove` deletes last.
- **`updated`.** A deploy reports a bucket, table or queue the resources stack changed as `updated` instead of
  `up to date`.

## Earlier versions

Only what an upgrade must act on; the [changelog](../CHANGELOG.md) has everything.

### 0.8

- Use 0.8.1: the `v0.8.0` tag points at the 0.7.0 code.
- The package upload sets the ACL `bucket-owner-full-control` instead of `private`.
- `AWS_ENDPOINT_URL` now sends Arco to a local emulator. Unset it in shells you deploy from (`arco deploy`
  prints a note naming it when it is set).

### 0.7

- The managed role's `ArcoTables` statement no longer grants `dynamodb:Scan`, and `ArcoStorage` adds
  `s3:AbortMultipartUpload`. With your own `role()`, make the same change.
- `arco init` writes nothing when the `arco.php` it would write does not validate.

### 0.6

- Queues (and the new buckets and tables) use `DeletionPolicy: RetainExceptOnCreate`: CloudFormation now needs
  `sqs:DeleteQueue` to roll back a deploy that created a queue. Existing resources stacks switch on their next
  deploy.
- The error key `deploy.queues_need_packages` is now `deploy.needs_packages`.
- With your own `role()`, storage and tables need the `ArcoStorage`, `ArcoStorageList` and `ArcoTables`
  statements ([Storage permissions](configuration.md#storage-permissions),
  [Table permissions](configuration.md#table-permissions)).

### 0.5

- `arco logs` without `--source`, and `logRetention()`, include the queue workers' log groups.
- With queues, the console package is built even when the console is off, and must fit 250 MB.
- Every deploy reads the resources stack (`cloudformation:DescribeStacks`) and looks for workers of removed
  queues (`tag:GetResources`).

### 0.4

The console function is opt-in: a deploy creates only the web function unless `arco.php` says `->console()` (or
a scheduler needs it). If an environment already has a console function (apps deployed with 0.2 or 0.3) and
`arco.php` has no `console()` setting, `arco deploy` stops before changing anything:

```php
->console()          // keep it
->console(false)     // delete it on the next deploy
```

`arco console:add` and `arco console:remove` write those lines. The scheduler and log retention need new
deploy-profile permissions ([Minimal IAM permissions](getting-started.md#minimal-iam-permissions)).

### 0.3

The default runtime build became `1.13.0-r2` (the console function carries a static PHP CLI). A pinned
`build('1.13.0-r1')` keeps working, with the console downloading FrankenPHP at cold start.

### 0.2

- Every deploy also deployed a console function (opt-in since 0.4), so the deploy profile needs the console
  function's Lambda permissions and `logs:StartLiveTail` for `arco logs --follow`.
- Packages built by `arco package` before 0.2 must be built again.

### 0.1.3

- `concurrency()` has no default any more (it was 16): Lambda uses its own (64). Set `concurrency(16)` to keep the
  old value.
- `maxInstances()` must be 2 to 10000 and `rateLimit()` 100 to 1000 in steps of 100, or 2000 to 10000 in steps of
  1000.
- Resources are tagged only `arco:app` and `arco:environment` (the `arco:version` tag is gone).

## The 1.x promise

From 1.0, a 1.x release does not break what is listed as API below; a change to it waits for 2.0. Additions
(new methods, named arguments, commands, flags, JSON keys, variables, tags) can come in any minor release.
Deprecations print a warning naming the replacement and keep working until 2.0.

**API (stable in 1.x):**

- **`arco.php`.** The methods of `App`, `Environment` and `Runtime` documented in the
  [configuration reference](configuration.md), with their defaults and merge rules. Named arguments are covered
  where the docs use them: `queue()`, `storage()`, `table()` (`ttl:`, `existing:`), `cache()`,
  `failedJobsTable()`, `console()`, and `Runtime`'s `workers()` and `binary()`; for other methods, only the
  positional form (see [Named arguments](configuration.md#named-arguments)). `dns('cloudflare')` means Cloudflare
  (the name is reserved in `dns()`), with the token read from `CLOUDFLARE_API_TOKEN` or `--cloudflare-token`, the
  `proxied: false` records and the ownership comments `managed by arco: <app>/<env>` and `managed by arco`
  described in [Cloudflare](configuration.md#cloudflare). `permissionsBoundary()` (0.11) with its precedence
  (`--permissions-boundary` > `ARCO_PERMISSIONS_BOUNDARY` > `arco.php`), its error with `role()`, and the rule that
  Arco never removes a boundary from a role.
- **The CLI.** Every command in `arco list`, its arguments and its documented options (`--permissions-boundary` of
  `deploy` and `info` among them), including
  `arco command "<one string>"` as the form of `arco command`.
- **`--json` and exit codes.** The documents of `info`, `info --resources`, `logs`, `queue:list`, `storage:list`
  and `profile`, versioned by `"schema": 1`: keys may be added, never renamed, removed or retyped within a schema.
  This includes the 0.10 keys `dns` and `tables` of `info --json` and `relations` of `info --resources --json`
  (with its `type` values `worker_of`, `dlq_of`, `invokes`, `serves` and `validates`), and the 0.11 key
  `permissions_boundary` of `info --json`.
  The NDJSON of `deploy --json` and `remove --json` is API the same way: its line types (`step`, `progress`,
  `result`), their keys, the `status` and `outcome` values, the progress keys, and each step's and error's `key`
  with its `params` (a key keeps its meaning; params may gain entries), including the 0.11 keys
  `deploy.boundary_set` and `deploy.boundary_kept` (`{role, boundary}`), `deploy.boundary_denied`
  (`{role, boundary, action, error}`) and the `boundary` param of `deploy.step_role`. Not covered: the `message`
  texts and which steps a run prints in what order.
  Exit codes: 0 success, 1 failure, and the command's own code for `arco command` and a failing deploy command.
- **Variables.** The ones Arco sets that the [variables table](configuration.md#variables-arco-sets) marks
  stable (`ARCO`, `ARCO_APP`, `ARCO_ENV`, `ARCO_BUILD`, `ARCO_TABLES`, and for `multiapi/arco-laravel`
  `ARCO_QUEUES`, `ARCO_STORAGE`, `ARCO_CACHE_TABLE`, `ARCO_FAILED_JOBS_TABLE`), the Laravel variables it sets per
  feature, the reservation of every `ARCO*` name, and the variables Arco reads on your machine (`ARCO_LANG`,
  `AWS_ENDPOINT_URL`, `AWS_PROFILE`, `CLOUDFLARE_API_TOKEN`, `ARCO_PERMISSIONS_BOUNDARY`).
- **AWS names and tags.** The web function `<app>-<env>`, the console function `<app>-<env>-console`, the
  workers `<app>-<env>-queue-<queue>`, the execution role `arco-<app>-<env>`, the scheduler rule
  `arco-<app>-<env>-scheduler`, the stacks `arco-<app>-<env>` and `arco-<app>-<env>-resources`, the CloudFront
  function `arco-<app>-<env>-host`, the Resource Group `arco-<app>-<env>`, queues `<app>-<env>-<queue>` and
  `<app>-<env>-<queue>-dlq` (`.fifo` for FIFO), buckets `<app>-<env>-<storage>-<account>`, tables
  `<app>-<env>-cache`, `<app>-<env>-failed-jobs` and `<app>-<env>-<table>` (with keys `PK` and `SK`), the code bucket `arco-<account>-<region>`, the log groups
  `/aws/lambda/web/<app>-<env>`, `/aws/lambda/<app>-<env>-console` and `/aws/lambda/<app>-<env>-queue-<queue>`,
  and the tags `arco:app`, `arco:environment`, `arco:region` (execution roles), `arco:resource` (resources-stack
  entries) and `arco:managed` (certificates Arco requested). Renaming any of them would orphan your resources.
- **Secrets.** `arco secret:set` writes `/<app>/<env>/<name>` in SSM Parameter Store, and the `arco-ssm:` and
  `arco-secret:` reference formats.
- **One region per app** for all of 1.x.
- **The default runtime build** moves only within its PHP minor; a new minor is opt-in with `php()`. Build ids,
  including the aliases `1.13.0-r1` and `1.13.0-r2`, stay valid.
- **`multiapi/arco` 1.x works with every `multiapi/arco-laravel` 1.x.**

**Not API (may change in any release):**

- Message texts, in any language: what the CLI prints, the warnings, the error wording and their translation
  keys (except the `key` of `deploy --json` and `remove --json` lines, above). Script against `--json` and exit
  codes instead.
- Classes and methods marked `@internal`: `toArray()`, `Runtime::mode()`, and everything outside `arco.php`'s
  fluent API.
- Framework presets: the interfaces (`FrameworkPreset`, `CachesPaths`, `RunsCommands`, `PresetRegistry`) are
  internal; 1.x supports only the first-party presets (Laravel and plain PHP).
- The runtime's internal variables (`ARCO_WORKER_QUEUE`, `ARCO_BINARY_*`, `ARCO_PHP_BINARY`, `ARCO_TMP` and the
  others the variables table marks internal), the contents of the package zip and of `arco-package.json`, the
  local cache under `~/.arco/` and `.arco/local.php`.
- What a deploy prints and its order of steps, timings and the IAM actions it needs (new features can need new
  ones, always documented in the changelog), and the Cloudflare token permissions a new feature may need (also
  in the changelog).
