'use strict';
// Arco runtime for AWS Lambda Web Functions: resolves secrets, then runs FrankenPHP.
const fs = require('node:fs');
const path = require('node:path');
const { resolveSecrets } = require('./lib/secrets');
const { createFetchers } = require('./lib/aws');
const { buildCaddyfile } = require('./lib/caddyfile');
const supervisor = require('./lib/supervisor');

const ROOT = __dirname;
const TMP = '/tmp/arco';
const started = Date.now();
const log = (phase, data = {}) => console.log(JSON.stringify({ arco: phase, ms: Date.now() - started, ...data }));

async function main() {
  const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'arco-runtime.json'), 'utf8'));
  const [host, portString] = (process.env.AWS_LAMBDA_HTTP_ENDPOINT || '0.0.0.0:3000').split(':');
  const port = Number(portString);
  log('boot', { mode: config.mode, endpoint: `${host}:${port}` });

  const region = process.env.AWS_REGION || process.env.AWS_DEFAULT_REGION;
  const { resolved, missing } = await resolveSecrets(process.env, createFetchers(region));
  if (missing.length > 0) {
    log('secrets_missing', { missing });
    process.exit(1);
  }
  Object.assign(process.env, resolved);
  log('secrets', { resolved: Object.keys(resolved).length });

  supervisor.prepareTmp(TMP);
  const proxy = config.mode === 'proxy';
  const phpHost = proxy ? '127.0.0.1' : host;
  const phpPort = proxy ? 8000 : port;
  const caddyfile = path.join(TMP, 'Caddyfile');
  fs.writeFileSync(caddyfile, buildCaddyfile(config, { host: phpHost, port: phpPort, root: ROOT }));

  supervisor.spawnFrankenphp(
    supervisor.resolveBinary(ROOT, TMP),
    caddyfile,
    { ...process.env, ARCO: '1', HOME: TMP, XDG_CONFIG_HOME: `${TMP}/caddy/config`, XDG_DATA_HOME: `${TMP}/caddy/data` },
    path.join(ROOT, 'app'),
    log,
  );
  await supervisor.waitForPort(phpHost === '0.0.0.0' ? '127.0.0.1' : phpHost, phpPort);
  log('frankenphp_ready');

  if (proxy) {
    await supervisor.startProxy(host, port, '127.0.0.1', phpPort);
    log('proxy_ready');
  }
}

main().catch((error) => {
  log('fatal', { error: error.message });
  process.exit(1);
});
