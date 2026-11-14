'use strict';
// Classic Lambda handler (worker.handler) of a process app's queue and console functions: starts the customer's
// binary once per environment and delivers SQS records and schedule events to it as local HTTP POSTs.
const nodeFs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { loadEnvironment } = require('./lib/boot');
const supervisor = require('./lib/supervisor');
const processKind = require('./lib/process');
const { createTelemetry } = require('./lib/telemetry');

const ROOT = __dirname;
const TMP = '/tmp/arco';
const BODY_LOG_BYTES = 1024;
const MARGIN_MS = 2000; // kept free at the end of every invocation
const MIN_BUDGET_MS = 500; // below this a record is not worth sending
const DEFAULT_REMAINING_MS = 30000;
const started = Date.now();
const defaultLog = (phase, data = {}) => console.log(JSON.stringify({ arco: phase, ms: Date.now() - started, ...data }));

// POST to the process; resolves { status, body } (body: first 1 KB), rejects on connection errors and timeouts.
function post(host, port, route, headers, body, timeoutMs) {
  return new Promise((resolve, reject) => {
    const request = http.request(
      { host, port, path: route, method: 'POST', headers: { ...headers, 'content-length': Buffer.byteLength(body) }, signal: AbortSignal.timeout(timeoutMs) },
      (response) => {
        const chunks = [];
        let size = 0;
        response.on('data', (chunk) => {
          if (size < BODY_LOG_BYTES) chunks.push(chunk.subarray(0, BODY_LOG_BYTES - size));
          size += chunk.length;
        });
        response.on('end', () => resolve({ status: response.statusCode, body: Buffer.concat(chunks).toString('utf8') }));
        response.on('error', reject);
      },
    );
    request.on('error', reject);
    request.end(body);
  });
}

function remainingOf(context) {
  return () => (context && typeof context.getRemainingTimeInMillis === 'function' ? context.getRemainingTimeInMillis() : DEFAULT_REMAINING_MS);
}

// deps: { fs, log, host, port, start, telemetry } are injectable; start() launches the process and resolves its child
// handle; telemetry(bootMs) makes the invocation counter (lib/telemetry.js) once the environment is up.
function createHandler(deps = {}) {
  const { fs = nodeFs, log = defaultLog, host = processKind.HOST, port = processKind.PORT } = deps;
  // Where requests go; startProcess swaps its port to the app's when a front fails, so it is read per request.
  let upstream = deps.upstream || { port };
  let config = null;
  const readConfig = () => (config ??= JSON.parse(fs.readFileSync(path.join(ROOT, 'arco-runtime.json'), 'utf8')));
  const start = deps.start || (async () => {
    const runtimeConfig = readConfig();
    await loadEnvironment(log);
    supervisor.prepareTmp(TMP, ['bin']);
    const started = await processKind.startProcess(ROOT, runtimeConfig, process.env, log);
    upstream = started.upstream;
    return started.child;
  });
  // Made after start(), so after loadEnvironment(): ARCO_TELEMETRY_DISABLE may come from the bundle or a secret.
  const makeTelemetry = deps.telemetry || ((bootMs) => createTelemetry({
    env: process.env, config: readConfig(), runtime: 'process', kind: process.env.ARCO_WORKER_QUEUE ? 'queue' : 'schedule', bootMs,
  }));
  let telemetry; // undefined: not made yet; null: off or failed
  let init = null;
  let dead = false;

  // Lambda freezes a classic handler as soon as it returns: the collector is resolved now, before the first count.
  function startTelemetry() {
    if (telemetry !== undefined) return;
    telemetry = null;
    try {
      const made = makeTelemetry(Date.now() - started);
      if (!made || !made.enabled) return;
      made.prepare();
      process.on('SIGTERM', () => {
        try {
          made.flush();
        } catch {
          // Telemetry never fails a shutdown.
        }
      });
      telemetry = made;
    } catch {
      telemetry = null;
    }
  }

  // One invocation of `kind`, sent (at most once a minute) before the handler returns. Never throws.
  function countInvocation(kind) {
    if (!telemetry) return;
    try {
      telemetry.count(kind);
      telemetry.tick();
    } catch {
      // Telemetry never changes a handler's result.
    }
  }

  async function ready() {
    if (!init) {
      init = start();
      init.catch(() => {});
    }
    let child;
    try {
      child = await init;
    } catch (error) {
      init = null;
      throw error;
    }
    startTelemetry();
    if (child && !child.__arcoWatched) {
      child.__arcoWatched = true;
      if (typeof child.once === 'function') child.once('exit', () => { dead = true; });
    }
    if (dead || isDead(child)) throw new Error('process exited');
  }

  function isDead(child) {
    return dead || Boolean(child && (child.exitCode != null || child.signalCode != null));
  }

  async function deliver(record, timeout) {
    const attribute = record.messageAttributes && record.messageAttributes.contentType;
    const headers = {
      'content-type': (attribute && attribute.stringValue) || 'application/octet-stream',
      'x-arco-queue': process.env.ARCO_WORKER_QUEUE || '',
      'x-arco-message-id': record.messageId,
      'x-arco-receive-count': String((record.attributes && record.attributes.ApproximateReceiveCount) || '1'),
      'x-arco-deadline': String(Date.now() + timeout),
    };
    try {
      const { status, body } = await post(host, upstream.port, process.env.ARCO_QUEUE_ROUTE, headers, record.body ?? '', timeout);
      if (status >= 200 && status < 300) return true;
      log('record_failed', { messageId: record.messageId, status, body });
    } catch (error) {
      log('record_failed', { messageId: record.messageId, error: error.message });
    }
    return false;
  }

  async function handleQueue(event, context) {
    const remaining = remainingOf(context);
    const fifo = process.env.ARCO_QUEUE_FIFO === '1';
    const failures = [];
    let skipped = 0;
    for (const record of event.Records) {
      const budget = remaining() - MARGIN_MS;
      if ((fifo && failures.length > 0) || dead || budget < MIN_BUDGET_MS) {
        if (!(fifo && failures.length > 0)) skipped += 1;
        failures.push({ itemIdentifier: record.messageId });
        continue;
      }
      if (!(await deliver(record, budget))) failures.push({ itemIdentifier: record.messageId });
    }
    if (skipped > 0) log('record_skipped', { queue: process.env.ARCO_WORKER_QUEUE, skipped, reason: dead ? 'process_exited' : 'deadline' });
    return { batchItemFailures: failures };
  }

  async function handleSchedule(event, context) {
    // name => {route, timeout (seconds)}; an older console sent name => route.
    const schedules = JSON.parse(process.env.ARCO_SCHEDULES || '{}');
    const entry = Object.prototype.hasOwnProperty.call(schedules, event.name) ? schedules[event.name] : undefined;
    const route = typeof entry === 'string' ? entry : entry && entry.route;
    if (!route) throw new Error(`unknown schedule: ${event.name}`);
    const left = remainingOf(context)() - MARGIN_MS;
    if (left < MIN_BUDGET_MS) {
      log('schedule_failed', { name: event.name, error: 'deadline' });
      throw new Error(`schedule ${event.name} not sent: invocation deadline too close`);
    }
    // The schedule's own timeout bounds the POST; the invocation's deadline still wins.
    const timeout = entry && typeof entry === 'object' && Number(entry.timeout) > 0 ? Number(entry.timeout) * 1000 : Infinity;
    const budget = Math.min(timeout, left);
    let result;
    try {
      result = await post(host, upstream.port, route, { 'x-arco-schedule': event.name, 'x-arco-deadline': String(Date.now() + budget) }, '', budget);
    } catch (error) {
      log('schedule_failed', { name: event.name, error: error.message });
      throw new Error(`schedule ${event.name} failed: ${error.message}`);
    }
    if (result.status < 200 || result.status >= 300) {
      log('schedule_failed', { name: event.name, status: result.status, body: result.body });
      throw new Error(`schedule ${event.name} failed with status ${result.status}`);
    }
    return { status: result.status };
  }

  return async function handler(event, context) {
    await ready();
    if (event && Array.isArray(event.Records) && event.Records.every((r) => r.eventSource === 'aws:sqs')) {
      // An SQS batch is one invocation, however many records it holds.
      try {
        return await handleQueue(event, context);
      } finally {
        countInvocation('queue');
      }
    }
    if (event && event.arco === 'schedule') {
      try {
        return await handleSchedule(event, context);
      } finally {
        countInvocation('schedule');
      }
    }
    throw new Error('unsupported event');
  };
}

module.exports = { createHandler };
module.exports.handler = createHandler();
