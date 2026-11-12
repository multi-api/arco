'use strict';
// Classic Lambda handler (worker.handler) of a process app's queue and console functions: starts the customer's
// binary once per environment and delivers SQS records and schedule events to it as local HTTP POSTs.
const nodeFs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { loadEnvironment } = require('./lib/boot');
const supervisor = require('./lib/supervisor');
const processKind = require('./lib/process');

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

// deps: { fs, log, host, port, start } are injectable; start() launches the process and resolves its child handle.
function createHandler(deps = {}) {
  const { fs = nodeFs, log = defaultLog, host = processKind.HOST, port = processKind.PORT } = deps;
  const start = deps.start || (async () => {
    const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'arco-runtime.json'), 'utf8'));
    await loadEnvironment(log);
    supervisor.prepareTmp(TMP, ['bin']);
    return processKind.startProcess(ROOT, config, process.env, log);
  });
  let init = null;
  let dead = false;

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
    };
    try {
      const { status, body } = await post(host, port, process.env.ARCO_QUEUE_ROUTE, headers, record.body ?? '', timeout);
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
      result = await post(host, port, route, { 'x-arco-schedule': event.name }, '', budget);
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
      return handleQueue(event, context);
    }
    if (event && event.arco === 'schedule') return handleSchedule(event, context);
    throw new Error('unsupported event');
  };
}

module.exports = { createHandler };
module.exports.handler = createHandler();
