'use strict';
// The process kind of the web runtime: index.js main() with child_process, net, http and fs stubbed.
const test = require('node:test');
const assert = require('node:assert/strict');
const childProcess = require('node:child_process');
const { EventEmitter } = require('node:events');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');

const RUNTIME = path.join(__dirname, '..');
const INDEX = path.join(RUNTIME, 'index.js');
const MODULES = [INDEX, path.join(RUNTIME, 'lib', 'supervisor.js'), path.join(RUNTIME, 'lib', 'process.js'), path.join(RUNTIME, 'lib', 'extensions.js')];

async function run({ config, executable = true, front = null, waitFor = null, refused = [], end = (out) => out.die(7) }) {
  const out = { spawn: [], connect: [], mkdir: [], writes: [], copies: [], chmods: [], logs: [], exits: [], proxy: [] };
  const children = [];
  const patches = [];
  const patch = (target, key, value) => { patches.push([target, key, target[key]]); target[key] = value; };
  patch(childProcess, 'spawn', (command, args, options) => {
    const child = new EventEmitter();
    child.kills = [];
    child.kill = (signal) => { child.kills.push(signal); };
    children.push(child);
    out.spawn.push({ command, args, cwd: options.cwd, env: options.env, stdio: options.stdio, connectedBefore: out.connect.map((c) => c.port) });
    return child;
  });
  patch(net, 'connect', (port, host) => {
    out.connect.push({ host, port });
    const socket = new EventEmitter();
    socket.destroy = () => {};
    setImmediate(() => socket.emit(refused.includes(port) ? 'error' : 'connect', new Error('ECONNREFUSED')));
    return socket;
  });
  patch(fs, 'mkdirSync', (dir, options) => { out.mkdir.push({ dir, options }); });
  patch(fs, 'accessSync', () => { if (!executable) throw new Error('EACCES'); });
  patch(fs, 'existsSync', () => false);
  patch(fs, 'copyFileSync', (from, to) => { out.copies.push({ from, to }); });
  patch(fs, 'chmodSync', (file, mode) => { out.chmods.push({ file, mode }); });
  patch(process, 'exit', (code) => { out.exits.push(code); });
  const savedEnv = { ...process.env };
  for (const key of Object.keys(process.env)) delete process.env[key];
  Object.assign(process.env, { PATH: '/usr/bin', AWS_LAMBDA_HTTP_ENDPOINT: '127.0.0.1:9000', AWS_REGION: 'us-east-1', APP_ENV: 'production' });
  const signalListeners = Object.fromEntries(['SIGTERM', 'SIGINT'].map((s) => [s, process.listeners(s)]));
  for (const file of MODULES) delete require.cache[file];
  globalThis.__ARCO_NO_AUTOSTART__ = true;
  try {
    const supervisor = require(MODULES[1]);
    patch(require(MODULES[3]), 'findFront', () => front);
    if (waitFor) patch(supervisor, 'waitForPort', waitFor);
    patch(supervisor, 'startProxy', async (...args) => { out.proxy.push(args); });
    const { main } = require(INDEX);
    const fakeFs = {
      readFileSync: () => JSON.stringify(config),
      writeFileSync: (file, content) => { out.writes.push({ file, content }); },
    };
    await main({ fs: fakeFs, log: (phase, data = {}) => out.logs.push({ phase, data }) });
    out.children = children;
    out.die = (code, signal = null) => children[0].emit('exit', code, signal);
    await end(out);
  } finally {
    delete globalThis.__ARCO_NO_AUTOSTART__;
    for (const [signal, keep] of Object.entries(signalListeners)) {
      for (const listener of process.listeners(signal)) if (!keep.includes(listener)) process.removeListener(signal, listener);
    }
    for (const [target, key, value] of patches.reverse()) target[key] = value;
    for (const key of Object.keys(process.env)) delete process.env[key];
    Object.assign(process.env, savedEnv);
    for (const file of MODULES) delete require.cache[file];
  }
  return out;
}

const CONFIG = { kind: 'process', command: ['bin/app'], healthPath: '/', accessLog: true };

test('the process kind spawns app/<command> on 127.0.0.1:8000 and proxies with the guards', async () => {
  const out = await run({ config: CONFIG });
  assert.equal(out.spawn.length, 1);
  assert.equal(out.spawn[0].command, path.join(RUNTIME, 'app/bin/app'));
  assert.deepEqual(out.spawn[0].args, []);
  assert.equal(out.spawn[0].cwd, path.join(RUNTIME, 'app'));
  assert.equal(out.spawn[0].env.PORT, '8000');
  assert.equal(out.spawn[0].env.HOST, '127.0.0.1');
  assert.equal(out.spawn[0].env.ARCO, '1');
  assert.equal(out.spawn[0].env.HOME, '/tmp/arco');
  assert.equal(out.spawn[0].env.APP_ENV, 'production');
  assert.deepEqual(out.connect, [{ host: '127.0.0.1', port: 8000 }]);
  assert.deepEqual(out.writes, [], 'no Caddyfile');
  assert.deepEqual(out.mkdir.map((m) => m.dir), ['/tmp/arco/bin']);
  assert.equal(out.proxy.length, 1);
  assert.deepEqual(out.proxy[0], ['127.0.0.1', 9000, '127.0.0.1', { port: 8000 }, { blockInternal: true, requestIds: true, accessLog: true }]);
  assert.ok(out.logs.some((l) => l.phase === 'process_ready'));
  assert.ok(out.logs.some((l) => l.phase === 'proxy_ready'));
});

test('the access log is off in the proxy options unless the config turns it on', async () => {
  const out = await run({ config: { kind: 'process', command: ['bin/app'], healthPath: '/' } });
  assert.deepEqual(out.proxy[0][4], { blockInternal: true, requestIds: true, accessLog: false });
});

test('command arguments are passed to the process', async () => {
  const out = await run({ config: { ...CONFIG, command: ['bin/app', '--serve', '-v'] } });
  assert.equal(out.spawn[0].command, path.join(RUNTIME, 'app/bin/app'));
  assert.deepEqual(out.spawn[0].args, ['--serve', '-v']);
});

test('a binary that lost its executable bit is copied to /tmp/arco/bin and run from there', async () => {
  const out = await run({ config: CONFIG, executable: false });
  assert.deepEqual(out.copies, [{ from: path.join(RUNTIME, 'app/bin/app'), to: '/tmp/arco/bin/app' }]);
  assert.deepEqual(out.chmods, [{ file: '/tmp/arco/bin/app', mode: 0o755 }]);
  assert.equal(out.spawn[0].command, '/tmp/arco/bin/app');
  assert.equal(out.spawn[0].cwd, path.join(RUNTIME, 'app'));
});

test('the process exiting ends the runtime with its exit code', async () => {
  const out = await run({ config: CONFIG });
  assert.deepEqual(out.exits, [7]);
  assert.deepEqual(out.logs.find((l) => l.phase === 'process_exit').data, { code: 7, signal: null });
});

test('SIGTERM from Lambda is forwarded and the runtime exits 0 when the process ends by the signal', async () => {
  const out = await run({
    config: CONFIG,
    end: (o) => {
      process.emit('SIGTERM', 'SIGTERM');
      // A Go binary without a signal handler dies by the signal: no code.
      o.die(null, 'SIGTERM');
    },
  });
  assert.deepEqual(out.children[0].kills, ['SIGTERM']);
  assert.deepEqual(out.exits, [0], 'a non-zero exit is reported by Lambda as Runtime.ExitError at spin-down');
  assert.deepEqual(out.logs.find((l) => l.phase === 'process_exit').data, { code: null, signal: 'SIGTERM', shutdown: true });
});

test('a process that handles SIGTERM and exits non-zero still ends a shutdown with 0', async () => {
  const out = await run({ config: CONFIG, end: (o) => { process.emit('SIGTERM', 'SIGTERM'); o.die(2); } });
  assert.deepEqual(out.exits, [0]);
});

test('a process that ignores SIGTERM is killed after the grace period and the runtime exits 0', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const out = await run({
    config: CONFIG,
    end: (o) => {
      process.emit('SIGTERM', 'SIGTERM');
      t.mock.timers.tick(999);
      assert.deepEqual(o.children[0].kills, ['SIGTERM']);
      t.mock.timers.tick(1);
      assert.deepEqual(o.children[0].kills, ['SIGTERM', 'SIGKILL']);
      o.die(null, 'SIGKILL');
    },
  });
  assert.deepEqual(out.exits, [0]);
});

test('a process that dies on its own, with no signal sent to the runtime, still ends it with code 1', async () => {
  const out = await run({ config: CONFIG, end: (o) => o.die(null, 'SIGKILL') });
  assert.deepEqual(out.exits, [1]);
});

test('isInternalPath is re-exported from lib/process.js', () => {
  const { isInternalPath, PORT } = require('../lib/process');
  assert.equal(PORT, 8000);
  assert.equal(isInternalPath('/%5Farco/x'), true);
  assert.equal(isInternalPath('/_arcox'), false);
});

const FRONT = { name: 'a-ext', dir: path.join(RUNTIME, 'app/.arco-cloud/a-ext'), command: ['bin/front', '--x'] };

test('starts app on 8001 and front on 8000 with ARCO_FRONT_LISTEN, ARCO_FRONT_UPSTREAM and ARCO_STARTED_AT', async () => {
  const out = await run({ config: CONFIG, front: FRONT, end: () => {} });
  assert.equal(out.spawn.length, 2);
  const [app, front] = out.spawn;
  assert.equal(app.env.PORT, '8001');
  assert.equal(app.cwd, path.join(RUNTIME, 'app'));
  assert.equal(front.command, path.join(FRONT.dir, 'bin/front'));
  assert.deepEqual(front.args, ['--x']);
  assert.equal(front.cwd, FRONT.dir);
  assert.equal(front.stdio, 'inherit');
  assert.equal(front.env.ARCO_FRONT_LISTEN, '127.0.0.1:8000');
  assert.equal(front.env.ARCO_FRONT_UPSTREAM, '127.0.0.1:8001');
  assert.match(front.env.ARCO_STARTED_AT, /^\d{13}$/);
  assert.equal(front.env.APP_ENV, 'production', 'the app environment is inherited');
  assert.equal(front.env.PORT, '8001');
  assert.deepEqual(out.connect.map((c) => c.port), [8001, 8000]);
  assert.deepEqual(out.proxy[0][3], { port: 8000 });
});

test('with a front, the front is spawned only after the app listens on 8001', async () => {
  const out = await run({ config: CONFIG, front: FRONT, end: () => {} });
  assert.deepEqual(out.spawn[0].connectedBefore, [], 'the app is spawned first');
  assert.deepEqual(out.spawn[1].connectedBefore, [8001], 'the front comes once the app answered on 8001');
  assert.equal(out.logs.filter((l) => l.phase === 'extension_failed').length, 0);
});

test('an app that binds 8000 despite PORT=8001 is served on 8000 and the front is not started', async () => {
  const out = await run({ config: CONFIG, front: FRONT, refused: [8001], end: () => {} });
  assert.equal(out.spawn.length, 1, 'no front spawned');
  assert.equal(out.spawn[0].env.PORT, '8001');
  assert.deepEqual(out.proxy[0][3], { port: 8000 });
  const failed = out.logs.filter((l) => l.phase === 'extension_failed');
  assert.equal(failed.length, 1);
  assert.equal(failed[0].data.reason, 'app_port');
  assert.equal(failed[0].data.extension, 'a-ext');
  assert.ok(out.logs.some((l) => l.phase === 'process_ready'));
  assert.deepEqual(out.exits, []);
});

test('without a front the app keeps PORT=8000', async () => {
  const out = await run({ config: CONFIG, end: () => {} });
  assert.equal(out.spawn.length, 1);
  assert.equal(out.spawn[0].env.PORT, '8000');
  assert.equal(out.spawn[0].env.ARCO_FRONT_LISTEN, undefined);
  assert.deepEqual(out.proxy[0][3], { port: 8000 });
});

test('falls back to 8001 when the front never listens within 2 s', async () => {
  const waited = [];
  const out = await run({
    config: CONFIG,
    front: FRONT,
    waitFor: async (host, port, timeout) => {
      waited.push({ port, timeout });
      if (port === 8000) throw new Error('did not listen');
    },
    end: () => {},
  });
  assert.deepEqual(waited, [{ port: 8000, timeout: 2000 }]);
  assert.equal(out.logs.filter((l) => l.phase === 'extension_failed').length, 1);
  assert.equal(out.proxy[0][3].port, 8001);
  assert.deepEqual(out.children[1].kills, ['SIGTERM'], 'the front that missed its wait is stopped');
  assert.deepEqual(out.children[0].kills, [], 'the app keeps running');
  assert.ok(out.logs.some((l) => l.phase === 'process_ready'));
});

test('switches to direct after the front exits, without ending the runtime', async () => {
  const out = await run({
    config: CONFIG,
    front: FRONT,
    end: (o) => {
      assert.equal(o.proxy[0][3].port, 8000);
      o.children[1].emit('exit', 1, null);
    },
  });
  assert.equal(out.proxy[0][3].port, 8001);
  assert.deepEqual(out.exits, []);
  assert.equal(out.logs.filter((l) => l.phase === 'extension_failed').length, 1);
});

test('a front that cannot be spawned leaves the app served directly', async () => {
  const out = await run({
    config: CONFIG,
    front: FRONT,
    end: (o) => { o.children[1].emit('error', new Error('ENOENT')); },
  });
  assert.equal(out.proxy[0][3].port, 8001);
  assert.deepEqual(out.exits, []);
  assert.equal(out.logs.filter((l) => l.phase === 'extension_failed').length, 1);
});

test('a front that missed the 2 s wait and later exits logs extension_failed once', async () => {
  const out = await run({
    config: CONFIG,
    front: FRONT,
    waitFor: async (host, port) => { if (port === 8000) throw new Error('did not listen'); },
    end: (o) => { o.children[1].emit('error', new Error('x')); o.children[1].emit('exit', 1, null); },
  });
  assert.equal(out.logs.filter((l) => l.phase === 'extension_failed').length, 1);
  assert.equal(out.logs.find((l) => l.phase === 'extension_failed').data.reason, 'not_listening');
  assert.equal(out.proxy[0][3].port, 8001);
  assert.deepEqual(out.exits, []);
});

test('SIGTERM reaches the front as well and the front ending is not a failure', async () => {
  const out = await run({
    config: CONFIG,
    front: FRONT,
    end: (o) => {
      process.emit('SIGTERM', 'SIGTERM');
      o.children[1].emit('exit', null, 'SIGTERM');
      o.die(null, 'SIGTERM');
    },
  });
  assert.deepEqual(out.exits, [0]);
  assert.equal(out.logs.filter((l) => l.phase === 'extension_failed').length, 0);
});

test('front command lacking the executable bit runs from a /tmp/arco/bin copy', async () => {
  const out = await run({ config: CONFIG, front: FRONT, executable: false, end: () => {} });
  assert.deepEqual(out.copies.map((c) => c.to), ['/tmp/arco/bin/app', '/tmp/arco/bin/a-ext-front']);
  assert.equal(out.copies[1].from, path.join(FRONT.dir, 'bin/front'));
  assert.equal(out.spawn[1].command, '/tmp/arco/bin/a-ext-front');
  assert.equal(out.spawn[1].cwd, FRONT.dir);
});
