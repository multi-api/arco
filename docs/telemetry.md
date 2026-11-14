# Telemetry

Arco's runtime counts how many times your functions are invoked and sends that count, anonymously, to Arco's
collector, so the project can publish how much Arco is used (for example "120M+ invocations in the last 30 days")
and see which versions, regions and kinds of function people run. It is on by default and turned off with one
line or one variable ([Turning it off](#turning-it-off)).

Nothing in it identifies you, your AWS account or your app. This page lists everything that is sent.

## What is sent

One UDP datagram of plain text, under 200 bytes, with exactly these fields and nothing else:

| Field | Example | Why it is safe |
|---|---|---|
| protocol version | `arco1` | constant |
| runtime (`r`) | `laravel` or `process` | two values |
| kind (`k`) | `web`, `queue`, `console`, `schedule` | four values |
| Arco version (`v`) | `0.21.0` | the same for everyone on that release |
| AWS region (`g`) | `us-east-1` | about 30 values, shared by millions of functions |
| invocations (`i`) | `1342` | a count since the previous packet |
| cold start (`c`) | `1` on an environment's first packet, else `0` | |
| boot bucket (`b`) | `2` (0: under 500 ms, 1: 500 ms to 1 s, 2: 1 to 3 s, 3: over 3 s) | only on the first packet; a bucket, not a value |

Real packets, as they leave the runtime:

```
arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1 c=1 b=2
arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1342 c=0
arco1 r=process k=queue v=0.21.0 g=sa-east-1 i=7 c=0
```

The code that builds them is short and has no other output:
[`runtime/lib/telemetry.js`](../runtime/lib/telemetry.js) (the Node runtime: web functions and process apps' queues
and schedules) and [`runtime/console/Telemetry.php`](../runtime/console/Telemetry.php) (the console function, the
scheduler and Laravel queue workers). Both send the same encoding.

**Never sent:** your AWS account id, function name or ARN, app or environment name, domain, request paths, IP
addresses, request ids, environment variables, host names, and no random or derived id of any kind (no install id,
no hash of anything). Two packets from the same app cannot be told apart from two packets of different apps.

**The collector and IP addresses.** Like any server that receives a packet, the collector sees its source IP (an AWS
NAT or Lambda address). It never logs or stores it: the collector only reads the payload, and keeps a per-IP rate
limit in memory that forgets each IP after a minute. It logs counts only (packets accepted and rejected), never
payloads. It sums packets per hour, Arco version, region, runtime and kind, and keeps nothing else.

Development builds of Arco (version `dev`, a CLI run from a git checkout) still send packets, but the collector does
not count them.

## How the count works

- Each Lambda execution environment keeps one counter in memory. Counting an invocation is an increment and a clock
  read, no I/O.
- The first packet goes right after the environment's first invocation (with `c=1` and the boot bucket), then at
  most one packet a minute, when an invocation ends. Lambda freezes environments between invocations, so there is no
  timer: a quiet environment sends nothing. The Node runtime (web functions, and process apps' queues and schedules)
  sends what is pending when it gets SIGTERM, best effort. The console function, the scheduler and Laravel queue
  workers (PHP) have no SIGTERM handler: they send when an invocation ends, and a Laravel queue worker also when it
  exits after a job that ran out of time, so when Lambda shuts one of their environments down its last minute of
  counts or less is lost. So is an environment's that stops without SIGTERM.
- Every invocation is counted, no sampling. What counts as one:
  - **web**: one finished HTTP response, static files and errors included. In Laravel's default mode Caddy writes one
    line per response to a local counter on `127.0.0.1` (its `arco_count` logger, whose entries carry no request
    data: no URL, headers or IP); in `mode: proxy` and for process apps the Node proxy counts. If the local counter
    cannot start, Caddy runs exactly as without telemetry and web counts are lost for that environment.
  - **console**: one `arco command`, `arco tinker` statement or deploy command; **schedule**: one `schedule:run` of
    `scheduler()`, or one run of a process app's schedule.
  - **queue**: one batch of SQS messages handed to a worker, not one per job.
- Sending never waits for the network. The collector (`telemetry.arcocloud.app`, UDP port 41700) is resolved once
  per environment; if that fails, telemetry stays off for the rest of the environment's life. A function in a VPC
  without a NAT resolves the name and its datagram is dropped by the network; nothing waits for it.
  One residual case: in the console function and Laravel queue workers (PHP) the lookup is blocking, so if no DNS
  resolver answers at all, the first send of an environment can take up to about 5 seconds, once, after the
  invocation's response has been sent.
- Laravel queue counts are sent by `multiapi/arco-laravel`'s worker loop, which loads the counter from your
  function package. An app on an older `multiapi/arco-laravel` than the CLI that deployed it sends nothing for its
  queues (everything else is still counted); upgrade both to the same version.

## Overhead

Measured for this release (arm64, Docker on a laptop, `bin/test-docker`):

| What | With telemetry | Without |
|---|---|---|
| `count()` + `tick()`, Node 24, 1M iterations | 98 ns per invocation | 10 ns |
| `count()` + `tick()`, PHP 8.4, 1M iterations | 142 ns per invocation | 38 ns |
| 1,000 requests for a static file, Lambda simulation (3 runs) | 0.95 to 1.08 ms per request | 0.97 to 1.01 ms |
| 1,000 requests to Laravel's `/up`, Lambda simulation (3 runs) | 1.35 to 1.38 ms per request | 1.26 to 1.30 ms |

Counting costs about a tenth of a microsecond per invocation. End to end, a request through Laravel took 0.08 to
0.10 ms longer with telemetry on in the simulation, consistently across runs; for a static file the difference (0.02 ms
less to 0.07 ms more) was within the simulation's noise. Sending is one `sendto` at most once a minute per environment, after the response.

## Turning it off

Any one of these turns it off. The runtime then counts nothing and sends nothing.

- `->telemetry(false)` in `arco.php` (app or environment level), or `"telemetry": false` in `arco.json` settings or a
  config layer. Takes effect on the next deploy.
- `ARCO_TELEMETRY_DISABLE=1` in the shell that runs `arco deploy` (or `arco package`): the same as
  `->telemetry(false)` for that deploy, without touching `arco.php`. Handy in CI.
- `ARCO_TELEMETRY_DISABLE=1` in the function's environment: in `variables()` (the one `ARCO*` name it accepts), in
  bundled variables (also as an `arco-ssm:` or `arco-secret:` reference), or set in the Lambda console. The runtime
  reads it after the variable bundle and secrets load. A literal `'1'` in `variables()` is also read by the CLI, like
  `->telemetry(false)`: `arco info` shows telemetry off, the package says so and no notice is printed. A reference
  is only resolved in the function, so the CLI still shows telemetry on and the runtime turns it off. A value set
  only in the Lambda console is replaced by the next deploy.

`arco info` shows whether it is on (`settings.telemetry` in `--json`). The first `arco deploy` on a machine with
telemetry on prints a one-line notice about it, once.

Apps deployed by [Arco Cloud](https://arcocloud.app) send nothing: Cloud runners and the `arco-deploy-action` GitHub
Action set `ARCO_TELEMETRY_DISABLE=1` for the deploys they run, because Cloud already counts its apps exactly. A
Cloud app deployed by hand from another machine sends counts like any other app until you turn it off there.
