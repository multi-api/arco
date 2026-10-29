---
name: arco
description: Deploy and operate a PHP or Laravel app on AWS Lambda Web Functions with the Arco CLI (vendor/bin/arco, arco.php). Use when the project has arco.php or multiapi/arco, or the user asks to deploy, configure arco.php, add a queue, storage, table, custom domain or VPC, run a command or migrations on Lambda, read Lambda logs, store a secret, or remove an Arco environment.
---

# Arco

Arco deploys this project to AWS Lambda Web Functions. Its configuration is `arco.php` at the project root; the CLI is
`vendor/bin/arco`.

**Before acting, read `vendor/multiapi/arco/docs/agents.md`** (the agent guide: ground rules, non-interactive use,
every command and `arco.php` method, workflows, error keys and fixes). It links to the full reference in the same
folder (`configuration.md`, `getting-started.md`, `local-floci.md`, `upgrading.md`). If `vendor/` is not installed,
run `composer install` first, or read the docs at https://github.com/multi-api/arco/tree/main/docs.

## Rules that always apply

- Ask the user before any command that changes AWS or runs code there: `deploy`, `remove`, `command`, `tinker`,
  `secret:set`, `queue:dlq --redrive`, `scheduler:pause`/`resume`, `scheduler:set`/`remove --apply`. Editing commands
  (`set`, `queue:add`, `storage:add`, `domain:add`, ...) and `info`, `logs`, `queue:list`, `storage:list`, `profile`
  are safe.
- Confirm which AWS profile and account to use; never assume `default`.
- Never put secret values in `arco.php`, `.env` files you commit, command arguments or the chat. The user stores them
  with `vendor/bin/arco secret:set <name> --env=<env>`; reference them as `arco-ssm:/<app>/<env>/<name>`.
- Never pass `--force` or `--force-dns` on your own: they override ownership and DNS safety checks.
- Always pass `-n` and `--env=<env>`. Pass `--yes` only for the exact confirmation the user gave.
- Validate after editing `arco.php`: `vendor/bin/arco info --env=<env> --json -n` (no AWS change).
- Use `--json` and exit codes, not message text. `deploy --json` ends with one `result` line: `outcome` is
  `deployed`, `removed`, `refused` (nothing changed in AWS) or `failed` (check `rolled_back` and `error.key`).
- Never run `arco logs --follow` in a foreground call: read a window with `--since`/`--until`.
- Removed queues, buckets and tables stay in AWS with their data. Do not delete them unless the user asks.
