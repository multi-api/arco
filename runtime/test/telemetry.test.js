'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { EventEmitter } = require('node:events');
const {
  createTelemetry, encodePacket, bootBucket, DEFAULT_HOST, DEFAULT_PORT, SEND_INTERVAL_MS,
} = require('../lib/telemetry');

// Shared with the PHP runtime's encoder tests: both must produce these lines byte for byte.
const FIXTURE = path.join(__dirname, '../../../../tests/Fixtures/telemetry-packets.json');

const ENV = { AWS_REGION: 'us-east-1' };
const CONFIG = { arcoVersion: '0.21.0' };

// A socket that records what it was asked to send.
function fakeSocket() {
  const socket = new EventEmitter();
  socket.sent = [];
  socket.unrefCalls = 0;
  socket.unref = () => { socket.unrefCalls++; return socket; };
  socket.send = (message, port, address, callback) => {
    socket.sent.push({ line: message.toString(), port, address });
    if (callback) callback(null);
  };
  return socket;
}

// A lookup that answers at once (dns.lookup answers later: see the "before the lookup resolves" test).
function fakeLookup(address = '203.0.113.7') {
  const lookup = (host, options, callback) => {
    lookup.calls.push(host);
    callback(null, address, 4);
  };
  lookup.calls = [];
  return lookup;
}

function setup({ env = ENV, config = CONFIG, runtime = 'laravel', kind = 'web', bootMs = 1200, lookup = fakeLookup() } = {}) {
  const clock = { now: 1_000_000 };
  const socket = fakeSocket();
  const telemetry = createTelemetry({ env, config, runtime, kind, bootMs, now: () => clock.now, socket, lookup });
  return { telemetry, socket, lookup, clock, lines: () => socket.sent.map((s) => s.line) };
}

test('exports the collector address and the send interval', () => {
  assert.equal(DEFAULT_HOST, 'telemetry.arcocloud.app');
  assert.equal(DEFAULT_PORT, 41700);
  assert.equal(SEND_INTERVAL_MS, 60000);
});

test('encodePacket gives exactly the spec examples', () => {
  assert.equal(
    encodePacket({ runtime: 'laravel', kind: 'web', version: '0.21.0', region: 'us-east-1', invocations: 1342, cold: false, boot: 0 }),
    'arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1342 c=0',
  );
  assert.equal(
    encodePacket({ runtime: 'process', kind: 'queue', version: '0.21.0', region: 'sa-east-1', invocations: 1, cold: true, boot: 2 }),
    'arco1 r=process k=queue v=0.21.0 g=sa-east-1 i=1 c=1 b=2',
  );
});

test('encodePacket matches every line of the shared fixture', () => {
  const { packets } = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
  assert.ok(packets.length >= 2);
  for (const { fields, packet } of packets) {
    assert.equal(encodePacket(fields), packet);
    assert.ok(Buffer.byteLength(packet) < 200);
  }
});

test('bootBucket puts the boot duration in four buckets', () => {
  assert.equal(bootBucket(0), 0);
  assert.equal(bootBucket(499), 0);
  assert.equal(bootBucket(500), 1);
  assert.equal(bootBucket(999), 1);
  assert.equal(bootBucket(1000), 2);
  assert.equal(bootBucket(2999), 2);
  assert.equal(bootBucket(3000), 3);
  assert.equal(bootBucket(60000), 3);
  assert.equal(bootBucket(undefined), 0);
  assert.equal(bootBucket(-5), 0);
});

test('the first tick sends a cold-start packet to the collector at once', () => {
  const { telemetry, socket, lookup, lines } = setup();
  assert.equal(telemetry.enabled, true);
  telemetry.count();
  telemetry.tick();
  assert.deepEqual(lines(), ['arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1 c=1 b=2']);
  assert.deepEqual(lookup.calls, [DEFAULT_HOST]);
  assert.equal(socket.sent[0].port, DEFAULT_PORT);
  assert.equal(socket.sent[0].address, '203.0.113.7');
});

test('within 60 seconds a tick sends nothing; after them it sends the sum, warm', () => {
  const { telemetry, clock, lines, lookup } = setup();
  telemetry.count();
  telemetry.tick();
  clock.now += 30_000;
  telemetry.count();
  telemetry.count();
  telemetry.tick();
  assert.equal(lines().length, 1);
  clock.now += 29_999;
  telemetry.count();
  telemetry.tick();
  assert.equal(lines().length, 1);
  clock.now += 1;
  telemetry.tick();
  assert.deepEqual(lines().slice(1), ['arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=3 c=0']);
  assert.equal(lookup.calls.length, 1, 'resolves the collector once per environment');
});

test('sends nothing when nothing was counted', () => {
  const { telemetry, clock, lines, lookup } = setup();
  telemetry.tick();
  assert.deepEqual(lines(), []);
  assert.deepEqual(lookup.calls, [], 'no lookup before there is something to send');
  telemetry.count();
  telemetry.tick();
  clock.now += 120_000;
  telemetry.tick();
  telemetry.flush();
  assert.equal(lines().length, 1);
});

test('keeps one counter per kind and sends one packet for each', () => {
  const { telemetry, clock, lines } = setup({ runtime: 'process', kind: 'queue', bootMs: 100 });
  telemetry.count();
  telemetry.count('schedule');
  telemetry.tick();
  assert.deepEqual(lines(), [
    'arco1 r=process k=queue v=0.21.0 g=us-east-1 i=1 c=1 b=0',
    'arco1 r=process k=schedule v=0.21.0 g=us-east-1 i=1 c=0',
  ]);
  clock.now += 60_000;
  telemetry.count('schedule');
  telemetry.tick();
  assert.deepEqual(lines().slice(2), ['arco1 r=process k=schedule v=0.21.0 g=us-east-1 i=1 c=0']);
});

test('ignores a kind that is not one of the four', () => {
  const { telemetry, lines } = setup();
  telemetry.count('anything');
  telemetry.count();
  telemetry.tick();
  assert.deepEqual(lines(), ['arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1 c=1 b=2']);
});

test('flush sends what is pending without waiting for the interval', () => {
  const { telemetry, clock, lines } = setup();
  telemetry.count();
  telemetry.tick();
  clock.now += 5_000;
  telemetry.count();
  telemetry.count();
  telemetry.flush();
  assert.deepEqual(lines().slice(1), ['arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=2 c=0']);
  telemetry.flush();
  assert.equal(lines().length, 2);
});

for (const [name, env, config] of [
  ['ARCO_TELEMETRY_DISABLE=1', { ...ENV, ARCO_TELEMETRY_DISABLE: '1' }, CONFIG],
  ['"telemetry": false in arco-runtime.json', ENV, { ...CONFIG, telemetry: false }],
  ['no arcoVersion in arco-runtime.json', ENV, {}],
  ['no AWS_REGION', {}, CONFIG],
]) {
  test(`${name} sends nothing and never resolves the collector`, () => {
    const { telemetry, clock, lines, lookup } = setup({ env, config });
    assert.equal(telemetry.enabled, false);
    telemetry.count();
    telemetry.tick();
    clock.now += 60_000;
    telemetry.count();
    telemetry.tick();
    telemetry.flush();
    assert.deepEqual(lines(), []);
    assert.deepEqual(lookup.calls, []);
  });
}

test('a runtime that is not laravel or process turns telemetry off', () => {
  const { telemetry, lines, lookup } = setup({ runtime: 'python' });
  assert.equal(telemetry.enabled, false);
  telemetry.prepare();
  telemetry.count();
  telemetry.tick();
  assert.deepEqual(lines(), []);
  assert.deepEqual(lookup.calls, []);
});

test('prepare resolves the collector ahead of time and sends nothing', () => {
  const { telemetry, clock, lines, lookup } = setup();
  assert.equal(telemetry.prepare(), undefined);
  assert.deepEqual(lookup.calls, [DEFAULT_HOST]);
  assert.deepEqual(lines(), []);
  telemetry.prepare();
  assert.equal(lookup.calls.length, 1, 'resolves once');
  clock.now += 5_000;
  telemetry.count();
  telemetry.tick();
  assert.deepEqual(lines(), ['arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1 c=1 b=2']);
  assert.equal(lookup.calls.length, 1, 'no second lookup');
});

test('prepare does not start a second lookup while one is in flight', () => {
  let answer;
  const lookup = (host, options, callback) => { lookup.calls.push(host); answer = () => callback(null, '203.0.113.7', 4); };
  lookup.calls = [];
  const { telemetry, lines } = setup({ lookup });
  telemetry.prepare();
  telemetry.prepare();
  telemetry.count();
  telemetry.tick();
  assert.equal(lookup.calls.length, 1);
  answer();
  assert.deepEqual(lines(), ['arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1 c=1 b=2']);
});

test('prepare does nothing when telemetry is off or the lookup failed', () => {
  const off = setup({ env: { ...ENV, ARCO_TELEMETRY_DISABLE: '1' } });
  off.telemetry.prepare();
  assert.deepEqual(off.lookup.calls, []);

  const lookup = (host, options, callback) => { lookup.calls.push(host); callback(new Error('ENOTFOUND')); };
  lookup.calls = [];
  const failed = setup({ lookup });
  failed.telemetry.prepare();
  assert.equal(failed.telemetry.enabled, false);
  failed.telemetry.prepare();
  failed.telemetry.count();
  failed.telemetry.tick();
  assert.equal(lookup.calls.length, 1);
  assert.deepEqual(failed.lines(), []);
});

test('works without a config or env at all', () => {
  const telemetry = createTelemetry({ runtime: 'laravel', kind: 'web', bootMs: 0, socket: fakeSocket(), lookup: fakeLookup() });
  assert.equal(telemetry.enabled, false);
  telemetry.count();
  telemetry.tick();
});

test('ARCO_TELEMETRY_HOST overrides the collector host and port', () => {
  const { telemetry, socket, lookup } = setup({ env: { ...ENV, ARCO_TELEMETRY_HOST: '127.0.0.1:9999' }, lookup: fakeLookup('127.0.0.1') });
  telemetry.count();
  telemetry.tick();
  assert.deepEqual(lookup.calls, ['127.0.0.1']);
  assert.equal(socket.sent[0].address, '127.0.0.1');
  assert.equal(socket.sent[0].port, 9999);
});

test('ARCO_TELEMETRY_HOST without a port keeps the default port', () => {
  const { telemetry, socket, lookup } = setup({ env: { ...ENV, ARCO_TELEMETRY_HOST: 'collector.test' } });
  telemetry.count();
  telemetry.tick();
  assert.deepEqual(lookup.calls, ['collector.test']);
  assert.equal(socket.sent[0].port, DEFAULT_PORT);
});

test('a failed lookup turns telemetry off for good, silently', () => {
  const lookup = (host, options, callback) => { lookup.calls.push(host); callback(new Error('ENOTFOUND')); };
  lookup.calls = [];
  const { telemetry, clock, lines } = setup({ lookup });
  telemetry.count();
  assert.doesNotThrow(() => telemetry.tick());
  assert.equal(telemetry.enabled, false);
  clock.now += 60_000;
  telemetry.count();
  telemetry.tick();
  telemetry.flush();
  assert.deepEqual(lines(), []);
  assert.equal(lookup.calls.length, 1, 'does not try again');
});

test('a lookup that throws turns telemetry off too', () => {
  const { telemetry, lines } = setup({ lookup: () => { throw new Error('bad host'); } });
  telemetry.count();
  assert.doesNotThrow(() => telemetry.tick());
  assert.equal(telemetry.enabled, false);
  assert.deepEqual(lines(), []);
});

test('counts made before the lookup resolves are sent once it does', () => {
  let answer;
  const lookup = (host, options, callback) => { lookup.calls.push(host); answer = () => callback(null, '203.0.113.7', 4); };
  lookup.calls = [];
  const { telemetry, lines } = setup({ lookup });
  telemetry.count();
  assert.equal(telemetry.tick(), undefined);
  telemetry.count();
  telemetry.tick();
  telemetry.flush();
  assert.deepEqual(lines(), []);
  assert.equal(lookup.calls.length, 1);
  answer();
  assert.deepEqual(lines(), ['arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=2 c=1 b=2']);
});

test('send never throws and never awaits when the socket errors', () => {
  const socket = new EventEmitter();
  socket.unref = () => socket;
  socket.attempts = 0;
  socket.send = () => {
    socket.attempts++;
    socket.emit('error', new Error('ENETUNREACH'));
    throw new Error('EBADF');
  };
  const clock = { now: 0 };
  const telemetry = createTelemetry({ env: ENV, config: CONFIG, runtime: 'laravel', kind: 'web', bootMs: 0, now: () => clock.now, socket, lookup: fakeLookup() });
  telemetry.count();
  let result;
  assert.doesNotThrow(() => { result = telemetry.tick(); });
  assert.equal(result, undefined, 'returns nothing, not a promise');
  clock.now += 60_000;
  telemetry.count();
  assert.doesNotThrow(() => { result = telemetry.flush(); });
  assert.equal(result, undefined);
  assert.equal(telemetry.count(), undefined);
  assert.equal(socket.attempts, 2);
  assert.doesNotThrow(() => socket.emit('error', new Error('late error')), 'an error handler is attached');
});

test('unrefs the socket so it never keeps the process alive', () => {
  const { telemetry, socket } = setup();
  telemetry.count();
  telemetry.tick();
  assert.equal(socket.unrefCalls, 1);
  assert.ok(socket.listenerCount('error') >= 1);
});

test('the default socket and lookup send a real datagram', async () => {
  const dgram = require('node:dgram');
  const server = dgram.createSocket('udp4');
  await new Promise((resolve) => server.bind(0, '127.0.0.1', resolve));
  const received = new Promise((resolve) => server.once('message', (message) => resolve(message.toString())));
  const telemetry = createTelemetry({
    env: { ...ENV, ARCO_TELEMETRY_HOST: `127.0.0.1:${server.address().port}` }, config: CONFIG, runtime: 'process', kind: 'web', bootMs: 700,
  });
  telemetry.count();
  telemetry.tick();
  assert.equal(await received, 'arco1 r=process k=web v=0.21.0 g=us-east-1 i=1 c=1 b=1');
  server.close();
});

test('booted() sets the boot bucket when the boot ends after the telemetry was made', () => {
  const { telemetry, lines } = setup({ bootMs: 0 });
  telemetry.booted(2500);
  telemetry.count();
  telemetry.tick();
  assert.deepEqual(lines(), ['arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1 c=1 b=2']);
});

test('booted() ignores what is not a duration and never throws', () => {
  const { telemetry, lines } = setup({ bootMs: 1200 });
  telemetry.booted('soon');
  telemetry.count();
  telemetry.tick();
  assert.deepEqual(lines(), ['arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1 c=1 b=2']);
});

test('the default clock is monotonic: a wall clock set back does not stop the minute', (t) => {
  const monotonic = { now: 5_000 };
  t.mock.method(performance, 'now', () => monotonic.now);
  const wall = t.mock.method(Date, 'now', () => 2_000_000_000_000);
  const socket = fakeSocket();
  const telemetry = createTelemetry({ env: ENV, config: CONFIG, runtime: 'laravel', kind: 'web', bootMs: 0, socket, lookup: fakeLookup() });
  telemetry.count();
  telemetry.tick();
  // The wall clock jumps back an hour; a minute passes on the monotonic one.
  wall.mock.mockImplementation(() => 2_000_000_000_000 - 3_600_000);
  monotonic.now += SEND_INTERVAL_MS;
  telemetry.count();
  telemetry.tick();
  assert.deepEqual(socket.sent.map((s) => s.line), [
    'arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1 c=1 b=0',
    'arco1 r=laravel k=web v=0.21.0 g=us-east-1 i=1 c=0',
  ]);
});
