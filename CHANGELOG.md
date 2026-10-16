# Changelog

All notable changes to Arco are documented here. Arco follows [semantic versioning](https://semver.org);
while it is 0.x, minor versions may contain breaking changes, always listed under **Changed**.

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
