# Config layer

The config layer is a JSON document that Arco merges over `arco.php`, or uses alone when there is no `arco.php`.
It exists for tools that manage resources outside your code (a dashboard, a platform such as Arco Cloud): they write
the queues, buckets, tables, variables and domains they own into a layer and pass it to Arco at deploy time, and
never edit `arco.php`. Without a layer Arco behaves exactly as before; nothing here changes a deploy that does not
use one.

The layer is a public contract under the [1.x promise](upgrading.md#the-1x-promise): keys, rules and error keys
below are only added to, never renamed or removed.

## Passing a layer

```bash
vendor/bin/arco deploy --env=production --config-layer=layer.json
ARCO_CONFIG_LAYER=layer.json vendor/bin/arco deploy
```

- `--config-layer=<path>` wins over `ARCO_CONFIG_LAYER`. Surrounding whitespace is trimmed, and an empty or blank
  value falls back to the other one: there is no layer only when both are empty.
- Only `deploy`, `package`, `info`, `remove` and `logs` read the layer. Every other command (`tinker`, `command`,
  `queue:list`, the `arco.php`-editing commands, ...) ignores both the flag and the variable, and the editing commands
  change `arco.php` only.
- A missing or unreadable file is `config.layer_not_found` (`{path}`).
- `--env` may be left out: the layer's environment is used. An `--env` that names another environment fails with
  `config.layer_environment` (`{layer, environment}`).

## The document (schema 1)

```json
{
  "schema": 1,
  "name": "shop",
  "region": "us-east-1",
  "environments": {
    "production": {
      "settings": {
        "account": "123456789012",
        "cache": true,
        "failedJobsTable": true,
        "scheduler": 1,
        "console": true,
        "consoleTimeout": 900,
        "dns": "cloudflare",
        "vpc": { "subnets": ["subnet-a", "subnet-b"], "securityGroups": ["sg-1"], "endpoints": ["s3", "dynamodb"] }
      },
      "variables": { "MAIL_FROM_ADDRESS": "hi@example.com", "STRIPE_SECRET": "arco-ssm:/arco-cloud/shop/production/STRIPE_SECRET" },
      "permissions": [ { "Effect": "Allow", "Action": "dsql:DbConnect", "Resource": "arn:aws:dsql:..." } ],
      "domains": ["shop.example.com"],
      "queues":  { "emails": { "timeout": 60, "tries": 3, "dlq": true } },
      "storage": { "uploads": { "public": ["avatars/"], "adopt": true } },
      "tables":  { "state": { "ttl": "expires_at" } }
    }
  }
}
```

### Top level

`schema` (required, must be `1`), `name`, `region` and `environments`. Nothing else. A `schema` that is missing or not
`1` is `config.layer_schema` (`{schema}`).

`environments` has **exactly one** entry, the environment the command works on. The entry has these optional keys,
the same ones `arco.php` produces for an environment, without `runtime` and `deployCommands`, which belong with the
code:

| Key | Type |
|---|---|
| `settings` | object, see below |
| `variables` | object of name to string; values may be `arco-ssm:` / `arco-secret:` references |
| `permissions` | list of IAM statements (objects) |
| `domains` | list of host names |
| `queues` | object of queue name to options |
| `storage` | object of storage name to options |
| `tables` | object of table name to options |

### `settings`

Each key has the type the `arco.php` method stores:

| Key | Type |
|---|---|
| `concurrency`, `timeout`, `maxInstances`, `rateLimit`, `consoleTimeout`, `consoleMemory` | integer |
| `auth`, `logLevel`, `role`, `kmsKey`, `profile`, `certificate`, `cacheDriver`, `account` | string |
| `permissionsBoundary`, `dns` | string or `false` (`dns` is a profile name, `"cloudflare"` or `false`; Route 53 in the deploy account is no `dns` at all) |
| `scheduler`, `logRetention` | integer or `false` |
| `console`, `protected` | boolean |
| `cache`, `failedJobsTable` | `true`, `false` or `{"existing": "<table>"}` |
| `vpc` | `false` or `{"subnets": [...], "securityGroups": [...], "endpoints": [...]}`, as `vpc()` stores it (`endpoints` a list of services, or `service => id`) |

### Queues, storage and tables

Named entries take the options of the `arco.php` methods, plus `adopt` (see
[Adopting retained resources](#adopting-retained-resources)):

| Entry | Option | Type |
|---|---|---|
| queue | `timeout`, `memory`, `maxInstances`, `batchSize`, `tries` | integer |
| queue | `dlq`, `fifo`, `default`, `adopt` | boolean |
| queue | `existing` | string |
| storage | `public`, `cors` | list of strings |
| storage | `expire` | object of prefix to integer (days) |
| storage | `existing` | string |
| storage | `default`, `adopt` | boolean |
| table | `ttl`, `existing` | string |
| table | `adopt` | boolean |

Each entry is an object (`{}` for all defaults). Not accepted: the deprecated queue `concurrency`, `false` (a layer
cannot remove what `arco.php` declares) and `adopt` together with `existing`.

### Strictness

Unknown keys, wrong types and JSON that does not parse fail with `config.layer_invalid` (`{path, reason}`), where
`path` is a JSON pointer such as `/environments/production/queues/emails/timeout` (`""` for the document) and the
reason never repeats a value. Values of the right type are then checked by the same validation as `arco.php`, on the
merged configuration.

The strictness is deliberate: an Arco older than a layer's keys refuses the layer instead of silently ignoring part of
it. New keys arrive in later releases without changing `schema`; a breaking change would be `schema: 2`.

## Merging with `arco.php`

Order: Arco defaults, `arco.php` app level, `arco.php` environment level, layer. The layer is checked against the
environment's **resolved** `arco.php` value (an app-level value that the environment overrides or drops does not
count). The layer never overrides: a different value is a conflict and the command stops before any AWS call.

| What | Rule |
|---|---|
| `name`, `region` | The layer's value must equal `arco.php`'s when both are set |
| each `settings` key | Not set by `arco.php` (default) takes the layer's value; equal is fine; different is a conflict. A setting `arco.php` sets to `false` (`scheduler(false)`, `vpc(false)`) counts as set |
| each variable | The same, per name |
| `queues`, `storage`, `tables` | A name `arco.php` does not declare is added after `arco.php`'s entries; the same name is a conflict, except `adopt`. An environment-level `queue('x', false)` in `arco.php` frees the name: the layer may then declare `x` |
| `domains` | Appended after `arco.php`'s list. A domain in both is not a conflict: it is listed once, with `arco.php` as its source |
| `permissions` | Appended |

`default` among queues and storage follows the usual rule on the merged list: the first one is the default unless one
asks, so `arco.php`'s first queue stays the default.

Every conflict is listed, one per line, in this order of kinds. Each is `config.layer_conflict` with params
`{kind, name}`, where `kind` is `name`, `region`, `setting`, `variable`, `queue`, `storage` or `table` and `name` is
the layer's name or region, the setting's key, the variable's name (never its value) or the queue, storage or table
name. In `--json`, the error carries the first conflict's key and params.

### Without `arco.php`

With a layer and no `arco.php`, the layer alone is the configuration: `name` and `region` are required
(`config.layer_missing`, `{key}`), the environment is the layer's, and app-level values are Arco's defaults. With
neither, `config.not_found` as always. The prompt to lock the account in `arco.php` after a deploy is skipped.

An environment that `arco.php` does not declare is allowed: the layer adds it, as if `arco.php` had an empty
`->environment('<env>')`.

## Adopting retained resources

Arco keeps queues, buckets and tables when they leave the configuration (`queue:remove`, `remove`, ...). A layer entry
with `adopt: true` takes one of them back. It exists only in the layer; `arco.php` has `existing:` for
resources that were not made by Arco.

- While `arco.php` still declares the name, the layer's entry waits: it is ignored and a note
  `config.layer_adopt_waiting` (`{kind, name}`, an NDJSON `warning`) says so. Remove the name from `arco.php` to hand
  it over.
- Otherwise the entry is used like any layer entry, and at deploy the resources step decides:
  - the resources stack already owns it: a normal update (the usual hand-over, because the name left `arco.php` in
    the same deploy);
  - nothing with that physical name exists: created as usual;
  - it exists outside the stack and is tagged `arco:app` and `arco:environment` for this app and environment (and
    `arco:resource`, when present, as Arco would write it): **imported** into the resources stack;
  - anything else (unreadable tags, other tags): the same refusal as without `adopt` (`deploy.queue_retained`,
    `deploy.storage_retained`, `deploy.generic_table_retained`, `deploy.bucket_taken`, `deploy.queue_dlq_retained`).
- A queue and its dead-letter queue: each one that exists must pass; if either fails, nothing is imported.
- All checks are reads that run before the first change, so a refusal leaves everything untouched (`deploy --json`
  reports `changed: false`).

An import uses a CloudFormation `IMPORT` change set: the step `deploy.step_import` (`{stack, count}`;
`deploy.step_import_one`, same params, when there is one resource), then `deploy.queue_adopted` (`{queue, name}`),
`deploy.storage_adopted` (`{storage, bucket}`) or `deploy.generic_table_adopted` (`{table, name}`) per resource
instead of `_created`. An imported dead-letter queue adds `deploy.queue_dlq_adopted` (`{queue, name}`, `name` being
the DLQ) after its queue's step, which is `deploy.queue_adopted` when the queue was imported too, else its usual
`_updated` or `_changed`. Imported resources keep their data and are `Retain` during the import; the normal deploy
then applies every option (bucket policy, redrive, TTL) and the usual retention policy. If CloudFormation cannot
import, it rolls the import back and the deploy fails with `deploy.import_failed` (`{stack, reason}`); the resources
stay in AWS with their data. An import cannot add tags to a stack, so when the resources stack does not exist yet
Arco first creates it, tagged, with only a placeholder (`ArcoImportPlaceholder`, an
`AWS::CloudFormation::WaitConditionHandle` that makes nothing), imports into it, and the normal deploy then removes
the placeholder. If the deploy is cut short in between, the stack keeps the placeholder until the next deploy.
Do not run two deploys of the same environment at once.
`deploy.import_failed` is also the refusal of the checks, with `reason` `cloudformation:GetTemplate`, when an import
is due and the template the resources stack runs cannot be read (the import must keep it as it is): nothing is
written. A read the profile is denied during the checks is reported like it is without `adopt`:
`deploy.lookup_denied` (`{permission, name}`) for a refused `dynamodb:DescribeTable`, `deploy.bucket_taken` for a
refused `s3:HeadBucket`, and the usual retained refusal for tags that cannot be read. The permissions an import
needs are in [Permissions for queues](getting-started.md#permissions-for-queues) and [for storage and
tables](getting-started.md#permissions-for-storage-and-tables).

## Inventory

`arco info --resources --json` (schema 1, keys only added) says where each resource came from:

- every entry of `resources` gains `source`: `arco.php` (app or environment level), `cloud` (the layer), `default`
  (what Arco always makes: web function, execution role, log groups, stacks, resource group) or `null` (tagged for the
  environment but no longer in the configuration, such as a retained queue); and `options`: the resolved options of
  the item the resource belongs to (`toArray()` key names, defaults included), or `null`. A queue's DLQ and worker
  function carry their queue's options. A distribution, its certificate (also a reused one) and its CloudFront
  function carry the domains: `{domains, dns, certificate}`, with `arco.php` as source if any domain comes from there,
  `cloud` if every domain comes from the layer.
  The console function reports the stored value: `console: null` when only the scheduler turns the console on.
- `variables`: `[{"name", "source"}]`, sorted by name. Never values.
- `config`: every item the resolved configuration declares, deployed or not, `[{kind, name, source, options}]` with
  `kind` one of `queue`, `storage`, `table`, `cache`, `failedJobsTable`, `console`, `scheduler`, `domain`, `vpc`
  (`name` is the queue, storage or table name as configured, the domain, or the kind for the singletons; a domain's options are
  `{domain, dns, certificate}`).

`variables` and `config` are present with or without a layer. `arco info` (the table) shows `cloud` in the source
column for what the layer set.

## Errors

| Key | Params | When |
|---|---|---|
| `config.layer_not_found` | `path` | The file does not exist or cannot be read |
| `config.layer_schema` | `schema` | `schema` missing or not `1` |
| `config.layer_invalid` | `path`, `reason` | Parse error, unknown key, wrong type |
| `config.layer_missing` | `key` | No `arco.php` and no `name` or `region` in the layer |
| `config.layer_environment` | `layer`, `environment` | `--env` is not the layer's environment |
| `config.layer_conflict` | `kind`, `name` | The layer sets differently what `arco.php` sets (one error per conflict) |
| `config.layer_adopt_waiting` | `kind`, `name` | Note: an `adopt` entry waits for `arco.php` to let go of the name |

With `--json`, an error's `error` object carries `params` when the error has any (so far `config.layer_conflict`,
with `{kind, name}`); the key is always there. Params are only ever added to.
