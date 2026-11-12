'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const net = require('node:net');
const { once } = require('node:events');
const { spawnProcess, waitForPort } = require('../lib/supervisor');

test('spawnProcess calls onExit with the code and signal of the child', async () => {
  const exited = new Promise((resolve) => {
    spawnProcess(process.execPath, ['-e', 'process.exit(3)'], { env: process.env, cwd: process.cwd(), stdio: 'ignore', onExit: (code, signal) => resolve({ code, signal }) });
  });
  assert.deepEqual(await exited, { code: 3, signal: null });
});

test('spawnProcess forwards SIGTERM and SIGINT from the parent to the child', async (t) => {
  const before = { SIGTERM: process.listeners('SIGTERM'), SIGINT: process.listeners('SIGINT') };
  // The test process must survive the signal: a no-op listener stands in for the runtime's own handlers.
  const exited = new Promise((resolve) => {
    const child = spawnProcess(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], {
      env: process.env, cwd: process.cwd(), stdio: 'ignore', onExit: (code, signal) => resolve({ code, signal }),
    });
    child.once('spawn', () => process.emit('SIGTERM', 'SIGTERM'));
  });
  t.after(() => {
    for (const [signal, keep] of Object.entries(before)) {
      for (const listener of process.listeners(signal)) if (!keep.includes(listener)) process.removeListener(signal, listener);
    }
  });
  assert.deepEqual(await exited, { code: null, signal: 'SIGTERM' });
});

test('waitForPort rejects naming the process after the timeout', async () => {
  const server = net.createServer().listen(0, '127.0.0.1');
  await once(server, 'listening');
  const { port } = server.address();
  server.close();
  await once(server, 'close');
  await assert.rejects(
    waitForPort('127.0.0.1', port, 100, 'process'),
    { message: `process did not listen on 127.0.0.1:${port} within 100 ms` },
  );
});

test('waitForPort keeps FrankenPHP as the default name', async () => {
  const server = net.createServer().listen(0, '127.0.0.1');
  await once(server, 'listening');
  const { port } = server.address();
  server.close();
  await once(server, 'close');
  await assert.rejects(waitForPort('127.0.0.1', port, 50), { message: `FrankenPHP did not listen on 127.0.0.1:${port} within 50 ms` });
});
