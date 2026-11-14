'use strict';
// worker.js against a real local HTTP server standing in for the customer's process.
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const path = require('node:path');

const { createHandler } = require(path.join(__dirname, '..', 'worker.js'));

async function app(handle) {
  const requests = [];
  const server = http.createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const request = { method: req.method, url: req.url, headers: req.headers, body: Buffer.concat(chunks).toString() };
      requests.push(request);
      handle(request, res, requests.length);
    });
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  return { server, requests, port: server.address().port, close: () => { server.closeAllConnections(); server.close(); } };
}

function record(id, body, extra = {}) {
  return {
    eventSource: 'aws:sqs',
    messageId: id,
    body,
    attributes: { ApproximateReceiveCount: '3' },
    messageAttributes: {},
    ...extra,
  };
}

const ENV_KEYS = ['ARCO_WORKER_QUEUE', 'ARCO_QUEUE_ROUTE', 'ARCO_QUEUE_FIFO', 'ARCO_SCHEDULES'];

async function withEnv(values, fn) {
  const saved = Object.fromEntries(ENV_KEYS.map((k) => [k, process.env[k]]));
  for (const k of ENV_KEYS) delete process.env[k];
  Object.assign(process.env, values);
  try { return await fn(); } finally {
    for (const k of ENV_KEYS) { if (saved[k] === undefined) delete process.env[k]; else process.env[k] = saved[k]; }
  }
}

const context = (ms = 60000) => ({ getRemainingTimeInMillis: () => ms });
const queueEnv = { ARCO_WORKER_QUEUE: 'emails', ARCO_QUEUE_ROUTE: '/_arco/queues/emails' };

test('delivers records in order and reports the failed ones', async () => {
  const statuses = [204, 500, 204];
  const a = await app((req, res, n) => { res.statusCode = statuses[n - 1]; res.end(n === 2 ? 'boom' : ''); });
  const logs = [];
  const handler = createHandler({ port: a.port, start: async () => ({}), log: (p, d) => logs.push([p, d]) });
  await withEnv(queueEnv, async () => {
    const result = await handler({ Records: [
      record('m1', '{"a":1}', { messageAttributes: { contentType: { stringValue: 'application/json', dataType: 'String' } } }),
      record('m2', 'raw'),
      record('m3', 'x'),
    ] }, context());
    assert.deepEqual(result, { batchItemFailures: [{ itemIdentifier: 'm2' }] });
  });
  assert.equal(a.requests.length, 3);
  const [first, second] = a.requests;
  assert.equal(first.method, 'POST');
  assert.equal(first.url, '/_arco/queues/emails');
  assert.equal(first.body, '{"a":1}');
  assert.equal(first.headers['content-type'], 'application/json');
  assert.equal(first.headers['x-arco-queue'], 'emails');
  assert.equal(first.headers['x-arco-message-id'], 'm1');
  assert.equal(first.headers['x-arco-receive-count'], '3');
  assert.equal(second.headers['content-type'], 'application/octet-stream');
  assert.ok(logs.some(([, d]) => d.status === 500 && d.body === 'boom'), 'failure log keeps status and body');
  a.close();
});

test('a record that never answers fails before the invocation times out, the next one is still sent', async () => {
  const a = await app((req, res, n) => { if (n !== 1) res.end(); });
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {} });
  const started = Date.now();
  await withEnv(queueEnv, async () => {
    const result = await handler({ Records: [record('m1', 'a'), record('m2', 'b')] }, context(2500));
    assert.deepEqual(result, { batchItemFailures: [{ itemIdentifier: 'm1' }] });
  });
  assert.ok(Date.now() - started < 2500, 'failed within the invocation time');
  assert.equal(a.requests.length, 2);
  a.close();
});

test('hung records never overrun the invocation deadline', async () => {
  const a = await app(() => {});
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {} });
  const begin = Date.now();
  const ctx = { getRemainingTimeInMillis: () => 3300 - (Date.now() - begin) };
  await withEnv(queueEnv, async () => {
    const result = await handler({ Records: ['m1', 'm2', 'm3', 'm4'].map((id) => record(id, id)) }, ctx);
    assert.equal(result.batchItemFailures.length, 4);
  });
  assert.ok(Date.now() - begin <= 3300 - 2000 + 150, 'returned with the safety margin left');
  a.close();
});

test('with under 2500 ms left nothing is sent and every record fails', async () => {
  const a = await app((req, res) => { res.statusCode = 204; res.end(); });
  const logs = [];
  const handler = createHandler({ port: a.port, start: async () => ({}), log: (p, d) => logs.push([p, d]) });
  await withEnv(queueEnv, async () => {
    const result = await handler({ Records: [record('m1', 'a'), record('m2', 'b')] }, context(1200));
    assert.equal(result.batchItemFailures.length, 2);
  });
  assert.equal(a.requests.length, 0);
  assert.equal(logs.filter(([p]) => p === 'record_skipped').length, 1);
  a.close();
});

test('schedule with too little time left throws without sending', async () => {
  const a = await app(() => {});
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {} });
  await withEnv(schedulesEnv(), async () => {
    await assert.rejects(handler({ arco: 'schedule', name: 'cleanup' }, context(2400)));
  });
  assert.equal(a.requests.length, 0);
  a.close();
});

test('a child that dies mid-batch fails the in-flight and remaining records, and the next invocation', async () => {
  const child = new (require('node:events').EventEmitter)();
  child.exitCode = null;
  const a = await app((req, res) => { child.exitCode = 1; child.emit('exit', 1, null); res.socket.destroy(); });
  const handler = createHandler({ port: a.port, start: async () => child, log: () => {} });
  await withEnv(queueEnv, async () => {
    const result = await handler({ Records: [record('m1', 'a'), record('m2', 'b'), record('m3', 'c')] }, context());
    assert.deepEqual(result.batchItemFailures.map((f) => f.itemIdentifier), ['m1', 'm2', 'm3']);
    await assert.rejects(handler({ Records: [record('m4', 'd')] }, context()), /exited/);
  });
  assert.equal(a.requests.length, 1);
  a.close();
});

test('FIFO: after the first failure the rest is failed without being sent', async () => {
  const a = await app((req, res) => { res.statusCode = 500; res.end(); });
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {} });
  await withEnv({ ...queueEnv, ARCO_QUEUE_FIFO: '1' }, async () => {
    const result = await handler({ Records: [record('m1', 'a'), record('m2', 'b'), record('m3', 'c')] }, context());
    assert.deepEqual(result, { batchItemFailures: [{ itemIdentifier: 'm1' }, { itemIdentifier: 'm2' }, { itemIdentifier: 'm3' }] });
  });
  assert.equal(a.requests.length, 1);
  a.close();
});

test('connection refused fails every record', async () => {
  const a = await app(() => {});
  const port = a.port;
  a.close();
  const handler = createHandler({ port, start: async () => ({}), log: () => {} });
  await withEnv(queueEnv, async () => {
    const result = await handler({ Records: [record('m1', 'a'), record('m2', 'b')] }, context());
    assert.deepEqual(result, { batchItemFailures: [{ itemIdentifier: 'm1' }, { itemIdentifier: 'm2' }] });
  });
});

const schedulesEnv = () => schedules;
const schedules = { ARCO_SCHEDULES: JSON.stringify({ cleanup: { route: '/_arco/schedule/cleanup', timeout: 60 } }) };

test('schedule: the old string form (name => route) still works', async () => {
  const a = await app((req, res) => { res.statusCode = 204; res.end(); });
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {} });
  await withEnv({ ARCO_SCHEDULES: JSON.stringify({ cleanup: '/old/route' }) }, async () => {
    await handler({ arco: 'schedule', name: 'cleanup' }, context());
  });
  assert.equal(a.requests[0].url, '/old/route');
  a.close();
});

test('schedule: its own timeout bounds the POST, not the invocation time', async () => {
  const a = await app(() => {});
  const logs = [];
  const handler = createHandler({ port: a.port, start: async () => ({}), log: (p, d) => logs.push([p, d]) });
  const started = Date.now();
  // A shrinking deadline keeps a runtime that ignores the schedule timeout from hanging for a minute.
  const ctx = { getRemainingTimeInMillis: () => 6000 - (Date.now() - started) };
  try {
    await withEnv({ ARCO_SCHEDULES: JSON.stringify({ quick: { route: '/q', timeout: 1 } }) }, async () => {
      await assert.rejects(handler({ arco: 'schedule', name: 'quick' }, ctx), /quick/);
    });
    const elapsed = Date.now() - started;
    assert.ok(elapsed >= 900 && elapsed < 2500, `failed after its 1 s timeout (${elapsed} ms)`);
    assert.equal(a.requests.length, 1);
    assert.ok(logs.some(([p]) => p === 'schedule_failed'));
  } finally {
    a.close();
  }
});

test('schedule: POSTs to the route with the schedule header', async () => {
  const a = await app((req, res) => { res.statusCode = 204; res.end(); });
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {} });
  await withEnv(schedules, async () => {
    await handler({ arco: 'schedule', name: 'cleanup' }, context());
  });
  assert.equal(a.requests[0].method, 'POST');
  assert.equal(a.requests[0].url, '/_arco/schedule/cleanup');
  assert.equal(a.requests[0].headers['x-arco-schedule'], 'cleanup');
  assert.equal(a.requests[0].body, '');
  a.close();
});

test('schedule: a non-2xx throws and logs schedule_failed', async () => {
  const a = await app((req, res) => { res.statusCode = 500; res.end('nope'); });
  const logs = [];
  const handler = createHandler({ port: a.port, start: async () => ({}), log: (p, d) => logs.push([p, d]) });
  await withEnv(schedules, async () => {
    await assert.rejects(handler({ arco: 'schedule', name: 'cleanup' }, context()));
  });
  const failed = logs.find(([p]) => p === 'schedule_failed');
  assert.ok(failed);
  assert.equal(failed[1].name, 'cleanup');
  assert.equal(failed[1].status, 500);
  a.close();
});

test('schedule: an unknown name throws', async () => {
  const handler = createHandler({ port: 1, start: async () => ({}), log: () => {} });
  await withEnv(schedules, async () => {
    await assert.rejects(handler({ arco: 'schedule', name: 'other' }, context()), /other/);
  });
});

test('the process is started once across invocations', async () => {
  const a = await app((req, res) => { res.statusCode = 204; res.end(); });
  let starts = 0;
  const handler = createHandler({ port: a.port, start: async () => { starts += 1; return {}; }, log: () => {} });
  await withEnv(schedules, async () => {
    await handler({ arco: 'schedule', name: 'cleanup' }, context());
    await handler({ arco: 'schedule', name: 'cleanup' }, context());
  });
  assert.equal(starts, 1);
  a.close();
});

test('an invocation that finds the process dead fails', async () => {
  const handler = createHandler({ port: 1, start: async () => ({ exitCode: 1 }), log: () => {} });
  await withEnv(schedules, async () => {
    await assert.rejects(handler({ arco: 'schedule', name: 'cleanup' }, context()), /exited/);
  });
});

// A telemetry stand-in: records count(kind), tick() and prepare() in order.
function fakeTelemetry({ throws = false } = {}) {
  const calls = [];
  const made = [];
  const record = (name) => (...args) => { calls.push([name, ...args]); if (throws) throw new Error('telemetry boom'); };
  const factory = (bootMs) => {
    made.push(bootMs);
    return { enabled: true, count: record('count'), tick: record('tick'), prepare: record('prepare'), flush: record('flush') };
  };
  return { factory, calls, made };
}

test('telemetry: an SQS batch is one queue invocation, counted and ticked before the handler returns', async () => {
  const a = await app((req, res) => { res.statusCode = 204; res.end(); });
  const t = fakeTelemetry();
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {}, telemetry: t.factory });
  await withEnv(queueEnv, async () => {
    await handler({ Records: [record('m1', 'a'), record('m2', 'b'), record('m3', 'c')] }, context());
    assert.deepEqual(t.calls, [['prepare'], ['count', 'queue'], ['tick']]);
    await handler({ Records: [record('m4', 'd')] }, context());
  });
  a.close();
  assert.deepEqual(t.calls, [['prepare'], ['count', 'queue'], ['tick'], ['count', 'queue'], ['tick']]);
  assert.equal(t.made.length, 1, 'one telemetry per environment');
  assert.ok(Number.isInteger(t.made[0]) && t.made[0] >= 0, 'made with the boot duration');
});

test('telemetry: a schedule event counts as schedule, a failed one too', async () => {
  const statuses = [200, 500];
  const a = await app((req, res, n) => { res.statusCode = statuses[n - 1]; res.end(); });
  const t = fakeTelemetry();
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {}, telemetry: t.factory });
  await withEnv(schedulesEnv(), async () => {
    await handler({ arco: 'schedule', name: 'cleanup' }, context());
    await assert.rejects(handler({ arco: 'schedule', name: 'cleanup' }, context()), /status 500/);
  });
  a.close();
  assert.deepEqual(t.calls, [['prepare'], ['count', 'schedule'], ['tick'], ['count', 'schedule'], ['tick']]);
});

test('telemetry: a telemetry that throws changes neither the result nor the error', async () => {
  const a = await app((req, res, n) => { res.statusCode = n === 1 ? 500 : 204; res.end(); });
  const t = fakeTelemetry({ throws: true });
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {}, telemetry: t.factory });
  await withEnv({ ...queueEnv, ...schedules }, async () => {
    assert.deepEqual(await handler({ Records: [record('m1', 'a')] }, context()), { batchItemFailures: [{ itemIdentifier: 'm1' }] });
    assert.deepEqual(await handler({ arco: 'schedule', name: 'cleanup' }, context()), { status: 204 });
    await assert.rejects(handler({ arco: 'unknown' }, context()), /unsupported event/);
  });
  a.close();
});

test('telemetry: a factory that throws leaves the handler working', async () => {
  const a = await app((req, res) => { res.statusCode = 204; res.end(); });
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {}, telemetry: () => { throw new Error('nope'); } });
  await withEnv(queueEnv, async () => {
    assert.deepEqual(await handler({ Records: [record('m1', 'a')] }, context()), { batchItemFailures: [] });
  });
  a.close();
});

test('telemetry: SIGTERM flushes what is pending', async (t) => {
  const before = process.listeners('SIGTERM');
  t.after(() => { for (const l of process.listeners('SIGTERM')) if (!before.includes(l)) process.removeListener('SIGTERM', l); });
  const a = await app((req, res) => { res.statusCode = 204; res.end(); });
  const fake = fakeTelemetry();
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {}, telemetry: fake.factory });
  await withEnv(queueEnv, async () => { await handler({ Records: [record('m1', 'a')] }, context()); });
  a.close();
  for (const listener of process.listeners('SIGTERM')) if (!before.includes(listener)) listener('SIGTERM');
  assert.deepEqual(fake.calls.at(-1), ['flush']);
});
