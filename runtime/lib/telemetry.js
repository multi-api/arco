'use strict';
// createTelemetry(): the anonymous invocation count. Each Lambda environment counts invocations in memory, one counter
// per kind, and sends them to Arco's collector as one plain-text UDP datagram per kind: the first right after the
// first invocation (cold start, with the boot bucket), then at most once a minute when an invocation ends, and what is
// pending on SIGTERM. A packet carries only runtime, kind, Arco version, region, count, cold start and boot bucket.
// Nothing here blocks, awaits the network or throws into the caller: the collector is resolved once, asynchronously,
// and a failed lookup turns telemetry off for the environment's life.
// ARCO_TELEMETRY_DISABLE=1 or `"telemetry": false` in arco-runtime.json turn it off before anything is counted.
const dgram = require('node:dgram');
const dns = require('node:dns');

const DEFAULT_HOST = 'telemetry.arcocloud.app';
const DEFAULT_PORT = 41700;
const SEND_INTERVAL_MS = 60000;
const PROTOCOL = 'arco1';
const RUNTIMES = new Set(['laravel', 'process']);
const KINDS = new Set(['web', 'queue', 'console', 'schedule']);
// Version and region go into the packet as they are: anything unexpected turns telemetry off instead.
const VERSION = /^[0-9A-Za-z.+-]{1,32}$/;
const REGION = /^[a-z0-9-]{1,32}$/;

/** The boot duration as a bucket: 0 under 500 ms, 1 under 1 s, 2 under 3 s, 3 from 3 s. */
function bootBucket(ms) {
  if (!(ms >= 500)) return 0;
  if (ms < 1000) return 1;
  if (ms < 3000) return 2;
  return 3;
}

/** One packet line; the boot bucket is only sent with a cold start. Same encoding as the PHP runtime's. */
function encodePacket({ runtime, kind, version, region, invocations, cold, boot }) {
  const line = `${PROTOCOL} r=${runtime} k=${kind} v=${version} g=${region} i=${invocations} c=${cold ? 1 : 0}`;
  return cold ? `${line} b=${boot}` : line;
}

/** `host:port` from ARCO_TELEMETRY_HOST (maintainers and tests), else the collector. */
function destination(value) {
  if (typeof value !== 'string' || value === '') return { host: DEFAULT_HOST, port: DEFAULT_PORT };
  const colon = value.lastIndexOf(':');
  const port = colon === -1 ? NaN : Number(value.slice(colon + 1));
  if (Number.isInteger(port) && port > 0 && port < 65536) return { host: value.slice(0, colon), port };
  return { host: colon === -1 ? value : value.slice(0, colon), port: DEFAULT_PORT };
}

function defaultSocket() {
  return dgram.createSocket('udp4');
}

// now(): a monotonic clock in ms (the PHP runtime uses hrtime), so a wall clock set back never stops the minute.
function createTelemetry({ env = {}, config = {}, runtime, kind, bootMs, now = () => performance.now(), socket, lookup = dns.lookup } = {}) {
  const version = config && config.arcoVersion;
  const region = env && env.AWS_REGION;
  let enabled = !!env && env.ARCO_TELEMETRY_DISABLE !== '1'
    && !!config && config.telemetry !== false
    && typeof version === 'string' && VERSION.test(version)
    && typeof region === 'string' && REGION.test(region)
    && RUNTIMES.has(runtime)
    && KINDS.has(kind);

  const { host, port } = destination(env && env.ARCO_TELEMETRY_HOST);
  let boot = bootBucket(bootMs);
  const pending = new Map();
  let cold = true;
  let lastSent = 0;
  let address = null;
  let resolving = false;
  let sock = null;

  function disable() {
    enabled = false;
    pending.clear();
  }

  function openSocket() {
    if (sock) return sock;
    sock = socket || defaultSocket();
    sock.on('error', () => {});
    if (typeof sock.unref === 'function') sock.unref();
    return sock;
  }

  function sendPending() {
    if (!enabled || address === null || pending.size === 0) return;
    let s;
    try {
      s = openSocket();
    } catch {
      disable();
      return;
    }
    for (const [k, invocations] of pending) {
      const line = encodePacket({ runtime, kind: k, version, region, invocations, cold, boot });
      cold = false;
      try {
        s.send(Buffer.from(line), port, address, () => {});
      } catch {
        // Fire and forget: a lost packet is a lost count, never an error for the app.
      }
    }
    pending.clear();
    lastSent = now();
  }

  function resolve() {
    resolving = true;
    try {
      lookup(host, { family: 4 }, (error, resolved) => {
        resolving = false;
        if (error || typeof resolved !== 'string' || resolved === '') {
          disable();
          return;
        }
        address = resolved;
        sendPending();
      });
    } catch {
      resolving = false;
      disable();
    }
  }

  // Sends what is pending; the first send resolves the collector and sends once the answer arrives.
  function send() {
    if (!enabled || pending.size === 0) return;
    if (address !== null) {
      sendPending();
    } else if (!resolving) {
      resolve();
    }
  }

  return {
    get enabled() {
      return enabled;
    },
    /** The boot duration (ms), when the runtime is ready after the telemetry was made (index.js). */
    booted(ms) {
      if (typeof ms === 'number' && Number.isFinite(ms)) boot = bootBucket(ms);
    },
    /** Starts resolving the collector ahead of the first send (no I/O wait, nothing sent). */
    prepare() {
      try {
        if (enabled && address === null && !resolving) resolve();
      } catch {
        // Telemetry never fails a boot.
      }
    },
    /** One invocation of `kindOverride` (default: this function's kind). An increment, no I/O. */
    count(kindOverride) {
      if (!enabled) return;
      const k = kindOverride === undefined ? kind : kindOverride;
      if (!KINDS.has(k)) return;
      pending.set(k, (pending.get(k) || 0) + 1);
    },
    /** At the end of an invocation: the first packet at once, then at most one send a minute. */
    tick() {
      try {
        if (!enabled || pending.size === 0) return;
        if (cold || now() - lastSent >= SEND_INTERVAL_MS) send();
      } catch {
        // Telemetry never fails an invocation.
      }
    },
    /** On shutdown: everything pending, best effort. */
    flush() {
      try {
        send();
      } catch {
        // Telemetry never fails a shutdown.
      }
    },
  };
}

module.exports = { createTelemetry, encodePacket, bootBucket, DEFAULT_HOST, DEFAULT_PORT, SEND_INTERVAL_MS };
