'use strict';
// Arco runtime for AWS Lambda Web Functions: loads the variable bundle, resolves secrets, then runs FrankenPHP (or, for the process kind, the customer's binary).
const nodeFs = require('node:fs');
const path = require('node:path');
const { loadEnvironment } = require('./lib/boot');
const { buildCaddyfile } = require('./lib/caddyfile');
const supervisor = require('./lib/supervisor');
const processKind = require('./lib/process');
const caddycount = require('./lib/caddycount');
const { createTelemetry } = require('./lib/telemetry');

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
  // After loadEnvironment(): ARCO_TELEMETRY_DISABLE may come from the variable bundle or a secret.
  const telemetry = startTelemetry(config);
  // One web invocation per finished response; null when telemetry is off, so nothing is wired.
  const countResponse = telemetry ? () => { telemetry.count(); telemetry.tick(); } : null;

  if (config.kind === 'process') {
    // No Laravel storage/ or caddy/ folders: only bin/ for an executable copy.
    supervisor.prepareTmp(TMP, ['bin']);
    await processKind.startProcess(ROOT, config, process.env, log);
    flushOnSigterm(telemetry);
    await supervisor.startProxy(host, port, processKind.HOST, processKind.PORT, {
      blockInternal: true,
      requestIds: true,
      accessLog: config.accessLog === true,
      ...(countResponse ? { onResponse: countResponse } : {}),
    });
    log('proxy_ready');
    if (telemetry) telemetry.booted(Date.now() - started);
    return;
  }

  supervisor.prepareTmp(TMP);
  const proxy = config.mode === 'proxy';
  const phpHost = proxy ? '127.0.0.1' : host;
  const phpPort = proxy ? 8000 : port;
  const caddyfile = path.join(TMP, 'Caddyfile');
  // Direct mode counts Caddy's arco_count lines; the counter must listen before the Caddyfile names its port. One that
  // cannot listen leaves the Caddyfile exactly as without telemetry.
  const counter = countResponse && !proxy ? await caddycount.listenCaddyCount(countResponse) : null;
  if (countResponse && !proxy && counter === null) log('telemetry_counter_failed');
  fs.writeFileSync(caddyfile, buildCaddyfile(config, { host: phpHost, port: phpPort, root: ROOT, countPort: counter ? counter.port : null, timeout: Number(process.env.ARCO_TIMEOUT) }));

  supervisor.spawnFrankenphp(
    supervisor.resolveBinary(ROOT, TMP),
    caddyfile,
    { ...process.env, ARCO: '1', HOME: TMP, XDG_CONFIG_HOME: `${TMP}/caddy/config`, XDG_DATA_HOME: `${TMP}/caddy/data` },
    path.join(ROOT, 'app'),
    log,
    { accessLog: config.accessLog === true },
  );
  flushOnSigterm(telemetry);
  await supervisor.waitForPort(phpHost === '0.0.0.0' ? '127.0.0.1' : phpHost, phpPort);
  log('frankenphp_ready');
  if (telemetry && !proxy) telemetry.booted(Date.now() - started);

  if (proxy) {
    await supervisor.startProxy(host, port, '127.0.0.1', phpPort, countResponse ? { onResponse: countResponse } : {});
    log('proxy_ready');
    if (telemetry) telemetry.booted(Date.now() - started);
  }
}

// The anonymous invocation count (lib/telemetry.js), or null when it is off. The collector is resolved now, before the
// first request.
function startTelemetry(config) {
  try {
    const telemetry = createTelemetry({ env: process.env, config, runtime: config.kind === 'process' ? 'process' : 'laravel', kind: 'web' });
    if (!telemetry.enabled) return null;
    telemetry.prepare();
    return telemetry;
  } catch {
    return null;
  }
}

// SIGTERM sends what is pending. Registered once the child's own handlers are, so Node's default SIGTERM exit is
// never replaced by this listener alone.
function flushOnSigterm(telemetry) {
  if (telemetry) process.on('SIGTERM', () => telemetry.flush());
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
