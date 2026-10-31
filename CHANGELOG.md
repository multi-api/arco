# Changelog

All notable changes to Arco are documented here. Arco follows [semantic versioning](https://semver.org);
while it is 0.x, minor versions may contain breaking changes, always listed under **Changed**.

## 0.15.0

- **Added:** the execution role in `arco info --resources --json` has a `grants` relation to each queue, bucket and
  table its policy gives access to ([Resource relations](docs/getting-started.md)), worked out from `arco.php` like the
  other relations, with no extra AWS call or permission.

## 0.14.2

- **Fixed:** a queue job that failed for good was deleted even when the app's failed-jobs store could not take it
  (an app whose `failed_jobs` is a SQLite file, which does not exist on Lambda), so it was lost without a trace. Its
  message now comes back and ends in the dead-letter queue; the worker logs it with status `failed_unstored`.

## 0.14.1

- **Fixed:** `arco resources` printed the account, profile, region and environment inside the confirmation, which
  a narrow terminal cut off (the region came last). They are now a line of their own above a short question.
- **Fixed:** the `arco resources` picker's rows carried every detail and warning, which a narrow terminal cut off
  (the in-use mark and the warnings came last). Each row is now short (name, marks, the number of warnings, the id);
  every resource is printed in full above its checklist, warnings included.

## 0.14.0

- **Added:** `Inventory`, `ResourceItem` and `ResourceType` (`Multiapi\Arco\Resources`) are public API, for tools
  that list existing resources in-process instead of running `arco resources --json`
  ([Listing existing resources in-process](docs/config-layer.md#listing-existing-resources-in-process)). The config
  layer docs now list `resources` among the commands that read the layer.
- **Added:** `arco resources` lists the AWS resources an environment could use (subnets, security groups, gateway
  endpoints, buckets, queues, tables, certificates and Route 53 zones) and the command that adds each one to
  `arco.php`. It only reads (`List*`, `Describe*`, `Get*`); the only file it writes is `arco.php`, through the editing
  commands. In a terminal it is a picker that confirms the account, profile and region first. `--json` (schema 1) is
  the document for tools and agents: each item has `inUse`, `hidden`, `warnings` and `apply`, an argv `{command, args}`
  (`null` for zones; tables have one per use: `generic`, `cache`, `failedJobs`), and the top-level `vpc` is the
  environment's resolved `vpc()` (`{subnets, securityGroups, endpoints: [{service, id}], source}`, `false` for
  `vpc(false)`, `null` for none), the base of the one `vpc:set` a caller builds (an endpoint with a `null` id is the
  one Arco manages, which no item lists). Options: `--env`, `--profile`,
  `--region`, `--dns-profile`, `--type` (repeatable), `--all` (also Arco's own resources, other regions and default-VPC
  items), `--json`, `--config-layer`. A missing permission degrades only that type
  (`{"ok": false, "error": {...}}`, exit 0). Needs new, optional permissions
  ([Permissions for `arco resources`](docs/getting-started.md#permissions-for-arco-resources)); nothing else changes.
- **Added:** `arco vpc:set --env=<env> --subnet=<id> --security-group=<id> --endpoint=<s3|dynamodb|s3=vpce-...>`
  and `arco vpc:remove` edit `vpc()` in `arco.php`. `vpc:set` sets the **whole** list (it does not merge with what is
  there). With working credentials it checks the subnets (not found, more than one VPC) and prints the egress warnings.
- **Added:** `arco certificate:set <arn> --env=<env>` and `arco certificate:remove` edit `certificate()` in
  `arco.php`. The ARN must be an ACM certificate in `us-east-1`; with working credentials it warns when the certificate
  is not issued, expiring, expired, not covering the environment's domains or not found.
- **Added:** new dependency `laravel/prompts` `^0.3.25` (the picker). It brings `ext-mbstring` and conflicts with
  `laravel/framework` `>=10.17.0 <10.25.0`. Without a terminal, or if prompts cannot run, `arco resources` prints the
  plain list instead.
- **Added:** message keys `resources.*`, `vpc.set`, `vpc.replaced`, `vpc.removed`, `vpc.unchanged`,
  `vpc.mixed_vpcs`, `vpc.subnet_not_found`, `certificate.set`, `certificate.replaced`, `certificate.removed`,
  `certificate.unchanged`, `certificate.not_acm` and `certificate.wrong_region`. The `warnings` of `arco resources
  --json` use the keys `resources.warning.subnet_public`, `subnet_no_egress`, `subnet_few_ips`, `sg_no_egress`,
  `bucket_other_region`, `bucket_region_unknown`, `queue_is_dlq`, `table_schema`, `endpoint_missing_routes`,
  `zone_private`,
  `certificate_not_issued`, `certificate_expired`, `certificate_expiring`, `certificate_expiring_one_day`,
  `certificate_not_covering` and `certificate_not_found`.

## 0.13.0

- **Added:** the config layer, a JSON document merged over `arco.php` (or used alone when there is no `arco.php`),
  for tools that manage queues, buckets, tables, variables and domains outside your code. `--config-layer=<path>` and
  `ARCO_CONFIG_LAYER` on `deploy`, `package`, `info`, `remove` and `logs` (the flag wins; an empty or blank value falls
  back to the variable; every other command ignores both). Schema 1, strict: unknown keys and wrong types are refused. The
  layer never overrides `arco.php`: a different value is an error before any AWS call, every conflict listed. New
  keys: errors `config.layer_not_found` (`{path}`), `config.layer_schema` (`{schema}`), `config.layer_invalid`
  (`{path, reason}`), `config.layer_missing` (`{key}`), `config.layer_environment` (`{layer, environment}`) and
  `config.layer_conflict` (`{kind, name}`, `kind` being `name`, `region`, `setting`, `variable`, `queue`, `storage`
  or `table`); the note `config.layer_adopt_waiting` (`{kind, name}`). `arco info` shows `cloud` as the source of
  what the layer set ([Config layer](docs/config-layer.md)).
- **Added:** the `error` object of `--json` and NDJSON results gains `params` when the error key has params (today
  `config.layer_conflict`: `{kind, name}`). Errors without params are unchanged.
- **Added:** `adopt: true` on a config layer queue, storage or table takes back a retained resource of this app and
  environment: it waits (`config.layer_adopt_waiting`) while `arco.php` declares the name; otherwise a retained,
  correctly tagged (`arco:app`, `arco:environment`) queue, bucket or table is imported into the resources stack with
  a CloudFormation `IMPORT` change set, after checks that run before any change. New steps `deploy.step_import`
  (`{stack, count}`; `deploy.step_import_one` with the same params for one resource), `deploy.queue_adopted`,
  `deploy.queue_dlq_adopted` (`{queue, name}`, an imported dead-letter queue, after its queue's step),
  `deploy.storage_adopted` and `deploy.generic_table_adopted`; new error
  `deploy.import_failed` (`{stack, reason}`). New deploy permissions: `cloudformation:CreateChangeSet`,
  `DescribeChangeSet`, `ExecuteChangeSet`, `DeleteChangeSet` on `stack/arco-*` (and the tag and describe reads in
  [Permissions](docs/getting-started.md#permissions-for-queues)); nothing is needed without `adopt`. A resources
  stack left in `REVIEW_IN_PROGRESS` by an interrupted import is deleted and created again by the next deploy, after
  the ownership check (it holds no resources).
- **Added:** `arco info --resources --json` gives every resource `source` (`arco.php`, `cloud`, `default` or `null`)
  and `options` (the resolved options of its queue, bucket, table or setting, `null` when none), and gains the top-level
  keys `variables` (`[{name, source}]`, never values) and `config` (`[{kind, name, source, options}]` for everything
  the configuration declares, deployed or not). They are present with or without a layer.
## 0.12.1

- **Added:** the package uploads to S3 show their progress: a bar with the megabytes sent (`48 / 180 MB`) in a
  terminal, `25% (45 / 180 MB)` lines in CI logs; sizes under 10 MB have one decimal. The console package and its
  runtime binary, whose upload used to print nothing for minutes, upload under their own step,
  `deploy.step_upload_console` (`{size, mb}`, both files counted, one progress run), before `deploy.step_console`,
  which now covers only the console's Lambda update. `deploy.step_upload` gains the param `mb` (the size as printed,
  e.g. `"4.2"`; `size` stays whole MB). Both are now the zip that is sent, not the unzipped package size that
  `package.ready` prints, so the step and its bar agree. In CI logs a jump (a runtime binary already in the bucket
  counts as sent at once) prints only the mark it reached. `deploy --json` streams the upload progress under the new
  progress key `deploy.upload`.
- **Added:** a step that waits on AWS (the network connector, the first VPC console update, the VPC network
  interfaces of `remove`, CloudFront and certificates) shows its elapsed time after 10 seconds: a line redrawn in
  place in a terminal (`3 min 05 s elapsed`), one line per minute in CI logs (`3 min elapsed`). `--json` streams
  nothing new: the elapsed time follows from the step's `at`.
- **Added:** `->vpc(subnets: [...], securityGroups: [...], endpoints: [...])` makes sure S3 and/or DynamoDB go through
  free gateway endpoints, so a VPC without a NAT still reaches the code bucket, `storage()`, `cache()`, `table()` and
  the failed-jobs table. `endpoints: ['s3', 'dynamodb']` creates what is missing, reuses what exists and never deletes
  an endpoint it did not create; `'s3' => 'vpce-...'` names your own endpoint and lets Arco add the missing route
  tables to it. When the VPC has only endpoints of yours that miss route tables, a terminal asks what to do; in CI,
  with `--json` or with `--yes` the deploy fails with `deploy.endpoint_routes_missing` (it never continues silently).
  Arco's endpoints are tagged and shared by the environments of the VPC (`arco:uses:<app>/<env>`) and deleted once
  the last one leaves; route tables are never removed. New steps: `deploy.step_endpoints`, `deploy.endpoint_created`,
  `deploy.endpoint_routes_added`, `deploy.endpoint_reused`, `deploy.endpoint_shared`, `deploy.endpoint_released`, `deploy.endpoint_deleted`
  (and `remove.endpoint_released`, `_deleted`, `_cleanup_failed`); `deploy.endpoint_skipped`,
  `deploy.endpoint_cleanup_failed` and `deploy.endpoint_record_failed` (warnings); errors `deploy.endpoint_not_found`,
  `_wrong_type`, `_wrong_service`, `_wrong_vpc`, `_vpc_mixed`, `_routes_missing`, `_conflict`, `_tag_limit`, `_timeout`,
  `_create_failed`, `_modify_failed` and `_cancelled`. `arco info --json` gains `vpc.endpoints` (`[]` without `endpoints`,
  `null` when unreadable). Without `endpoints` there are no new calls or permissions
  ([Gateway endpoints](docs/configuration.md#gateway-endpoints)).
- **Added:** documentation for coding agents: [`docs/agents.md`](docs/agents.md) (running Arco without a terminal,
  every command and `arco.php` method, workflows, error keys and their fixes), an [`llms.txt`](llms.txt) index, and a
  Claude Code skill in `skills/arco` (copy it into a project's `.claude/skills/`).
- **Fixed:** `arco remove` printed the code of a kept bucket (`… was kept in AWS: some`) instead of saying whether
  it still has files. Its steps now print the way `deploy` prints them.

## 0.12.0

New opt-in setting in `arco.php`: `vpc()`. Without it nothing changes. To use it, the deploy permissions need new
statements first ([Upgrading to 0.12](docs/upgrading.md#upgrading-to-012)).

- **Added:** `->vpc(subnets: [...], securityGroups: [...])`, on the app or an environment (`->vpc(false)` clears an
  app-level one), runs the environment's Web Function, console, scheduler and queue workers inside your VPC. The Web
  Function uses a Lambda network connector (one per environment, named like the function) managed by an operator role
  `arco-<app>-<env>-network` that Arco creates and removes; the console and workers use `VpcConfig`, and the
  managed execution role gets the network interface permissions (`ArcoVpcAccess`). 1 to 16 subnets and 1 to 5
  security groups; one subnet works (checked on real AWS), two Availability Zones are recommended for production.
  A changed configuration creates a new connector version and new revisions without downtime; old versions are
  pruned after a successful deploy, keeping the ones rollback needs. A connector that ends `FAILED` fails the deploy
  before any revision exists, with the reason translated (wrong subnet or security group, no free IPs, missing role
  permissions) and the manual delete command; the next deploy replaces a failed connector no live revision uses
  (`deploy.network_replacing`). A connector with the name and another operator role is refused before any change,
  even with `--force`; the operator role is guarded like the execution role. Removing `vpc()` takes the environment out of the VPC and deletes the connector and operator role
  only after the deploy is verified; if that delete fails, the warning prints the manual commands and later
  deploys do not retry. `arco remove` deletes them too, and leaves a connector Arco did not create alone.
  `arco info` shows the VPC block (`--json`: a `vpc` object or `null`). Behind an emulator endpoint the VPC is
  skipped with a note (`deploy.vpc_unsupported_emulator`). The subnets need a NAT or endpoints for the AWS APIs
  (SSM, Secrets Manager, SQS, S3, DynamoDB, and the runtime binary download); see
  [VPC](docs/configuration.md#vpc). New steps `deploy.step_network`, `deploy.network_waiting`,
  `deploy.network_updated`, `deploy.network_removed`; errors `deploy.network_failed`, `deploy.network_timeout`,
  `deploy.network_foreign`; warnings `deploy.network_prune_failed`, `deploy.network_cleanup_failed`, `deploy.network_replacing`.
- **Added:** the deploy that leaves the VPC, and `arco remove`, wait until Lambda has deleted the console's and the
  workers' VPC network interfaces before the execution role loses its network statements or goes: Lambda needs
  that role to delete them. New steps `deploy.step_vpc_interfaces`, `remove.step_vpc_interfaces`; warnings
  `deploy.vpc_interfaces_pending`, `deploy.vpc_interfaces_failed`, `remove.vpc_interfaces_pending`,
  `remove.vpc_interfaces_failed`.
- **Added:** new deploy permissions with `vpc()`: `lambda:CreateNetworkConnector`, `lambda:GetNetworkConnector`,
  `lambda:UpdateNetworkConnector`, `lambda:DeleteNetworkConnector`, `lambda:ListNetworkConnectorVersions`,
  `lambda:PassNetworkConnector` on `network-connector:*`, `iam:PassRole` on `arco-<app>-*-network`, and
  `ec2:DescribeSubnets`, `ec2:DescribeSecurityGroups`, `ec2:DescribeVpcs`, `ec2:DescribeNetworkInterfaces`, `lambda:ListFunctions`
  ([Permissions for VPC](docs/getting-started.md#permissions-for-vpc)). Arco Cloud's connect template needs them
  for apps that use `vpc()`.

Requested by the ferretería project (MultiERP) through Arco Cloud.

## 0.11.5

Nothing changes in `arco.php` or in the deploy permissions.

- **Fixed:** a network blip while uploading the package to S3 failed the whole deploy. A deploy from GitHub Actions
  failed with `cURL error 56: Recv failure: Connection reset by peer` on one `UploadPart`, after the AWS SDK's own
  quick retries, and the same job passed when it was run again. Now an upload that fails on the network or on S3
  (a connection reset or timeout, a 5xx, `RequestTimeout`, `SlowDown`) is retried once, 5 seconds later: a
  multipart upload resumes with the parts already uploaded instead of starting over. A note says so
  (`deploy.upload_retry`, a `note` event in `--json`). An upload that fails again, or that S3 rejected (for
  example `AccessDenied`), fails the deploy as before with the same error, and its multipart upload is aborted so
  no parts are left behind. This covers the web package, the console package and the runtime binary. Reported by
  Arco Cloud.

## 0.11.4

Security patch. Upgrade and redeploy every environment that has `domain()`
([Upgrading to 0.11.4](docs/upgrading.md#upgrading-to-0114-security)). Nothing changes in `arco.php`, but the deploy
profile needs new permissions first.

- **Security:** with `domain()`, the Web Function endpoint stayed `ApplicationManaged`, so anyone who knew its
  `*.lambda-web.<region>.on.aws` URL could call it directly and skip CloudFront. `arco-laravel` trusts
  `X-Forwarded-*` from every source, and the `ForwardHost` function that sets `X-Forwarded-Host` runs only on
  CloudFront, so a direct call could send any `X-Forwarded-Host` (the tenant of a host-resolved multi-tenant app,
  signed URLs, password reset links) and any `X-Forwarded-For`. Every environment with `domain()` is affected;
  environments without `domain()` are not changed. Now the endpoint is `IamAuth` and only the environment's own
  CloudFront distribution may invoke it, so the raw endpoint URL answers 403. Reported by the ferretería project
  (MultiERP) through Arco Cloud.
- **Changed:** with `domain()`, the endpoint is `IamAuth`, whatever `auth()` says. The domain stack gains a
  CloudFront origin access control (`AWS::CloudFront::OriginAccessControl`, type `lambda`, signing behavior
  `always-amz-auth`, which is the only one Lambda Web Function origins accept) on the origin. After the stack
  completes, Arco puts a statement `ArcoCloudFront` in the Web Function's resource-based policy (allowing
  `cloudfront.amazonaws.com` to call `lambda:InvokeWebFunctionEndpoint` on the endpoint, only for that
  distribution), waits until the distribution is `Deployed`, and only then switches an existing endpoint to
  `IamAuth`, so an upgrade does not cut the live site. New steps: `deploy.oac_policy`, `deploy.endpoint_protected`,
  `deploy.endpoint_unprotected` and `deploy.oac_revoked` (only when a statement was removed); warning
  `deploy.domain_stack_unreadable`; errors `deploy.policy_unreadable`, `deploy.access_arn_invalid` (Arco never writes
  a policy it cannot read) and the verification reason `deploy.verify_signed_forbidden`. Statements Arco did not write are kept.
  Removing `domain()` removes the statement first (for `auth('public')` and `auth('iam')`) and, for `auth('public')`,
  makes the endpoint public again; `arco remove` cleans up as before.
- **Changed:** `auth('iam')` together with `domain()` is allowed now (`validation.domain_iam` is gone) and means the
  same as `domain()` alone.
- **Changed:** with `domain()`, the post-deploy check still probes `/up` on the endpoint, now with a SigV4-signed
  request (service `lambda`, the deploy credentials, the endpoint's region), with the same success rule as before.
  This needs `lambda:InvokeWebFunctionEndpoint` on the environment's endpoint ARN; a 403 names that permission and
  the app's own health route as the two causes (`deploy.verify_signed_forbidden`). `auth('iam')` without `domain()` keeps using the platform init
  report and needs no new permission.
- **Changed:** new deploy permissions with `domain()`: `cloudfront:CreateOriginAccessControl`,
  `cloudfront:GetOriginAccessControl`, `cloudfront:UpdateOriginAccessControl`,
  `cloudfront:DeleteOriginAccessControl` (CloudFormation uses the deploy credentials), and
  `lambda:GetResourcePolicy`, `lambda:PutResourcePolicy` (plus `lambda:DeleteResourcePolicy`, only when `domain()`
  is removed and Arco's statement was the only one) on `web-function/<app>-*`. The existing
  `cloudfront:*Distribution*` and `lambda:*WebFunction*` do not cover these. The signed `/up` probe also needs
  `lambda:InvokeWebFunctionEndpoint`, which the minimal permissions' `lambda:*WebFunction*` already grants; add it
  only to scoped or guarded profiles (`ArcoWebFunctionOfTheApp`). A deploy without them stops with an AWS access
  error, and says the endpoint is still public (`deploy.endpoint_still_public`).
  **Arco Cloud's connect template needs the same permissions before it deploys an environment with `domain()` with
  0.11.4.**
- **Changed:** after an existing endpoint switches to `IamAuth`, the deploy requests the health path through the
  distribution's own domain (`deploy.protection_check`), retrying 401, 403 and 5xx for about a minute. If CloudFront
  still cannot reach the app, Arco sets the endpoint back to `ApplicationManaged` (the site keeps serving through
  CloudFront, unprotected) and the deploy fails with `deploy.protection_reverted`. The policy statement and the
  access control stay; the next successful deploy locks the endpoint again. Any other error after the switch also
  sets the endpoint back first. If that fails three times, the deploy stops with `deploy.protection_revert_failed`,
  which prints the `aws lambda-web update-web-function-endpoint` command that makes the endpoint public by hand.
- **Changed:** `auth('iam')` → `auth('public')` without `domain()` makes no resource-policy call: the statement is
  only removed while a domain stack is left from an earlier `domain()`.
- **Upgrade every deploy path to 0.11.4:** a deploy by 0.11.3 or older (a CI lock file, a teammate, Arco Cloud's
  runner) drops the access control and makes a protected endpoint public again, without a warning. Require
  `multiapi/arco:^0.11.4` everywhere you deploy from.
- **Changed:** `arco info` shows, for an environment with `domain()`, that the endpoint is `IamAuth` and only
  CloudFront may invoke it (`info.auth_cloudfront`), and `arco info --json` has the new top-level key
  `endpoint_auth` (`IamAuth` or `ApplicationManaged`) after `permissions_boundary`. `settings.auth` keeps the
  configured value; no key is renamed.
- **Changed:** with `domain()`, `arco deploy` marks the endpoint URL in its summary as CloudFront only
  (`deploy.endpoint_cloudfront_only`). `--json` results are unchanged. `auth('iam')` deploys without `domain()`
  make one extra `DescribeStacks` call (to find a leftover CloudFront statement); no new permission. If the profile
  may not call it, the deploy prints the warning `deploy.domain_stack_unreadable` and goes on.
- **Changed:** behind an emulator endpoint (`AWS_ENDPOINT_URL`, such as Floci), a `domain()` environment is deployed
  without this protection: no origin access control, no policy statement, the configured `auth()`, and the plain `/up`
  probe, with the note `deploy.endpoint_unprotected_emulator`. A service-specific override
  (`AWS_ENDPOINT_URL_LAMBDA`, `_LAMBDA_WEB`, `_CLOUDFRONT`, `_CLOUDFORMATION`) that points elsewhere keeps the
  protection. Floci's CloudFront cannot sign for a Lambda Web Function
  origin (it rejects `always-amz-auth`). Real AWS deploys (the variable unset) always get the protection.
- **Note (known residual, not fixed here):** closing the endpoint does not stop `X-Forwarded-For` spoofing through
  CloudFront. CloudFront appends the viewer address to an `X-Forwarded-For` the viewer sent, and because Laravel
  trusts every proxy (`TrustProxies::at('*')`), `request()->ip()` returns the leftmost address, the one the viewer
  chose. Do not rely on `request()->ip()` for security decisions. `X-Forwarded-Host` is safe: the `ForwardHost`
  function overwrites it.

## 0.11.3

- **Changed:** `arco deploy` updates the console function and the queue workers together instead of one after
  another. Each of them runs the same console zip (~185 MB), and Lambda takes a while over each code update: the
  deploy now starts the code update on every function, waits for all of them, updates every configuration, waits
  again, then publishes a version of each. With a console and one worker, that stretch takes about half as long
  (about a third with two workers). Nothing moves earlier: the deploy commands, the live aliases, the traffic shift
  and the event source mappings still wait until every version is published. The steps `deploy.step_console` and
  `deploy.step_worker` are printed as before, all of them before the update starts; a failure still names its
  function (`deploy.console_failed`, `deploy.worker_update_failed`), and `deploy.timeout` lists every function
  still busy after 600 s. Nothing to change on upgrade. Reported by Arco Cloud.

## 0.11.2

- **Fixed:** a Laravel app without `laravel/octane` is caught early. `arco init` lists it with the other
  packages to `composer require`, and `arco deploy` / `arco package` refuse in seconds, before the copy and
  `composer install`, with `build.needs_packages` naming the command to run. Before, the build failed only
  after `composer install`, with `laravel.octane_missing`. Reported by Arco Cloud.
- **Fixed:** `arco init` and the deploy's package check suggested `multiapi/arco-laravel:^0.9`; they now
  suggest `^0.11`, the line that goes with this Arco.

## 0.11.1

Tagged by mistake on the 0.11.0 commit before the 0.11.2 fixes were merged: v0.11.1 is the 0.11.0 code.
Use 0.11.2.

## 0.11.0

One opt-in feature, a permissions boundary on the execution role, requested for accounts that hand the deploy
profile to a CI or a service. Nothing changes without a boundary, so an upgrade needs no change
([Upgrading to 0.11](docs/upgrading.md#upgrading-to-011)). The one difference: a role whose boundary was set by hand
now gets the note `deploy.boundary_kept` on every deploy.

- **Added:** `->permissionsBoundary(string|false $policyArn)` on the app and on an environment: the managed execution
  role `arco-<app>-<env>` carries that IAM permissions boundary. A new role is created with it; an existing role
  without it, or with another one, gets it (`iam:PutRolePermissionsBoundary`) before its inline policy is written,
  with the step `deploy.boundary_set`. Arco never removes a boundary: when `arco.php` stops setting one, the role
  keeps it, with the note `deploy.boundary_kept`. The value must be a policy ARN (AWS-managed policies allowed;
  `validation.boundary_arn`), and combining it with `role()` is an error (`validation.boundary_with_custom_role`).
  `permissionsBoundary(false)` on an environment clears an app-level one. See
  [Permissions boundary](docs/configuration.md#permissions-boundary).
- **Added:** the deploy host can set the boundary without editing `arco.php`: `arco deploy --permissions-boundary=<arn>`
  or `ARCO_PERMISSIONS_BOUNDARY` from the environment of the machine that runs Arco (not an app variable). Precedence:
  flag > variable > `arco.php`; an empty flag or variable counts as not given. `arco info` takes the same flag.
- **Added:** with a boundary configured, an `AccessDenied` on `iam:CreateRole`, `iam:PutRolePermissionsBoundary` or
  `iam:PutRolePolicy` stops the deploy, before any function or traffic change (the resources stack and the code
  upload may already have run), with `deploy.boundary_denied`
  (`{role, boundary, action, error}`): either the account requires another boundary (condition
  `iam:PermissionsBoundary`) or the profile lacks `action` on `role/arco-*`.
- **Added:** `arco info` shows a `permissionsBoundary` row with its source; `arco info --json` has the key
  `permissions_boundary` (string or `null`) after `tables`. In `deploy --json`, `deploy.step_role` has the param
  `boundary` when one is configured.
- **Docs:** the setting and its interaction with `permissions()` (effective permissions are the intersection with
  the boundary); `iam:PutRolePermissionsBoundary` in the minimal permissions when a boundary is used, granted with `iam:CreateRole`; a new
  [Enforcing a permissions boundary](docs/getting-started.md#enforcing-a-permissions-boundary) with the account
  guard, what it needs to hold (every existing `arco-*` role carries the boundary, with a check; Lambda writes scoped
  to the app's functions `<app>-*`, with the statements and the actions that cannot be scoped; no CloudFormation on
  stacks other than `arco-*`; the optional `iam:PassedToService` condition), the unconstrained trust policy of a new
  role (IAM Access Analyzer, SCPs), the permissions that would bypass it, the denial and a recommended boundary policy
  derived from the managed policy, with the partition to change for `aws-cn` and `aws-us-gov`; the 0.11 upgrade notes and the new entries of the 1.x promise. The end-to-end suites set the
  AWS-managed boundary `PowerUserAccess` on the `floci` and `e2e` environments and read it back with `iam:GetRole`.
- **Fixed:** PHP warnings and notices (for example the AWS SDK failing to cache a refreshed SSO token in a
  read-only `~/.aws`) now go to stderr in every command, so they never corrupt a `--json` document on stdout.


## 0.10.2

- **Fixed:** the Laravel build no longer fails with "Please provide a valid cache path" when a package resolves
  the Blade compiler at boot (laravel/cashier-paddle, for example). `storage/` is still not packaged, but Arco
  creates empty `storage/framework/{views,cache/data,sessions}` and `storage/logs` in the staged copy for
  `composer install` (`package:discover`) and the build steps, and removes them before packaging.
- **Docs:** the deploy profile's storage permissions on `arn:aws:s3:::<app>-*` also cover the buckets' files,
  because `*` matches `/`. The docs said the opposite. They now show an optional deny that keeps the deploy
  profile away from them.

## 0.10.1

**Security release.** PHP 8.5.12 fixes two critical remote code execution vulnerabilities (CVE-2026-109618,
CVE-2026-109619; [php.net advisory](https://www.php.net/archive/2026.php#2026-10-21-5)) that affect FrankenPHP and
long-lived workers. Redeploy every environment (`arco deploy`); no `arco.php` change is needed.

- **Security:** the default runtime build is now `php8.5.12-fp1.13.1-r1` (PHP 8.5.12, FrankenPHP 1.13.1). Apps that
  pin an older build with `->runtime(fn (Runtime $r) => $r->build(...))` keep it: remove the pin or move it to the new
  build.
- **Security:** php.net's interim hardening is on by default on the web function, the console and queue workers:
  `session.upload_progress.enabled=0` and `fatal_error_backtraces=0`. An app's `phpIni()` can still set either key.

## 0.10.0

Four features (Cloudflare DNS, `deploy`/`remove --json`, resource relations, `table()`) and two `info --json` keys;
nothing else changes meaning, so an upgrade needs no change unless an AWS profile is named `cloudflare`
([Upgrading to 0.10](docs/upgrading.md#upgrading-to-010)).

- **Changed:** `'cloudflare'` is reserved in `dns()`: `->dns('cloudflare')` now means Cloudflare, so an AWS profile
  named `cloudflare` can no longer be used as a DNS profile there. Rename the profile and write the new name.

- **Added:** `->dns('cloudflare')` puts the custom domains' DNS records in Cloudflare (API v4): the ACM validation
  `CNAME`s and `CNAME <domain> → <distribution>.cloudfront.net`, always `proxied: false` (the apex works through
  CNAME flattening). The API token comes from `CLOUDFLARE_API_TOKEN` or `--cloudflare-token` (on `deploy`,
  `remove`, `init` and `domain:add`), is never written anywhere and needs Zone → DNS → Edit and Zone → Zone → Read;
  a missing or refused token, or a domain without a Cloudflare zone, stops the deploy before any AWS change.
  Records carry the comment `managed by arco: <app>/<env>`: a live record without it is refused unless
  `--force-dns`, and `arco remove` deletes only this environment's. Validation `CNAME`s carry `managed by arco`
  and are kept on remove, as with Route 53; a conflicting record at a validation name shows only after the
  certificate is requested. Requests answered with HTTP 429 or 5xx are tried up to 4 times. `arco init` and `domain:add` offer
  Cloudflare (`--dns=cloudflare`); `arco info` shows the DNS provider (`dns.provider` row, `dns` key in `--json`).
  See [Cloudflare](docs/configuration.md#cloudflare).
- **Added:** `arco deploy --json` and `arco remove --json` print NDJSON on stdout: `step` events (`started`,
  `done` with `elapsed_ms`, `warning`, `note`), throttled `progress` lines (`build.zip`, `binary.download`) and a
  final `result` line (`outcome` `deployed`, `removed`, `failed` or `refused`, `revision`, `url`, `domains`,
  `rolled_back`, `cold_start_ms`, `error {key, message}`). Every line has `"schema": 1`. `--json` never asks,
  sends the remaining human output to stderr or nowhere, redacts resolved secrets and tokens (inside text from 8
  characters, as whole values below that), and keeps the exit
  codes. Step and error `key`s with their `params` are API; `message` texts are not. See
  [Deploy and remove with `--json`](docs/getting-started.md#deploy-and-remove-with---json).
- **Added:** `relations` on every resource of `arco info --resources --json`: `worker_of`, `dlq_of`, `invokes`,
  `serves` and `validates`, outgoing from the resource, `[]` when unknown.
- **Added:** `->table('<name>', ttl: ..., existing: ...)`: a DynamoDB table `<app>-<env>-<name>` for your own code
  (keys `PK`/`SK` strings, on demand, optional TTL, tagged, kept when removed), or an `existing` one. Its names
  reach the functions as `ARCO_TABLES` (`{"<name>": "<table>"}`), and the managed role gets item access on it
  (`ArcoAppTables`). `cache` and `failed-jobs` are reserved names. New commands `table:add` and `table:remove`;
  `arco info` lists the tables (`tables` in `--json`). DynamoDB allows one TTL change per table per hour. See
  [Tables](docs/configuration.md#tables).
- **Added:** `arco info --json` has the keys `dns` and `tables`, after the existing keys and before the conditional `error` key.
- **Docs:** Cloudflare, tables, `deploy`/`remove --json`, relations, the 0.10 upgrade notes and the new entries of
  the 1.x promise. The end-to-end suite deploys a `table()` and runs `arco deploy --json` against Floci.

## 0.9.1

- **Fixed:** `arco info --resources` (and the list `arco remove` shows before asking) marked the CloudFront
  distribution and function as kept although the domain stack, which `arco remove` deletes, owns them: they are now
  shown as deleted. A certificate Arco requested stays deleted and one it found and reused stays kept.
- **Fixed:** the same list did not include the execution role, because the Tagging API does not return IAM roles. Arco
  now reads it directly (`iam:GetRole`) and lists it, as deleted, when it carries the environment's tags; if the role
  is missing or the read is denied, it is simply not listed.

## 0.9.0

The API freeze before 1.0: the last release allowed to break `arco.php`, the CLI, the `--json` output or the AWS
names. Deprecated items keep working in 0.9 with one warning per run and are removed in 1.0. What to change, with
before/after snippets: [Upgrading to 0.9](docs/upgrading.md#upgrading-to-09).

- **Changed (breaking, IAM):** before the first 0.9 deploy, add `lambda:ListTags` and `events:ListTagsForResource`
  to the deploy profile: the ownership check reads them before any change, and without them `arco deploy` and
  `arco remove` stop naming the permission. The other new permissions are optional (a warning or note without
  them); the table is the first step of [Upgrading to 0.9](docs/upgrading.md#upgrading-to-09).
- **Added:** `->runtime(fn (Runtime $r) => $r->php('8.5'))` chooses the PHP version: the newest runtime build whose
  PHP is 8.5.x. `frankenphp('1.13')` now matches by prefix and combines with `php()`. Without `php()`, Arco uses the
  newest build of its default PHP minor (8.5), and a 1.x release moves that default only to a new PHP patch: a new
  PHP minor is opt-in via `php()`, also for `frankenphp()` alone. An unknown minor is a validation error listing the
  PHP versions this Arco knows. `arco init` now writes `->runtime(fn (Runtime $r) => $r->php('8.5'))`.
  The selectors resolve per level: an environment's `build()` (or `binary()`) replaces the app's `php()`/`frankenphp()`,
  and an environment's `php()`/`frankenphp()` replaces the app's `build()`; mixing them at one level is an error.
- **Added:** tags are Arco's record of what belongs to an app and environment (no state file: local and CI runs
  see the same). Every resource Arco creates for an environment carries `arco:app` and `arco:environment`: the
  functions, the execution role, the scheduler rule, both stacks and what they make (queues, buckets, tables,
  CloudFront), the managed certificate, and now also the log groups (created tagged, or adopted and tagged when
  Lambda made them first) and the SSM parameters `arco secret:set` writes (tagged after every write, new or
  overwritten).
- **Added:** `arco deploy` keeps an AWS Resource Group `arco-<app>-<env>` (a tag query on both tags), so the AWS
  console shows the environment's resources together; it creates the group or updates its query, and leaves a
  group with that name that is not the environment's alone. `arco remove` deletes it last, only when its query
  is the environment's. The group is a convenience: without permission the deploy and the remove print a note
  (once). The deploy prints `deploy.group_ready` only when it created the group or updated its query.
- **Added:** `arco info --resources` lists every resource tagged for the environment in its region (type, name,
  whether `arco remove` deletes or keeps it), also as `--json` (`schema: 1`, `app`, `environment`, `group`,
  `resources[]` with `type`, `name`, `arn`, `retained`). `arco remove` prints the same list before it asks for
  confirmation (and with `--yes`).
- **Added:** `arco info --profile/-p` (it ran without a profile flag before) and the account guard: `info` refuses to
  read an environment locked with `account()` through a profile of another account, like `deploy` does. This is a change in behaviour: `info` now enforces the `account()` lock for any resolved profile
  (the flag, `AWS_PROFILE`, the remembered one), where it used to read whatever account the profile pointed to.
- **Added:** `--json` on `arco queue:list`, `arco storage:list` and `arco profile`, and `"schema": 1` as the first key of
  every `--json` output (`info`, `info --resources`, `logs` (each line), `queue:list`, `storage:list`, `profile`).
  Existing keys are unchanged. `--json` output and exit codes are the scripting API; message texts are not.
- **Added:** execution roles are tagged `arco:region`. `arco deploy` refuses (`deploy.region_changed`), before any change and even with `--force`, when the
  existing role was created for another region than `region()`: an app lives in one region, so use another
  environment name for a second one. A role without the tag (created by an older Arco) deploys and gets it.
- **Added:** `arco failed-jobs:add [--existing=<table>]` and `arco failed-jobs:remove` turn the failed-jobs table on or
  off in `arco.php`, matching `cache:add` / `cache:remove`.
- **Added:** `cache(driver: 'dynamodb')` is accepted (the only value; others are a validation error), so another cache
  backend can be added later without renaming. The `arco.php` editor does not write the default driver.
- **Added:** `docs/upgrading.md`: what to change in `arco.php`, CI and IAM for each version from 0.1 to 0.9, with
  before/after snippets for 0.9, and the 1.x compatibility promise (what is API and what is not). The README,
  the getting-started guide and the configuration reference describe 0.9 throughout, including a table of the
  variables Arco sets, the named-argument policy, the default-build policy and the `--json` schema.
- **Changed:** `protected()` guards `arco command`, `arco remove` and `arco queue:dlq --redrive`, not only `arco tinker`
  (default: environments named `production`). At a terminal they ask first; without one they stop and say to add
  `--yes`. CI scripts running `arco command ... --env=production --no-interaction` or `arco remove` there must add
  `--yes`. Deploy commands (`deployCommands()`, `--migrate`) are not asked.
- **Changed:** `--yes` / `-y` answers every confirmation on `deploy`, `remove`, `tinker`, `command` and `queue:dlq`;
  `--force` only overrides a safety check (`init`, `deploy`, `deploy --force-dns`).
- **Changed:** before a deploy updates, or `arco remove` deletes, the web function, console function, queue
  workers, execution role or scheduler rule, Arco reads their `arco:app`/`arco:environment` tags and refuses,
  before changing anything, a resource tagged for another app or environment (names are `<app>-<env>`, so app
  `shop-eu` + env `prod` and app `shop` + env `eu-prod` collide), or one with no tags and no Arco description.
  Untagged resources that an older Arco made (its description is on them) are this environment's, and the
  deploy tags them. `arco deploy --force` overrides a refusal (dangerous); `arco remove` never deletes another
  environment's resources. The deploy profile needs `lambda:ListTags` and `events:ListTagsForResource`
  (and `lambda:TagResource`, `iam:TagRole` to tag older resources); without them the deploy stops naming
  the permission (`events:DescribeRule` too, when the deploy puts a scheduler rule). `scheduler:set --apply`,
  `scheduler:remove --apply`, `scheduler:pause` and `scheduler:resume` check the rule the same way, and
  `arco command` / `arco tinker` check the console function before invoking it (they now also need
  `lambda:GetFunctionConfiguration` and `lambda:ListTags` on it); none of these has an override.
- **Changed:** app and environment names may not end in `-console`, `-resources`, `-scheduler` or `-host`, nor
  contain `-queue-`, nor may `<app>-<env>` when it names another app's resource (app `shop-eu` + env
  `console` is the console function of `shop`/`eu`): validation names the rule.
- **Changed:** `variables()` refuses every name starting with `ARCO`: they are reserved for Arco (it sets `ARCO`,
  `ARCO_APP`, `ARCO_ENV`, `ARCO_BUILD` and the ones its runtime reads). The error names the variable. A variable
  of your own that starts with `ARCO` needs another name (a value of `ARCO_QUEUES` or `ARCO_STORAGE` no longer
  replaces Arco's map).
- **Changed:** log retention defaults to 30 days. An environment without `logRetention()` (new or already deployed)
  now gets 30 days on its web, console and queue worker log groups on the next deploy, which prints the
  `deploy.log_retention_default` line once; before, those groups kept logs forever. `logRetention(false)` keeps
  "never expire" and an explicit value is unchanged. `arco info` shows `30 days (default)`.
  Every deploy now calls `logs:CreateLogGroup` and reads the current retention (`logs:DescribeLogGroups`): only
  a group whose retention changes (a new group, or one without that retention) gets `logs:PutRetentionPolicy`
  (or `logs:DeleteRetentionPolicy` for `false`), and the default line is printed only then. Without
  `logs:DescribeLogGroups` every deploy sets the retention and prints the line, as the first one does. A missing
  `logs:CreateLogGroup` / `logs:PutRetentionPolicy` is a warning after the deploy is live.
- **Changed:** a profile without `logs:TagResource` gets a plain note, once per deploy, instead of a warning.
- **Changed:** when every `storage()` sets `default: false` explicitly, the app has no default disk: only
  `ARCO_STORAGE` is injected (no `FILESYSTEM_DISK`, `AWS_BUCKET` or `AWS_URL`). A storage without the option is still
  the default, as before.
- **Changed:** `queue(maxInstances: N)` is the most concurrent worker invocations of a queue (the event source
  mapping's `MaximumConcurrency`), the same name `maxInstances()` has for the web function. `queue:add
  --max-instances` and `queue:set <queue> max-instances` replace `--concurrency` / `concurrency`; editing a queue
  rewrites `concurrency:` as `maxInstances:`.
- **Changed:** `workers(int $count)` is the Octane worker count and the new `maxThreads(int $threads)` is FrankenPHP's
  `max_threads` (defaults 4 and 64, as before). `arco info` shows `runtime.maxThreads` next to `runtime.workers`.
  `mode()` is marked `@internal` and left out of the docs.
- **Changed:** `arco info --json` reports `settings."runtime.workers"` as an integer (the Octane worker count; it was
  the string `"4-64"`) and the new `settings."runtime.maxThreads"` (FrankenPHP `max_threads`).
- **Changed:** `console(bool $enabled = true, ?int $timeout = null, ?int $memory = null)`: the console function's
  timeout (3 to 900 s) and memory (128 to 10240 MB, default 2048) are options of `console()`; giving one turns the
  console on. `arco set console-timeout` / `console-memory` (and `unset`) write them there.
- **Changed:** `cache(bool $enabled = true, ?string $existing = null, string $driver = 'dynamodb')` and
  `failedJobsTable(bool $enabled = true, ?string $existing = null)` no longer take `null` (it meant `true`);
  `cache(driver:)` accepts only `dynamodb` and validation refuses anything else. `dns()`'s parameter is named
  `$dns`.
- **Changed:** runtime build ids are `php<php>-fp<frankenphp>-r<n>` (`php8.5.11-fp1.13.0-r2`); `arco info` (also
  `runtime.build` in `--json`), the deploy output and new packages show them. The old ids `1.13.0-r1` and
  `1.13.0-r2` stay valid forever as aliases: `build('1.13.0-r2')` and packages built with it deploy the identical
  binaries. `build()` is an exact pin and is now an error next to `php()` too (as next to `frankenphp()`). The local
  runtime cache moves to `~/.arco/cache/runtime/<new id>/`, so each machine downloads the build once more. When the
  console downloads FrankenPHP at cold start (a build without a PHP CLI, such as `1.13.0-r1`), its S3 key becomes
  `runtime/<new id>/frankenphp`: the binary is uploaded once more (same bytes), and the old key is kept while any
  published function version references it.
- **Changed:** `binary(string $frankenphp, ?string $php = null)`: the parameter `$path` is now `$frankenphp`
  (`binary(path: ...)` no longer works). With `php:` (a Linux arm64 static PHP CLI, checked at validation) the
  console function and the queue workers carry it as `bin/php` like the official builds, instead of downloading
  the FrankenPHP binary at cold start; without it nothing changes.
- **Changed:** the framework preset interfaces (`FrameworkPreset`, `CachesPaths`, `RunsCommands`,
  `PresetRegistry`) and `App::toArray()` / `Environment::toArray()` are marked `@internal`: not part of the 1.x
  compatibility promise.
- **Changed:** new deploy-profile permissions, all optional (a warning or note without them):
  `logs:TagResource` (log group tags), `logs:DescribeLogGroups` (log retention changes only when needed),
  `ssm:AddTagsToResource` (`secret:set`), `tag:GetResources`
  (`info --resources`, the list before `remove`), and for the Resource Group `resource-groups:GetGroupQuery`,
  `resource-groups:CreateGroup`, `resource-groups:Tag`, `resource-groups:GetGroup`, `resource-groups:GetTags`,
  `resource-groups:UpdateGroupQuery`, `resource-groups:DeleteGroup`.
- **Changed:** the `composer require` line that `arco deploy` and `arco init` print for a missing
  `multiapi/arco-laravel` names `multiapi/arco-laravel:^0.9` (the release line of this Arco; `^1.0` from 1.0).
  It printed `^0.5` or `^0.6`, which Composer reads as 0.5.x or 0.6.x only. The check itself is unchanged: it
  still accepts any install that has what the feature needs.
- **Changed:** the E2E suite now pins Floci `nightly-10192026`, where storage writes and CloudFront to a Web Function work, so all eight Floci checks are mandatory.
- **Changed:** the real-AWS E2E cleanup also deletes the run's log groups (`/aws/lambda/<app>-e2e*` and
  `/aws/lambda/web/<app>-e2e*`), within the guarded account and the run's app name. When the profile may not list
  them or read their tags, it reports `log groups not checked: <error>`, deletes none of them and still cleans up
  the other resources.
- **Deprecated:** `queue(concurrency:)`, `queue:add --concurrency` and `queue:set <queue> concurrency` (use
  `maxInstances` / `max-instances`) and `workers(min:, max:)` (use `workers(N)` and `maxThreads(N)`). They keep
  working in 0.9, print one warning per run, and are removed in 1.0. Giving both `concurrency` and `maxInstances`
  to a queue is a validation error.
- **Deprecated:** `arco remove --force` and `arco tinker --force` (use `--yes`). They keep working in 0.9, print
  one warning, and are removed in 1.0.
- **Deprecated:** `arco queue:failed-table` (use `failed-jobs:add` / `failed-jobs:remove`). It is hidden from the command
  list, keeps working, prints one warning per run, and is removed in 1.0.
- **Removed:** `maxEnvironments()` (the deprecated old name of `maxInstances()`) and `consoleTimeout()`. An `arco.php` that
  still calls them stops with a message naming the replacement: `maxInstances(N)` and `console(timeout: N)`.
- **Fixed:** `arco deploy` reports `updated` (not `up to date`) for a bucket, table or queue whose definition the
  resources stack update changed (lifecycle, CORS, bucket policy, table settings). It compares the running template
  (`cloudformation:GetTemplate`); when that is denied it reports `updated` for every resource it updates.
- **Fixed:** help and message texts that named removed or wrong things: `arco command --timeout` (it named
  `consoleTimeout`), `storage:add --default` (it said `FILESYSTEM_DISK`, which is always `s3`; the option picks
  the bucket behind `AWS_BUCKET` and `AWS_URL`), the `maxEnvironments()` error (it said the rename happened in
  0.5; `maxInstances()` is the name since 0.1) and the deploy's log retention default note. The validation error
  for an `<app>-<env>` ending in `-resources`, `-scheduler` or `-host` no longer calls it a console function: it
  names the resource each suffix belongs to.
- **Fixed:** `arco remove` refuses, before deleting anything, when the execution role was created for another
  region than `region()` (`deploy.region_changed`, as `deploy` does): after a region change it would have
  deleted the account-global role and the CloudFront stack still used by the live function in the old region.
- **Fixed:** `arco deploy --force` refuses a resources stack or domains stack with Arco's name that another app or
  environment made before it tags anything (the stack check moved into the read-only ownership check); it used to
  tag the resources it took over first, then refuse the stack.
- **Fixed:** `arco init` leaves `ARCO*` keys of `.env` (such as `ARCO_API_SECRET`) out of `arco.php`: `variables()`
  refuses them, so the written file did not validate.
- **Fixed:** any failed `cloudformation:GetTemplate` (not only a denied one) reports the resources as `updated`
  and the deploy goes on.
- **Fixed:** an unexpected failure while `arco remove` deletes the Resource Group is a `remove.group_failed` note;
  the remove still succeeds.
- **Fixed:** `arco info --json` and `arco info --resources --json` print a config error as
  `{"schema": 1, "error": "..."}` with exit code 1, like `queue:list` and `storage:list`.
- **Fixed:** `arco tinker` prints config warnings (such as `deprecated.*`) on stderr, so the value
  `tinker --execute` prints on stdout stays clean.
- **Fixed:** an `arco.php` still written for an 0.8 signature gets a message naming the 0.9 one:
  `binary(path: ...)` (now `binary(frankenphp: ..., php: ...)`), `dns(profile: ...)` (positional or `dns(dns: ...)`)
  and `cache(null)` / `failedJobsTable(null)` (null meant `true`: pass `true` or nothing to keep it on, `false` to turn it off). Other errors keep PHP's text.

## 0.8.1

- **Note:** the `v0.8.0` tag was created on the 0.7.0 commit by mistake, so 0.8.0 ships exactly the code of
  0.7.0. Everything below is the 0.8 release; use 0.8.1.
- **Added:** `AWS_ENDPOINT_URL` points Arco at a local AWS emulator such as [Floci](https://github.com/floci-io/floci),
  the same variable the AWS SDK honours (the SDK reads it itself; Arco reads it for its URLs). Arco recognises
  only `http(s)://host[:port]`; for any other non-empty value `arco deploy` warns that the SDK may still use it
  while Arco builds AWS-style URLs. `AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=true` turns it off for both. With an
  endpoint set, URLs use `http` when the endpoint does, bucket URLs are path style (`<endpoint>/<bucket>`), the
  app gets `AWS_USE_PATH_STYLE_ENDPOINT=true` (your `variables()` win), `arco deploy` prints a note naming the
  endpoint before it loads credentials, so an `AWS_ENDPOINT_URL` exported for another tool does not redirect a
  deploy unnoticed, and it does not offer to lock the environment to the emulator's account. Nothing changes on
  AWS when it is unset. See `docs/local-floci.md`.
- **Added:** `multiapi/arco-laravel`'s named S3 disks honour `AWS_USE_PATH_STYLE_ENDPOINT`.
- **Added:** end-to-end suites. `composer test:e2e` deploys `examples/laravel` to a throwaway Floci in Docker
  and checks the function, cache, storage, queue, scheduler, `arco command`, logs and the CloudFront
  distribution; `composer test:e2e-aws` is the manual run against a dedicated real AWS account (dry run unless
  `ARCO_E2E_DRY_RUN=0`, cleanup limited to resources tagged `arco:app=<run app name>`, refuses any account but
  `ARCO_E2E_ACCOUNT`). Maintainer docs: `docs/maintainers/e2e.md` and `releasing.md`.
- **Added:** `.github/workflows/e2e-floci.yml` runs the Floci suite in CI, on ARM. It replaces the manual
  `e2e.yml` workflow, which is removed.
- **Changed:** the package upload sets the ACL `bucket-owner-full-control` instead of `private`.
- **Fixed:** relative Composer `path` repositories that point outside the project (monorepos) could not be
  installed in the staged build, so the deploy failed. They are now rewritten to absolute paths, copied instead
  of symlinked; path repositories inside the project are untouched.

## 0.7.0

- **Added:** `arco init` reads the Laravel app and offers what it finds, one question each: the console
  function, `migrate --force` on deploy (only with a server database and the console), the scheduler (with
  the interval the app's schedule needs, when `multiapi/arco-laravel` can report it), SQS queues with their
  workers (from `ShouldQueue` classes and `onQueue()` calls), a failed-jobs table, an S3 storage, the
  DynamoDB cache table and CloudWatch log retention (default 30 days). Detection runs only for the Laravel
  preset and never fails `init`: when artisan cannot boot, the schedule is read from the code.
- **Added:** `arco init` flags that answer those questions: `--console` / `--no-console`, `--migrate` /
  `--no-migrate`, `--scheduler=N`, `--log-retention=N`, `--queue=NAME` (repeatable), `--failed-jobs-table`,
  `--storage=NAME` and `--cache`. Without a terminal `init` writes only what the flags say and prints the
  `arco` command that adds each detected piece it left out.
- **Added:** `arco init` ends with `composer require ...` for the packages the chosen features need
  (`multiapi/arco-laravel`, `aws/aws-sdk-php`, `league/flysystem-aws-s3-v3`) when `composer.lock` does not
  list them under `packages`. Without a `composer.lock` nothing is checked; the deploy still verifies the
  package.
- **Changed:** `arco init` validates the `arco.php` it is about to write, for every environment, and writes
  nothing when it would not validate; warnings are shown after the file is created.
- **Fixed:** answering yes to `migrate` in `arco init` now also writes `->console()`. Before, it wrote
  `->deployCommands()` alone, which `arco deploy` has refused since 0.4 (deploy commands run in the console
  function). Declining the console skips the migrate and scheduler questions.
- **Changed:** the `ArcoTables` statement of the managed execution role no longer grants `dynamodb:Scan`
  (neither the cache store nor the failed-jobs provider uses it); with your own `role()`, drop it too.
- **Changed:** the `ArcoStorage` statement adds `s3:AbortMultipartUpload`, and every bucket Arco creates gets a
  lifecycle rule (`arco-abort-incomplete-uploads`) that aborts multipart uploads left incomplete for 7 days,
  next to its `expire` rules. The bucket is updated in place; `existing` buckets are not touched.
- **Fixed:** wording of `queue:dlq` (reading raises the receive count) and, in Spanish, of the deploy errors
  for retained queues, buckets and tables.
- **Fixed:** `arco.php` failed to load with Laravel Herd's PHP when Herd dumps are on.
- **Fixed:** the integration-test S3 emulator retries with another port when its port is taken.

## 0.6.0

- **Added:** S3 storage. `->storage('uploads')` (app or environment level, merged by name; `->storage('uploads',
  false)` drops an app-level storage from an environment) creates a private bucket
  `<app>-<env>-uploads-<account>` (at most 63 characters, checked by validation): all Block Public Access
  settings on, SSE-S3, tagged. Named options: `public` (key prefixes anyone can read), `expire` (prefix =>
  days, a lifecycle rule), `cors` (origins; `GET`, `PUT`, `POST`, `HEAD`), `existing` (a bucket Arco does not
  manage, in the app's region) and `default` (the first storage unless one says `default: true`).
- **Added:** the default storage is Laravel's `s3` disk and default disk (Arco sets `FILESYSTEM_DISK=s3`,
  `AWS_BUCKET` and `AWS_URL`), and `multiapi/arco-laravel` registers every storage as a disk of its own name
  from `ARCO_STORAGE` (`Storage::disk('uploads')`). A disk the app already defines under that name is left
  alone, with a `disk_skipped` log line; the stock names `s3`, `public` and `local` are refused by the
  validation. The app needs `league/flysystem-aws-s3-v3`.
- **Added:** public prefixes use a bucket policy (`s3:GetObject` on `<prefix>*` only) and relax only
  `BlockPublicPolicy` and `RestrictPublicBuckets` on that bucket. When the account's own Block Public Access
  refuses the policy, the deploy stops with an error that names the setting.
- **Added:** validation of storage: public and expire prefixes cannot contain `*` or `?`; `public`, `expire`
  or `cors` on an `existing` bucket are ignored, with a warning (also from the `storage:*` commands).
- **Added:** DynamoDB tables. `->cache()` creates `<app>-<env>-cache` (key `key`, TTL on `expires_at`) and sets
  `CACHE_STORE=dynamodb` and `DYNAMODB_CACHE_TABLE`; `multiapi/arco-laravel` points the `dynamodb` store at it
  with the attributes `key` / `value` / `expires_at`, whatever `config/cache.php` says. With
  `cache(existing: '<table>')` the app's own attributes are used. `->failedJobsTable()` creates
  `<app>-<env>-failed-jobs` (Laravel's `application` + `uuid` layout, TTL on `expires_at`), sets
  `QUEUE_FAILED_DRIVER=dynamodb`, and `multiapi/arco-laravel` sends failed jobs there; Laravel gives them an
  expiry of one week, so they disappear after about a week. Both tables are on-demand; `false` turns them off
  on an environment.
- **Added:** buckets and tables are kept like queues: one removed from `arco.php` (or by `arco remove`) stays
  in AWS with its files or items and the deploy names it. Adding a kept name back stops the deploy before any
  change and prints the `existing:` line to use instead; a bucket name held by another account (or a 403
  without `s3:ListBucket`) stops it the same way.
- **Added:** `arco storage:add`, `storage:remove`, `storage:public`, `storage:expire`, `storage:cors`,
  `cache:add`, `cache:remove` and `queue:failed-table` edit `arco.php`; `arco storage:list` shows each
  storage's bucket and options and the retained buckets (`tag:GetResources`).
- **Added:** `arco info` lists the storage, the cache table and the failed-jobs table; `--json` adds `storage`
  (`name`, `bucket`, `url`, `existing`, `default`, `public`), `cache` and `failedJobsTable` (`{table,
  existing}` or `null`).
- **Added:** the managed execution role gets `ArcoStorage` (object read, write, delete and ACL actions on the
  buckets' objects), `ArcoStorageList` (`s3:ListBucket` on the buckets) and `ArcoTables` (item actions,
  `Query`, `Scan`, batch actions and `DescribeTable` on the tables), `existing` ones included. With your own
  `role()`, add them yourself (see the configuration reference).
- **Added:** deploy-profile permissions for storage and tables (see Permissions for storage and tables in the
  getting-started guide): on the app's bucket ARNs only (`arn:aws:s3:::<app>-*`, no object access)
  `s3:Get*`, `s3:Put*`, `s3:CreateBucket`, `s3:DeleteBucketPolicy`, `s3:ListBucket` and `s3:DeleteBucket`
  (rollback only); on the app's tables `dynamodb:CreateTable`, `UpdateTable`, `Describe*`, `UpdateTimeToLive`,
  `TagResource`, `UntagResource`, `ListTagsOfResource` and `DeleteTable` (rollback only); and
  `tag:GetResources`. An action a failed stack event names goes on the same ARNs.
- **Added:** before any change, the deploy checks the package for what `arco.php` needs: storage needs
  `league/flysystem-aws-s3-v3`, `aws/aws-sdk-php` and `multiapi/arco-laravel` 0.6+; the cache table
  `aws/aws-sdk-php`; the failed-jobs table `aws/aws-sdk-php` and `multiapi/arco-laravel` 0.6+ (queues as in
  0.5). A missing one stops the deploy with the `composer require` line to run. The error key
  `deploy.queues_need_packages` is now `deploy.needs_packages` and lists each package with the `arco.php`
  calls that need it.
- **Changed:** queues, buckets and tables use `DeletionPolicy: RetainExceptOnCreate`: one created by a deploy
  that fails and rolls back is deleted (it is new and unused, and keeping it would block the next deploy on
  its name); every other one is still kept. CloudFormation then needs `sqs:DeleteQueue` for that rollback.
  Existing resources stacks switch on their next deploy.
- **Changed:** the note for a variable of your own that overrides Arco's now covers every variable Arco sets
  (queues, storage, tables), not only the queue ones; `ARCO_*` variables never get it. A user value for
  `CACHE_STORE` or `DYNAMODB_CACHE_TABLE` (or `QUEUE_FAILED_DRIVER`) also leaves out `ARCO_CACHE_TABLE` (or
  `ARCO_FAILED_JOBS_TABLE`), so `multiapi/arco-laravel` does not put Arco's table back.
- **Changed:** `queue:remove --app` (and `storage:remove --app`) warns about environments that keep their own
  call for that name.
- **Changed:** a stack named `arco-<app>-<env>-resources` that Arco did not create no longer fails deploys of
  apps without queues, storage or tables; it is left alone.

## 0.5.0

- **Added:** SQS queues. `->queue('emails')` (app or environment level, merged by name; `->queue('emails', false)`
  drops an app-level queue from an environment) creates an SQS queue `<app>-<env>-emails` with a dead-letter
  queue, and a worker function `<app>-<env>-queue-emails` fed by an SQS event source mapping. Named options:
  `timeout` (60, 10 to 900), `memory` (1024), `concurrency` (unset; at least 2), `batchSize` (1), `tries` (3),
  `dlq` (on), `fifo` (off), `existing` and `default` (the first queue unless one says `default: true`).
  Above 10 messages a batch on a standard queue, the event source mapping gets a 1-second batching window
  (AWS requires one).
- **Added:** the worker runs jobs with Laravel's own queue `Worker`, booted once per instance
  (`multiapi/arco-laravel`'s internal `arco:work`): retries, `$backoff`, `$timeout`, `failed()` and the
  app's failed-jobs store work as with `queue:work`, with partial batch responses. The dead-letter queue
  (`maxReceiveCount` = `tries` + 2) only catches messages the worker never answered for (crash, out of
  memory, Lambda timeout); on the last delivery before it, a job that would be retried again is failed into
  `failed_jobs`, so jobs whose own `$tries`, `retryUntil()` or releasing middleware go beyond the queue's
  `tries` end there earlier than they asked. In maintenance mode jobs wait without spending attempts.
  Job time limits use `pcntl` signals; a job blocked in one long C call (curl, a database query) times out
  only when the call returns. The app needs `multiapi/arco-laravel` 0.5+ and `aws/aws-sdk-php`: with
  queues, the deploy checks the package for both before any change and names the `composer require` to run.
- **Added:** Arco sets `QUEUE_CONNECTION=sqs`, `SQS_PREFIX`, `SQS_QUEUE` (the default queue) and `ARCO_QUEUES`
  (every queue's URL) on all functions, and `multiapi/arco-laravel` maps queue names to their URLs, so
  `->onQueue('emails')` and `Queue::route(...)` work without app changes (Laravel 11 to 13). Your own
  `variables()` win, with a note in the deploy output.
- **Added:** FIFO queues (`fifo: true`) work on Laravel 11 to 13: messages without a group get the queue
  name as their group and the job's UUID as their deduplication id, and a delay is dropped. In a batch,
  the messages after one that comes back are not run (they come back too), so a message group keeps its order.
- **Added:** `existing:` attaches a worker to a queue Arco does not manage (name, URL or ARN); Arco never
  changes its attributes and warns when it already has other consumers. When a queue's reference changes,
  the worker's event source mapping on the old queue is deleted once the new one exists.
- **Added:** queues are never deleted. A queue removed from `arco.php` (or by `arco remove`) is kept in AWS
  with its messages and the deploy names it with its message count; adding the name back, or switching
  `fifo` on a queue Arco created, stops the deploy before any change. The queues live in a CloudFormation
  stack per environment, `arco-<app>-<env>-resources`, updated before any code is deployed; workers switch
  to the new version only after the web revision is live and verified (a failure there is a warning).
- **Added:** `arco queue:add`, `queue:set` (`concurrency none` clears the limit; `fifo` and `existing` are
  fixed) and `queue:remove` edit `arco.php`; `arco queue:list` shows messages, dead-letter queue and worker
  version; `arco queue:dlq <name>` shows a sample of the dead-letter queue and `--redrive` moves the messages
  back.
- **Added:** `arco logs --queue=<name>` and `--source=queues` (worker logs; one JSON line per job with its
  status), and `--console` as a short form of `--source=console`. With more than 10 log groups, `--follow`
  polls instead of using Live Tail and says so.
- **Added:** `arco info` lists the queues with their URL and worker version; `--json` adds `queues`
  (`name`, `url`, `existing` as a boolean, `dlq` as the dead-letter queue name or `null`, `worker`).
- **Added:** the managed execution role gets an `ArcoQueues` statement (send, receive, delete, change
  visibility, read attributes and URL on the queues in `arco.php`) and the worker log groups. With your own
  `role()`, add them yourself (see Queue permissions in the configuration reference).
- **Added:** deploy-profile permissions for queues (see Permissions for queues in the getting-started guide):
  CloudFormation on `stack/arco-*-resources`, `sqs:CreateQueue`, `GetQueueUrl`, `GetQueueAttributes`,
  `SetQueueAttributes`, `TagQueue`, `UntagQueue`, `ListQueueTags`, `ReceiveMessage`, `StartMessageMoveTask`
  (plus `DeleteMessage` / `SendMessage` for a redrive), `lambda:CreateEventSourceMapping`,
  `UpdateEventSourceMapping`, `DeleteEventSourceMapping`, `ListEventSourceMappings`, `GetEventSourceMapping`,
  the console function's Lambda actions on `function:*-queue-*`, and `tag:GetResources`.
- **Changed:** `arco logs` without `--source` (`all`) and `->logRetention()` now include the queue worker log
  groups.
- **Changed:** with queues, the console package is built and uploaded even when the console is off (the
  workers run it); a console package over 250 MB then stops the deploy before any change.
- **Changed:** `arco remove` also deletes the queue workers with their event source mappings and the
  resources stack, keeping the queues and listing them.
- **Changed:** every deploy looks for the resources stack (`cloudformation:DescribeStacks`) and for workers of
  removed queues (`tag:GetResources`). Without queues in `arco.php`, a profile denied either is not warned.

## 0.4.1

- **Added:** `arco scheduler:pause` / `arco scheduler:resume` disable or enable the environment's scheduler
  rule in AWS at once, without editing `arco.php`. A deploy keeps a paused rule paused (with a note) instead of
  turning it back on; new rules start enabled. Without a rule in AWS they fail and change nothing.
- **Added:** `scheduler:set` and `scheduler:remove` can apply the change without a deploy: they ask "Apply now
  in AWS without a deploy?" (default no), or apply at once with `--apply`. Set needs the console function's
  `live` alias to be deployed (otherwise nothing in AWS changes and it asks for a deploy); remove deletes the
  rule and its invoke permission. `--apply` needs `--env`.
- **Added:** `arco info` shows a paused scheduler (`every 5 min (paused)`), and `--json` adds
  `settings.schedulerPaused` (`true`, `false` or `null`).
- **Added:** deploy-profile permissions `events:DisableRule` and `events:EnableRule` on
  `rule/arco-*-scheduler` for pause and resume (already covered by an `events:*` grant on the rule).
- **Changed:** with a scheduler, a deploy now reads the rule (`events:DescribeRule`, already in the documented
  permissions) before updating it, to keep a paused rule paused; without that permission the scheduler update
  fails with the existing `deploy.scheduler_failed` warning.

## 0.4.0

- **Changed (breaking):** the console function is opt-in. A deploy now creates only the web function; the
  console function (`<app>-<env>-console`) exists only when `arco.php` says `->console()` (or a scheduler
  needs it). `arco command`, `arco tinker`, `deployCommands()` and `arco deploy --migrate` need it and fail
  before touching AWS when it is off, naming `arco console:add`.
- **Changed (breaking):** upgrade guard. If an environment already has a console function and `arco.php`
  has no `console()` setting, `arco deploy` stops before changing anything. Add `->console()` to keep the
  console function, or `->console(false)` to delete it on the next deploy (`arco console:add` and
  `arco console:remove` write those lines). Apps deployed with Arco 0.2 or 0.3 need one of them.
- **Added:** `console()` setting and `arco console:add` / `arco console:remove`. With `console(false)` the
  deploy deletes an existing console function (a failed delete is a warning).
- **Added:** clock-aligned Laravel scheduler: `->scheduler(N)` / `arco scheduler:set [N]` /
  `arco scheduler:remove`. An EventBridge rule runs `schedule:run` on the console function's `live` alias
  at minutes aligned to the UTC clock (`cron(0/N * * * ? *)`), for N of 1, 2, 3, 4, 5, 6, 10, 12, 15, 20,
  30 or 60. It needs the console and turns it on. `scheduler:set` without N suggests an interval from the
  app's tasks (read with `php artisan arco:schedule-intervals`, run with `ARCO=1`, so the app's own
  `env('ARCO')` branches see it). The deploy warns when a task needs a shorter interval, creates the rule
  only after the console was activated successfully, and warns when EventBridge cannot be read.
- **Added:** log retention: `->logRetention(days|false)` / `arco logs:retention <days|never>` /
  `--remove`. Applied to the web and console log groups on deploy; not set means Arco does not manage it.
- **Added:** `arco info` shows `console`, `scheduler` and `logRetention`; with the console off, the `console`
  block of `--json` is `{"enabled": false, "function": "<name>"}` with no remote lookup, and with it on it
  gains `"enabled": true`. In `--json`, `settings.console` is `true`/`false`/`null`, `settings.scheduler` the
  minutes or `null` and `settings.logRetention` the days, `false` (never expire) or `null` (not managed).
- **Added:** a deploy removes runtime binaries (`runtime/*` in the code bucket) older than 24 hours that no
  published version of any Arco function in the region references, best effort (a warning when it cannot).
- **Added:** deploy-profile permissions for the above (see Minimal IAM permissions in the getting-started
  guide): `events:*` on `rule/arco-*-scheduler`, `lambda:AddPermission`, `lambda:RemovePermission`,
  `lambda:GetPolicy`, `logs:CreateLogGroup`, `logs:PutRetentionPolicy`, `logs:DeleteRetentionPolicy`,
  `tag:GetResources`, and `s3:ListBucket` / `s3:DeleteObject` for the clean-up.

## 0.3.0

- **Changed:** the console function carries a static PHP CLI (`bin/php`) with the same PHP version and
  extensions as the web runtime, so it no longer downloads FrankenPHP at cold start. Runtime build
  `1.13.0-r2`, which ships it, is now the default. In local Docker (`provided:al2023`) init took about
  0.2 s and the first command 0.6 to 1 s, against about 3 s and 4 s with the download (local
  measurements, not Lambda guarantees). The PHP CLI takes about 91 MB of the 250 MB of
  unzipped code Lambda allows, leaving about 159 MB for the app (sizes as Arco prints them). A deploy
  fetches it from GitHub releases once per runtime build and machine (cached in `~/.arco/cache/runtime`).
- **Changed:** a custom `binary()`, a runtime build without a PHP CLI (`1.13.0-r1`) or unknown to this Arco
  version, an app whose console would pass 250 MB unzipped only because of `bin/php`, or a PHP CLI that
  cannot be downloaded from GitHub (network, proxy, outage) keeps the download at cold start, and the
  deploy goes on and says why (a plain note for a custom `binary()`, a warning for the other cases) (for the size case it suggests `->exclude()`; for the download
  case it names the URL and the error). A PHP CLI whose size or checksum does not match stops the deploy
  before any AWS change.
- **Changed:** a console package whose `bin/php` lost its exec bit fails at init with an error naming
  it, instead of asking for `ARCO_BINARY_*` variables.
- **Fixed:** `arco tinker` no longer contacts api.github.com for PsySH's weekly update check.

## 0.2.1

- **Fixed:** `arco tinker` presents Eloquent models, collections, `Stringable`, `HtmlString` and the
  application like local `php artisan tinker` (attributes, not internals): the casters of
  `laravel/tinker` are registered when it is installed, plus the app's `tinker.casters` config.
- **Added:** config warnings for Laravel `deployCommands()` entries that cannot work on Lambda: an
  `artisan` or `php artisan` prefix (Arco runs artisan itself), commands that fail because they write into
  the read-only code directory (`config:cache`, `route:cache`, `event:cache`, `optimize`, `storage:link`),
  commands that do nothing there (`config:clear`, `route:clear`, `event:clear`, `optimize:clear`),
  commands Laravel asks to confirm in production without `--force` (`migrate`, `migrate:rollback`,
  `db:seed`...), and commands that drop every table (`migrate:fresh`, `migrate:refresh`,
  `migrate:reset`, `db:wipe`). `arco deploy` and `arco init` show them.
- **Changed:** the console function's log masks the values of secret-looking options (`--password=...`,
  `--api-key ...`, `--token`...) as `***` in the command audit line and in `command_failed`.
- **Changed:** on an environment with an `account()` lock, `arco tinker`, `arco command` and the other
  commands read the account of IAM Identity Center (SSO) and assumed-role credentials from the credentials
  themselves instead of calling `sts:GetCallerIdentity`: about 0.6 s less before the first statement. A
  mismatching account is still refused before anything runs.

## 0.2.0

- **Added:** every deploy also deploys a console function, `<app>-<env>-console`, with the same code,
  variables, secrets, `phpIni()` settings and role as the web function. Its package leaves out FrankenPHP (Lambda allows
  250 MB for classic functions): the binary is uploaded once to `runtime/<build>/frankenphp` in the code
  bucket and downloaded, checksum-verified, at the console function's cold start. The deploy keeps the
  live version and the 4 newest and prunes older ones. If AWS refuses to move its `live` alias after a
  verified deploy, the deploy still succeeds with a warning. `arco remove` deletes it.
- **Added:** `arco command "<args>"` runs an artisan (or PHP) command there and exits with its exit code;
  `--async` starts it without waiting, `--timeout` lowers the limit.
- **Added:** `deployCommands([...])`, `arco deploy --migrate` and `--no-deploy-commands`: commands run
  on the new console version before traffic moves; the first failure stops the deploy (exit code of that
  command) with the previous revision still serving. If a later step fails, Arco lists the commands that
  ran and suggests `arco command "migrate:rollback --force"`. `arco init` proposes `migrate --force` for
  Laravel apps with a server database.
- **Added:** `arco tinker`: PsySH on your machine, statements run on the console function, variables kept
  between statements; confirmation on `production` and `->protected()` environments (`--force` skips it);
  every statement is logged (its result is not). Needs `laravel/tinker` in the app's `require`.
- **Added:** `arco logs`: web and console logs merged and colored, with `--follow` (CloudWatch Live
  Tail, polling as fallback), `--since`/`--until`, `--level`, `--request`, `--filter`, `--source`,
  `--platform`, `--json`.
- **Added:** `consoleTimeout()` and `protected()`; `arco info` shows the console function and its version.
- **Changed:** the deploy profile needs Lambda permissions for the console function and
  `logs:StartLiveTail` for `--follow` (see Minimal IAM permissions), the managed role may read `runtime/*`
  in the code bucket and write the console log group, and packages built by `arco package` before this
  version must be built again.
- **Changed:** `multiapi/arco-laravel` registers a hidden `arco:tinker` command on Lambda.
- **Fixed:** missing or expired AWS credentials are reported as such, naming the profile (the DNS profile
  for Route 53 calls) and suggesting `aws sso login --profile <p>` for SSO profiles, by every command; other
  credential failures, such as a denied AssumeRole, keep AWS's message; `arco info` no longer says "Not
  deployed yet" in that case (it exits with 1).
- **Fixed:** the cold-start lookup has its own budget after custom domains are attached; a slow CloudFront
  update no longer leaves it at "not available yet (up to 0 s)".
- **Fixed:** `arco logs --follow` no longer prints an event twice when Live Tail replays the last history event.
- **Fixed:** a tinker statement that throws is no longer logged as `command_failed` (ERROR) by the console
  function (`arco:tinker` now exits with 3 for it; other failures still are); `command_failed` and
  `tinker_done` carry `durationMs`.
- **Changed:** `arco tinker` and `arco command` skip `sts:GetCallerIdentity` when no `account()` lock needs
  checking, saving a round trip and a TLS handshake before the first statement.

## 0.1.4

- **Fixed:** the cold start of a redeploy showed "not available yet". Lambda's platform events reach
  CloudWatch Logs up to ~40 s late, and the lookup searched streams by recent activity. It now
  searches the revision's own streams (named `<date>/<function>[<revision>]…`) and runs at the
  very end of the deploy, until ~60 s after the health check passed. With `auth('iam')`, where the
  init report is the health signal, Arco waits up to ~75 s. `logs:DescribeLogStreams` is no
  longer used.

## 0.1.3

- **Changed:** `concurrency()` has no default any more: Arco lets Lambda use its own default (64)
  and its dynamic per-instance limit. In a load test, forcing 32 started three times as many
  instances (41 vs 13) for the same throughput and latency. Set `concurrency()` only for CPU-heavy apps.
- **Changed:** AWS resources are tagged only with `arco:app` and `arco:environment`. The `arco:version`
  tag made every Arco upgrade update the CloudFront stack (minutes of deploy time); the Arco version
  is now in each revision's description ("Deployed by Arco 0.1.3").
- **Changed:** more folders and files are never packaged: `.ai`, `.junie`, `.cursor`, `.windsurf`,
  `.gemini`, `.codex`, `.aider*` and `.mcp.json` (AI assistant config).
- **Fixed:** `maxInstances()` must be 2–10000 and `rateLimit()` 100–1000 in steps of 100 or
  2000–10000 in steps of 1000, as AWS requires. Before, values AWS rejects (like `maxInstances(1)`)
  passed validation and failed during deploy.

## 0.1.2

- README: tagline.

## 0.1.1

- `arco --version`, the `arco:version` tag on AWS resources and the package manifest now report the
  installed version from Composer (0.1.0 said `0.1.0-dev`).

## 0.1.0 — first public release

Deploy Laravel and PHP apps to AWS Lambda Web Functions with FrankenPHP.

- **CLI:** `init`, `deploy`, `package`, `info`, `remove`, `profile`, `secret:set`, `set` / `unset`,
  `domain:add` / `domain:remove`. Messages in English and Spanish.
- **`arco.php`:** fluent configuration with app-level defaults and per-environment overrides.
- **Deploy:** builds a staging copy (`composer install --no-dev`), packages it with an immutable,
  attestation-verified FrankenPHP build from `multi-api/arco-runtime`, uploads it (skipped when
  unchanged), creates a revision, shifts traffic, verifies the health route, rolls back on failure
  and reports the cold start. `--package=<zip>` deploys a zip built earlier by `arco package`.
- **Laravel:** Octane workers on FrankenPHP; storage in `/tmp`; trusted proxy headers; Lambda's
  session token passed to every AWS client configured with the function's key; JSON logs to stderr
  with a request id.
- **Secrets:** `arco-ssm:` and `arco-secret:` references, checked before deploy, resolved at start-up,
  with IAM scoped to exactly the referenced parameters and secrets.
- **AWS profiles:** chosen per environment and remembered, with an account lock; SSO, login,
  `credential_process`, static-key and assume-role profiles.
- **Custom domains:** `domain()`, `dns()` and `certificate()`: ACM certificate found or requested,
  CloudFront distribution, Route 53 aliases in the same or another account, or records printed for
  other DNS providers. Live records are never taken over without `--force-dns`.
- **Scaling settings:** `concurrency()` (default 16), `maxInstances()`, `rateLimit()`, `timeout()`,
  plus `permissions()` for extra IAM statements.
