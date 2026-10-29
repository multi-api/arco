# Try your app locally with Floci

[Floci](https://github.com/floci-io/floci) is a local AWS emulator. With `AWS_ENDPOINT_URL` set, Arco deploys to it
instead of AWS: the same `arco deploy`, `arco info`, `arco logs` and `arco remove`, with no account and no cost.
It is a way to see your app run on a Lambda Web Function before you touch a real account, not a replacement for a
staging deploy.

## Start Floci

Lambda Web Functions exist only in Floci's `nightly-*` images so far (release 2.3.0 does not have them). Arco's own
end-to-end suite is tested with `floci/floci:nightly-10192026`; use that tag, or a newer one, until a Floci release
ships Web Functions.

```bash
docker run --rm -d --name floci -p 4566:4566 \
  -e FLOCI_BASE_URL=http://localhost:4566 \
  -v /var/run/docker.sock:/var/run/docker.sock \
  floci/floci:nightly-10192026
```

- `FLOCI_BASE_URL` is the URL you reach Floci at from your machine. Floci puts that host and port in the function
  URLs it hands out, and an endpoint keeps the host it was created with, so set it before the first deploy and do
  not change it afterwards.
- Floci runs each function as a sibling container, so it needs the Docker socket.

## Point Arco at it

```bash
export AWS_ENDPOINT_URL=http://localhost:4566
export AWS_ACCESS_KEY_ID=test
export AWS_SECRET_ACCESS_KEY=test
export AWS_REGION=us-east-1
export AWS_CONFIG_FILE=/dev/null
export AWS_SHARED_CREDENTIALS_FILE=/dev/null
```

The last two keep your real profiles out of reach, so nothing in this shell can reach AWS by mistake. Do not set
`AWS_PROFILE`.

Arco does not pass the endpoint to the AWS SDK: the SDK reads `AWS_ENDPOINT_URL` itself, and Arco reads the same
variable to build its URLs. Arco recognises only `http(s)://host[:port]`. For any other non-empty value
`arco deploy` warns, naming the value: the SDK may still send its calls there while Arco builds AWS-style URLs, so
fix or unset it. With `AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=true` both the SDK and Arco ignore the variable. When a
recognised endpoint is set, `arco deploy` prints a note naming it (before it loads your credentials), so an
`AWS_ENDPOINT_URL` left exported for another tool does not redirect a deploy unnoticed. With it set, Arco also:

- builds URLs with `http` (an `https://` endpoint keeps `https`),
- uses path-style bucket URLs (`<endpoint>/<bucket>`) and sets `AWS_USE_PATH_STYLE_ENDPOINT=true` in the app
  (a value you set in `variables()` wins).
- deploys a `domain()` environment without the CloudFront protection of 0.11.4: no origin access control in the
  domain stack, no resource-policy statement, and the endpoint keeps the auth `auth()` configures (public by default),
  checked with the plain `/up` probe. The deploy says so in one line. On AWS only CloudFront may invoke that endpoint.
  A service-specific variable (`AWS_ENDPOINT_URL_LAMBDA`, `AWS_ENDPOINT_URL_LAMBDA_WEB`, `AWS_ENDPOINT_URL_CLOUDFRONT`
  or `AWS_ENDPOINT_URL_CLOUDFORMATION`) that points elsewhere keeps the protection. Arco does not read `endpoint_url`
  in a profile: a profile that sends those services to real AWS while `AWS_ENDPOINT_URL` points at an emulator
  deploys without the protection, so keep such profiles out of real deploys.

Nothing changes on real AWS when the variable is unset.

The SDK also honours per-service variables (`AWS_ENDPOINT_URL_<SERVICE>`, such as `AWS_ENDPOINT_URL_S3`) and
`endpoint_url` in a profile of your AWS config. Arco does not read those and does not show them: keep them out of a
shell you deploy from, or set `AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=true` for a real-AWS deploy.

## Deploy

Use an environment without `->account()`: Floci's account is `000000000000`, and an `account()` check would refuse
it. With `AWS_ENDPOINT_URL` set, Arco does not offer to lock the environment to an account after the deploy. A
dedicated environment keeps that clear:

```php
->environment('local', fn (Environment $env) => $env
    ->console()
    ->queue('default')
    ->storage('files')
    ->cache()
    ->table('state', ttl: 'expires_at')
    ->variables([
        'APP_ENV' => 'production',
        'APP_KEY' => 'base64:...',   // a literal test key: there is no SSM to hold an arco-ssm: reference
    ]))
```

```bash
vendor/bin/arco deploy --env=local --yes
vendor/bin/arco info --env=local
```

The first deploy pulls the runtime image, so the first request can take a while.

Custom domains (CloudFront, ACM, Route 53) can be deployed too, but the Route 53 hosted zone must exist in Floci
first, as it would in your account:

```bash
aws --endpoint-url http://localhost:4566 route53 create-hosted-zone \
  --name example.test --caller-reference arco-local
```

Serving the app through CloudFront to a Web Function works in Floci, as long as the hosted zone exists first.
`dns('cloudflare')` has no emulator: against Floci, use Route 53 (the default) or `dns(false)`; Arco would call
the real Cloudflare API.

`arco deploy --json` works against Floci as on AWS; Arco's own end-to-end suite deploys `examples/laravel` there
both ways and checks the NDJSON.

## Reach the app

Floci serves each function endpoint under `*.localhost`, on Floci's port:

```
http://<id>.001.lambda-web.<region>.localhost:4566
```

`arco info` prints the exact URL. Browsers and curl resolve `*.localhost` to your machine; if yours does not, send
the `Host` header to `http://localhost:4566`.

`arco logs`, `arco command` and `arco remove` work as on AWS. The cold start in the `arco deploy` output shows "not
available yet": Floci does not report the Lambda init metric to CloudWatch Logs.

## What Floci does not do

- **IAM is not enforced like AWS.** A deploy that works here can still fail on AWS because of a missing
  permission. Check [Minimal IAM permissions](getting-started.md#minimal-iam-permissions) before the real deploy.
- **No init report, so no `auth('iam')` deploy passes verification.** An `IamAuth` endpoint cannot be probed without
  a signature, so Arco waits for the Lambda init report in CloudWatch Logs, and Floci does not write it.
- **No CloudFront protection for `domain()`.** Floci's CloudFront does not know the signing behavior
  `always-amz-auth`, the only one AWS accepts for Lambda Web Function origins, so it cannot sign requests to the
  endpoint. Arco therefore skips the protection behind an emulator endpoint (see above). Test the protection itself
  (the custom domain answers, the endpoint URL answers 403) against a real account.
- **No VPC for `vpc()`.** Floci has no Lambda network connectors, so Arco skips `vpc()` behind an emulator endpoint
  with a note (`deploy.vpc_unsupported_emulator`) and deploys outside any VPC. Its `endpoints:` (gateway endpoints)
  are skipped with the same note. Test them against a real account.
- **No real DNS or TLS.** Custom domains do not resolve outside the emulator, and nothing is served over HTTPS.

## Clean up

```bash
vendor/bin/arco remove --env=local --yes
docker stop floci
```

Stopping the container discards what Floci held, including retained queues, buckets and tables.

## Composer path repositories

Not Floci-specific, but you will hit it in a monorepo: a `path` repository in `composer.json` that points outside
the project (`"url": "../packages/foo"`) is rewritten to an absolute path in the staged build, copied rather than
symlinked, so `arco deploy` finds it. Path repositories inside the project are left as they are.
