'use strict';
// Arco runtime for AWS Lambda Web Functions: loads the variable bundle, resolves secrets, then runs FrankenPHP (or, for the process kind, the customer's binary).
const nodeFs = require('node:fs');
const path = require('node:path');
const { loadEnvironment } = require('./lib/boot');
const { buildCaddyfile } = require('./lib/caddyfile');
const supervisor = require('./lib/supervisor');
const processKind = require('./lib/process');

const ROOT = __dirname;
const TMP = '/tmp/arco';
const started = Date.now();
const defaultLog = (phase, data = {}) => console.log(JSON.stringify({ arco: phase, ms: Date.now() - started, ...data }));

// deps: { fs, log } are injectable so tests can run the startup path without touching the disk.
async function main({ fs = nodeFs, log = defaultLog } = {}) {
  const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'arco-runtime.json'), 'utf8'));
  const [host, portString] = (process.env.AWS_LAMBDA_HTTP_ENDPOINT || '0.0.0.0:3000').split(':');
  const port = Number(portString);
  log('boot', { mode: config.mode, endpoint: `${host}:${port}` });

  await loadEnvironment(log);

  if (config.kind === 'process') {
    // No Laravel storage/ or caddy/ folders: only bin/ for an executable copy.
    supervisor.prepareTmp(TMP, ['bin']);
    await processKind.startProcess(ROOT, config, process.env, log);
    await supervisor.startProxy(host, port, processKind.HOST, processKind.PORT, {
      blockInternal: true,
      requestIds: true,
      accessLog: config.accessLog === true,
    });
    log('proxy_ready');
    return;
  }

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
    { accessLog: config.accessLog === true },
  );
  await supervisor.waitForPort(phpHost === '0.0.0.0' ? '127.0.0.1' : phpHost, phpPort);
  log('frankenphp_ready');

  if (proxy) {
    await supervisor.startProxy(host, port, '127.0.0.1', phpPort);
    log('proxy_ready');
  }
}

// Start on load, whoever loads this file: the Lambda Web Functions runtime may launch it as the main module or
// through require(), and a runtime that never starts would break every app. Tests set the opt-out before requiring.
if (globalThis.__ARCO_NO_AUTOSTART__ !== true) {
  main().catch((error) => {
    defaultLog('fatal', { error: error.message });
    process.exit(1);
  });
}

module.exports = { main };
