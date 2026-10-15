# Changelog

All notable changes to Arco are documented here. Arco follows [semantic versioning](https://semver.org);
while it is 0.x, minor versions may contain breaking changes, always listed under **Changed**.

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
