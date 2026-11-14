'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const net = require('node:net');
const { once } = require('node:events');
const { startCaddyCount, listenCaddyCount, HOST, PORT } = require('../lib/caddycount');

const settle = () => new Promise((resolve) => setTimeout(resolve, 50));

async function counter(t) {
  let count = 0;
  const server = startCaddyCount(() => { count += 1; }, { port: 0 });
  await once(server, 'listening');
  t.after(() => server.close());
  return { port: server.address().port, count: () => count };
}

test('listens on 127.0.0.1 on an ephemeral port by default', () => {
  assert.equal(HOST, '127.0.0.1');
  assert.equal(PORT, 0);
});

test('listenCaddyCount resolves the port once listening, and the counter counts there', async (t) => {
  let count = 0;
  const counter = await listenCaddyCount(() => { count += 1; });
  t.after(() => counter.server.close());
  assert.ok(Number.isInteger(counter.port) && counter.port > 0);
  assert.equal(counter.server.address().address, '127.0.0.1');
  const socket = net.connect(counter.port, '127.0.0.1');
  await once(socket, 'connect');
  socket.end('a\nb\n');
  await once(socket, 'close');
  await settle();
  assert.equal(count, 2);
});

test('listenCaddyCount resolves null when it cannot listen', async (t) => {
  const taken = net.createServer().listen(0, '127.0.0.1');
  await once(taken, 'listening');
  t.after(() => taken.close());
  assert.equal(await listenCaddyCount(() => {}, { port: taken.address().port }), null);
});

test('counts one per line, whatever the chunks', async (t) => {
  const { port, count } = await counter(t);
  const socket = net.connect(port, '127.0.0.1');
  await once(socket, 'connect');
  socket.write('{"a":1}\n{"b"');
  await settle();
  socket.write(':2}\n{"c":3}');
  await settle();
  socket.write('\n');
  await settle();
  socket.end();
  assert.equal(count(), 3);
});

test('counts lines from several connections (Caddy reconnects)', async (t) => {
  const { port, count } = await counter(t);
  for (const lines of ['x\n', 'y\nz\n']) {
    const socket = net.connect(port, '127.0.0.1');
    await once(socket, 'connect');
    socket.end(lines);
    await once(socket, 'close');
  }
  await settle();
  assert.equal(count(), 3);
});

test('a counter that throws or a socket error never breaks the server', async (t) => {
  let calls = 0;
  const server = startCaddyCount(() => { calls += 1; throw new Error('boom'); }, { port: 0 });
  await once(server, 'listening');
  t.after(() => server.close());
  const socket = net.connect(server.address().port, '127.0.0.1');
  await once(socket, 'connect');
  socket.write('a\nb\n');
  await settle();
  socket.destroy();
  await settle();
  assert.equal(calls, 2);
  assert.equal(server.listening, true);
});

test('a port in use is reported to onError, not thrown', async (t) => {
  const taken = net.createServer().listen(0, '127.0.0.1');
  await once(taken, 'listening');
  t.after(() => taken.close());
  const errors = [];
  startCaddyCount(() => {}, { port: taken.address().port, onError: (error) => errors.push(error.code) });
  await settle();
  assert.deepEqual(errors, ['EADDRINUSE']);
});
