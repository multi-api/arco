# Upgrading

Upgrade one minor version at a time when you can, read its section below and the
[changelog](../CHANGELOG.md), then deploy a staging environment before production. Update `multiapi/arco`
(dev dependency) and `multiapi/arco-laravel` (app dependency) together: each Arco 0.x release goes with the
`multiapi/arco-laravel` release of the same minor, and from 1.0 any 1.x of one works with any 1.x of the other.

```bash
composer require --dev multiapi/arco:^0.9
composer require multiapi/arco-laravel:^0.9
vendor/bin/arco info --env=staging     # validation runs here: errors and deprecations name the fix
```

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
  where the docs use them: `queue()`, `storage()`, `cache()`, `failedJobsTable()`, `console()`, and `Runtime`'s
  `workers()` and `binary()`; for other methods, only the positional form (see
  [Named arguments](configuration.md#named-arguments)).
- **The CLI.** Every command in `arco list`, its arguments and its documented options, including
  `arco command "<one string>"` as the form of `arco command`.
- **`--json` and exit codes.** The documents of `info`, `info --resources`, `logs`, `queue:list`, `storage:list`
  and `profile`, versioned by `"schema": 1`: keys may be added, never renamed, removed or retyped within a schema.
  Exit codes: 0 success, 1 failure, and the command's own code for `arco command` and a failing deploy command.
- **Variables.** The ones Arco sets that the [variables table](configuration.md#variables-arco-sets) marks
  stable (`ARCO`, `ARCO_APP`, `ARCO_ENV`, `ARCO_BUILD`, and for `multiapi/arco-laravel` `ARCO_QUEUES`,
  `ARCO_STORAGE`, `ARCO_CACHE_TABLE`, `ARCO_FAILED_JOBS_TABLE`), the Laravel variables it sets per feature, the
  reservation of every `ARCO*` name, and the variables Arco reads on your machine (`ARCO_LANG`,
  `AWS_ENDPOINT_URL`, `AWS_PROFILE`).
- **AWS names and tags.** The web function `<app>-<env>`, the console function `<app>-<env>-console`, the
  workers `<app>-<env>-queue-<queue>`, the execution role `arco-<app>-<env>`, the scheduler rule
  `arco-<app>-<env>-scheduler`, the stacks `arco-<app>-<env>` and `arco-<app>-<env>-resources`, the CloudFront
  function `arco-<app>-<env>-host`, the Resource Group `arco-<app>-<env>`, queues `<app>-<env>-<queue>` and
  `<app>-<env>-<queue>-dlq` (`.fifo` for FIFO), buckets `<app>-<env>-<storage>-<account>`, tables
  `<app>-<env>-cache` and `<app>-<env>-failed-jobs`, the code bucket `arco-<account>-<region>`, the log groups
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
  keys. Script against `--json` and exit codes instead.
- Classes and methods marked `@internal`: `toArray()`, `Runtime::mode()`, and everything outside `arco.php`'s
  fluent API.
- Framework presets: the interfaces (`FrameworkPreset`, `CachesPaths`, `RunsCommands`, `PresetRegistry`) are
  internal; 1.x supports only the first-party presets (Laravel and plain PHP).
- The runtime's internal variables (`ARCO_WORKER_QUEUE`, `ARCO_BINARY_*`, `ARCO_PHP_BINARY`, `ARCO_TMP` and the
  others the variables table marks internal), the contents of the package zip and of `arco-package.json`, the
  local cache under `~/.arco/` and `.arco/local.php`.
- What a deploy prints and its order of steps, timings and the IAM actions it needs (new features can need new
  ones, always documented in the changelog).
