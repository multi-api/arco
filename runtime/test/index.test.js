'use strict';
// index.js main() and telemetry: child_process, net.connect, fs, the environment loader and the Caddy line counter
// are stubbed; packets go to a real UDP socket on 127.0.0.1 through ARCO_TELEMETRY_HOST.
const test = require('node:test');
const assert = require('node:assert/strict');
const childProcess = require('node:child_process');
const dgram = require('node:dgram');
const { EventEmitter, once } = require('node:events');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');

const RUNTIME = path.join(__dirname, '..');
const INDEX = path.join(RUNTIME, 'index.js');
const LIB = (name) => path.join(RUNTIME, 'lib', `${name}.js`);
const MODULES = [INDEX, LIB('supervisor'), LIB('process'), LIB('boot'), LIB('caddycount')];

const LARAVEL = { mode: 'direct', publicDir: 'app/public', threads: 4, workers: { min: 2, max: 8 }, phpIni: {}, arcoVersion: '0.21.0' };

async function collector(t) {
  const socket = dgram.createSocket('udp4');
  socket.bind(0, '127.0.0.1');
  await once(socket, 'listening');
  const packets = [];
  socket.on('message', (message) => packets.push(message.toString()));
  t.after(() => socket.close());
  return { host: `127.0.0.1:${socket.address().port}`, packets };
}

// Runs main() with `config`; `variables` is what the stubbed loadEnvironment() adds to process.env, as a bundle would.
async function run(t, { config, variables = {}, counterFails = false }) {
  const out = { writes: [], counters: [], proxy: [], logs: [], loaded: false };
  const { host, packets } = await collector(t);
  out.packets = packets;
  const patches = [];
  const patch = (target, key, value) => { patches.push([target, key, target[key]]); target[key] = value; };
  patch(childProcess, 'spawn', () => {
    const child = new EventEmitter();
    child.kill = () => {};
    return child;
  });
  patch(net, 'connect', () => {
    const socket = new EventEmitter();
    socket.destroy = () => {};
    setImmediate(() => socket.emit('connect'));
    return socket;
  });
  patch(fs, 'mkdirSync', () => {});
  patch(fs, 'accessSync', () => {});
  patch(process, 'exit', () => {});
  const savedEnv = { ...process.env };
  for (const key of Object.keys(process.env)) delete process.env[key];
  Object.assign(process.env, { PATH: '/usr/bin', AWS_LAMBDA_HTTP_ENDPOINT: '127.0.0.1:9000', AWS_REGION: 'us-east-1', ARCO_TELEMETRY_HOST: host });
  const signalListeners = Object.fromEntries(['SIGTERM', 'SIGINT'].map((s) => [s, process.listeners(s)]));
  for (const file of MODULES) delete require.cache[file];
  globalThis.__ARCO_NO_AUTOSTART__ = true;
  try {
    const boot = require(LIB('boot'));
    patch(boot, 'loadEnvironment', async () => { Object.assign(process.env, variables); out.loaded = true; });
    const caddycount = require(LIB('caddycount'));
    patch(caddycount, 'listenCaddyCount', async (onLine) => {
      out.counters.push(onLine);
      return counterFails ? null : { server: new EventEmitter(), port: 40123 };
    });
    const supervisor = require(LIB('supervisor'));
    patch(supervisor, 'startProxy', async (...args) => { out.proxy.push(args); });
    const { main } = require(INDEX);
    const fakeFs = {
      readFileSync: () => JSON.stringify(config),
      writeFileSync: (file, content) => { out.writes.push({ file, content }); },
    };
    await main({ fs: fakeFs, log: (phase, data = {}) => out.logs.push({ phase, data }) });
    out.sigterm = process.listeners('SIGTERM').filter((l) => !signalListeners.SIGTERM.includes(l));
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

const until = async (check) => {
  for (let i = 0; i < 100 && !check(); i++) await new Promise((resolve) => setTimeout(resolve, 10));
};

test('index.js reads the switch after loadEnvironment: a bundled ARCO_TELEMETRY_DISABLE=1 turns it off', async (t) => {
  const out = await run(t, { config: LARAVEL, variables: { ARCO_TELEMETRY_DISABLE: '1' } });
  assert.equal(out.loaded, true);
  assert.doesNotMatch(out.writes[0].content, /arco_count/);
  assert.deepEqual(out.counters, []);
});

test('"telemetry": false in arco-runtime.json turns it off', async (t) => {
  const out = await run(t, { config: { ...LARAVEL, telemetry: false } });
  assert.doesNotMatch(out.writes[0].content, /arco_count/);
  assert.deepEqual(out.counters, []);
  assert.deepEqual(out.proxy, []);
});

test('direct mode: Caddy lines are counted as web invocations and the first one sends a cold packet', async (t) => {
  const out = await run(t, { config: LARAVEL });
  assert.match(out.writes[0].content, /log arco_count {\n\t\toutput net 127\.0\.0\.1:40123 {/);
  assert.equal(out.counters.length, 1);
  out.counters[0]();
  await until(() => out.packets.length > 0);
  assert.match(out.packets[0], /^arco1 r=laravel k=web v=0\.21\.0 g=us-east-1 i=1 c=1 b=[0-3]$/);
});

test('direct mode: a counter that cannot listen leaves the Caddyfile exactly as without telemetry', async (t) => {
  const failed = await run(t, { config: LARAVEL, counterFails: true });
  const off = await run(t, { config: { ...LARAVEL, telemetry: false } });
  assert.equal(failed.counters.length, 1);
  assert.doesNotMatch(failed.writes[0].content, /arco_count|output discard/);
  assert.equal(failed.writes[0].content, off.writes[0].content);
  assert.ok(failed.logs.some(({ phase }) => phase === 'telemetry_counter_failed'));
});

test('proxy mode counts in startProxy onResponse', async (t) => {
  const out = await run(t, { config: { ...LARAVEL, mode: 'proxy' } });
  assert.deepEqual(out.counters, []);
  assert.equal(out.proxy.length, 1);
  out.proxy[0][4].onResponse();
  await until(() => out.packets.length > 0);
  assert.match(out.packets[0], /^arco1 r=laravel k=web v=0\.21\.0 g=us-east-1 i=1 c=1 b=[0-3]$/);
});

test('process apps count in startProxy onResponse as r=process', async (t) => {
  const out = await run(t, { config: { kind: 'process', command: ['bin/app'], healthPath: '/', arcoVersion: '0.21.0' } });
  assert.equal(typeof out.proxy[0][4].onResponse, 'function');
  assert.equal(out.proxy[0][4].blockInternal, true);
  out.proxy[0][4].onResponse();
  await until(() => out.packets.length > 0);
  assert.match(out.packets[0], /^arco1 r=process k=web v=0\.21\.0 g=us-east-1 i=1 c=1 b=[0-3]$/);
});

test('SIGTERM flushes the pending count', async (t) => {
  const out = await run(t, { config: { ...LARAVEL, mode: 'proxy' } });
  const { onResponse } = out.proxy[0][4];
  onResponse();
  await until(() => out.packets.length > 0);
  onResponse();
  onResponse();
  assert.equal(out.packets.length, 1, 'within the minute nothing more is sent');
  // spawnProcess's listener (forwards the signal to the stubbed FrankenPHP), then the telemetry's: Node's default
  // SIGTERM exit is never replaced by the telemetry's alone.
  assert.equal(out.sigterm.length, 2);
  assert.equal(out.sigterm[1].toString(), '() => telemetry.flush()');
  for (const listener of out.sigterm) listener('SIGTERM');
  await until(() => out.packets.length > 1);
  assert.equal(out.packets[1], 'arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=2 c=0');
});
