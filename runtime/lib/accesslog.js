'use strict';
// accessLog(): Caddy writes its access log (`log { output stdout format json }`) to FrankenPHP's stdout, which the
// runtime reads; each entry becomes one `{"arco":"request",...}` line, the shape Arco Cloud's Monitoring tab queries.
// The entry's headers, IP and cookies never reach CloudWatch. Every other stdout line passes through unchanged.
const readline = require('node:readline');

// Keep in sync with ShareRequestId::HEADERS (packages/arco-laravel/src/Http/ShareRequestId.php).
const REQUEST_ID_HEADERS = ['x-request-id', 'x-amzn-requestid', 'x-amz-request-id', 'x-amzn-trace-id'];
const MAX_REQUEST_ID = 200;
const MAX_PATH = 1024;

function requestId(headers) {
  const byName = {};
  for (const [name, values] of Object.entries(headers || {})) {
    const value = Array.isArray(values) ? values[0] : values;
    if (typeof value === 'string') byName[name.toLowerCase()] ??= value;
  }
  for (const name of REQUEST_ID_HEADERS) {
    if (byName[name]) return byName[name].slice(0, MAX_REQUEST_ID);
  }
  return null;
}

/** The request line for a Caddy access log entry, or null when the entry is something else. */
function requestLine(entry) {
  if (!entry || typeof entry !== 'object' || typeof entry.logger !== 'string' || !entry.logger.startsWith('http.log.access')) {
    return null;
  }
  const request = entry.request || {};
  const uri = typeof request.uri === 'string' ? request.uri : '';
  return {
    arco: 'request',
    status: Number.isInteger(entry.status) ? entry.status : 0,
    method: typeof request.method === 'string' ? request.method : '',
    path: uri.split('?', 1)[0].slice(0, MAX_PATH),
    // Caddy reports the duration in seconds.
    ms: typeof entry.duration === 'number' ? Math.round(entry.duration * 1000) : 0,
    // lib/caddyfile.js adds X-Request-ID to a request without any of these headers, after Caddy recorded them:
    // the entry carries that id as arco_request_id.
    requestId: requestId(request.headers) ?? (typeof entry.arco_request_id === 'string' && entry.arco_request_id !== '' ? entry.arco_request_id : null),
  };
}

function rewrite(line) {
  if (!line.startsWith('{')) return line;
  try {
    const request = requestLine(JSON.parse(line));
    return request === null ? line : JSON.stringify(request);
  } catch {
    return line;
  }
}

/** Copies `input` to `output` line by line, rewriting Caddy's access entries; resolves once `input` ends. */
function pipeAccessLog(input, output) {
  const lines = readline.createInterface({ input, crlfDelay: Infinity });
  lines.on('line', (line) => output.write(`${rewrite(line)}\n`));
  return new Promise((resolve) => lines.once('close', resolve));
}

module.exports = { REQUEST_ID_HEADERS, MAX_PATH, requestId, requestLine, pipeAccessLog };
