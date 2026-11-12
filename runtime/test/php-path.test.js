'use strict';
// Records what the PHP startup path (index.js main -> supervisor) does, with child_process, net, http, fs and
// process.exit stubbed. The fixture was taken before the supervisor refactor; the refactor must not change it.
// Re-record with RECORD=1 only when the PHP path is meant to change. bin/test-docker does not forward RECORD, so run:
// docker run --rm --init --network none --user "$(id -u):$(id -g)" --mount "type=bind,source=$PWD,target=/app" --workdir /app -e RECORD=1 arco-test:php84 node --test packages/arco/runtime/test/php-path.test.js
const test = require('node:test');
const assert = require('node:assert/strict');
const childProcess = require('node:child_process');
const { EventEmitter } = require('node:events');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const path = require('node:path');
const { PassThrough } = require('node:stream');

const FIXTURE = path.join(__dirname, 'fixtures', 'php-path.json');
const INDEX = path.join(__dirname, '..', 'index.js');
const SUPERVISOR = path.join(__dirname, '..', 'lib', 'supervisor.js');
const ENV = { PATH: '/usr/bin', AWS_LAMBDA_HTTP_ENDPOINT: '127.0.0.1:9000', AWS_REGION: 'us-east-1', APP_ENV: 'production' };

const CONFIGS = {
  worker: {
    mode: 'direct',
    publicDir: 'app/public',
    workerScript: 'app/public/frankenphp-worker.php',
    threads: 8,
    workers: { min: 4, max: 64 },
    phpIni: { memory_limit: '512M' },
    accessLog: true,
  },
  proxy: {
    mode: 'proxy',
    publicDir: 'app/public',
    threads: 4,
    workers: { min: 2, max: 8 },
    phpIni: {},
  },
};

async function record(config) {
  const out = { spawn: [], connect: [], mkdir: [], writes: [], accessChecks: [], logs: [], listen: [], exits: [] };
  const children = [];
  const patches = [];
  const patch = (target, key, value) => { patches.push([target, key, target[key]]); target[key] = value; };

  patch(childProcess, 'spawn', (command, args, options) => {
    const child = new EventEmitter();
    child.stdout = new PassThrough();
    child.kill = () => {};
    children.push(child);
    out.spawn.push({
      command,
      args,
      cwd: options.cwd,
      stdio: options.stdio,
      env: Object.fromEntries(Object.entries(options.env).sort(([a], [b]) => (a < b ? -1 : 1))),
    });
    return child;
  });
  patch(net, 'connect', (port, host) => {
    out.connect.push({ host, port });
    const socket = new EventEmitter();
    socket.destroy = () => {};
    setImmediate(() => socket.emit('connect'));
    return socket;
  });
  patch(http, 'createServer', () => {
    const server = {
      listen(port, host, callback) {
        out.listen.push({ host, port, keepAliveTimeout: server.keepAliveTimeout });
        callback();
      },
    };
    return server;
  });
  patch(fs, 'mkdirSync', (dir, options) => { out.mkdir.push({ dir, options }); });
  patch(fs, 'accessSync', (file, mode) => { out.accessChecks.push({ file, mode }); });
  patch(process, 'exit', (code) => { out.exits.push(code); });
  const savedEnv = { ...process.env };
  for (const key of Object.keys(process.env)) delete process.env[key];
  Object.assign(process.env, ENV);

  const signalListeners = Object.fromEntries(['SIGTERM', 'SIGINT'].map((s) => [s, process.listeners(s)]));
  delete require.cache[INDEX];
  delete require.cache[SUPERVISOR];
  globalThis.__ARCO_NO_AUTOSTART__ = true;
  try {
    const { main } = require(INDEX);
    const fakeFs = {
      readFileSync: () => JSON.stringify(config),
      writeFileSync: (file, content) => { out.writes.push({ file, content }); },
    };
    await main({ fs: fakeFs, log: (phase, data = {}) => out.logs.push({ phase, data }) });
    // The child dies: the supervisor logs it and ends the runtime with the child's exit code.
    children[0].emit('exit', 3, null);
    out.signals = Object.fromEntries(['SIGTERM', 'SIGINT'].map((s) => [s, process.listeners(s).length - signalListeners[s].length]));
  } finally {
    delete globalThis.__ARCO_NO_AUTOSTART__;
    for (const [signal, keep] of Object.entries(signalListeners)) {
      for (const listener of process.listeners(signal)) if (!keep.includes(listener)) process.removeListener(signal, listener);
    }
    for (const [target, key, value] of patches.reverse()) target[key] = value;
    for (const key of Object.keys(process.env)) delete process.env[key];
    Object.assign(process.env, savedEnv);
    delete require.cache[INDEX];
    delete require.cache[SUPERVISOR];
  }
  return out;
}

test('the PHP startup path matches the recorded fixture', async () => {
  const recorded = {};
  for (const [name, config] of Object.entries(CONFIGS)) recorded[name] = await record(config);
  // The runtime directory differs between checkouts; the fixture names it <runtime>.
  const actual = JSON.parse(JSON.stringify(recorded).split(JSON.stringify(path.dirname(INDEX)).slice(1, -1)).join('<runtime>'));
  if (process.env.RECORD === '1') {
    fs.writeFileSync(FIXTURE, `${JSON.stringify(actual, null, 2)}\n`);
    return;
  }
  assert.deepEqual(actual, JSON.parse(fs.readFileSync(FIXTURE, 'utf8')));
});
