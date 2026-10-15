# Changelog

All notable changes to Arco are documented here. Arco follows [semantic versioning](https://semver.org);
while it is 0.x, minor versions may contain breaking changes, always listed under **Changed**.

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
