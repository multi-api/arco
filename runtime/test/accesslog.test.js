'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { PassThrough } = require('node:stream');
const { requestLine, pipeAccessLog } = require('../lib/accesslog');

// The shape of a Caddy access log entry (`log { format json }`), trimmed to what matters here.
const caddyEntry = (request, extra = {}) => ({
  level: 'info', ts: 1762128000.1, logger: 'http.log.access.log0', msg: 'handled request',
  request: { remote_ip: '10.0.0.1', proto: 'HTTP/1.1', method: 'GET', host: 'shop.example', uri: '/', headers: {}, ...request },
  bytes_read: 0, user_id: '', duration: 0.0123456, size: 5, status: 200, resp_headers: {},
  ...extra,
});

test('turns an access entry into the arco request line, path without the query string', () => {
  const entry = caddyEntry({ method: 'POST', uri: '/orders/42?page=2&token=secret', headers: { 'X-Amzn-Requestid': ['abc-123'] } }, { status: 201, duration: 0.25 });
  assert.deepEqual(requestLine(entry), { arco: 'request', status: 201, method: 'POST', path: '/orders/42', ms: 250, requestId: 'abc-123' });
});

test('takes the request id from the same headers, in the same order, as ShareRequestId', () => {
  const headers = { 'X-Amzn-Trace-Id': ['Root=1-abc'], 'X-Amz-Request-Id': ['amz'], 'X-Amzn-Requestid': ['amzn'], 'X-Request-Id': ['mine'] };
  assert.equal(requestLine(caddyEntry({ headers })).requestId, 'mine');
  delete headers['X-Request-Id'];
  assert.equal(requestLine(caddyEntry({ headers })).requestId, 'amzn');
  delete headers['X-Amzn-Requestid'];
  assert.equal(requestLine(caddyEntry({ headers })).requestId, 'amz');
  delete headers['X-Amz-Request-Id'];
  assert.equal(requestLine(caddyEntry({ headers })).requestId, 'Root=1-abc');
  assert.equal(requestLine(caddyEntry({ headers: {} })).requestId, null);
  assert.equal(requestLine(caddyEntry({ headers: { 'X-Request-Id': [''] } })).requestId, null);
  assert.equal(requestLine(caddyEntry({ headers: { 'X-Request-Id': ['x'.repeat(300)] } })).requestId.length, 200);
});

test('without a request id header, takes the one the runtime made up for the request', () => {
  const uuid = '7dad1fdc-c383-450b-9554-2855bdb8a868';
  assert.equal(requestLine(caddyEntry({ headers: {} }, { arco_request_id: uuid })).requestId, uuid);
  assert.equal(requestLine(caddyEntry({ headers: { 'X-Request-Id': ['mine'] } }, { arco_request_id: uuid })).requestId, 'mine', 'the caller\'s id wins, as in Laravel');
  assert.equal(Object.keys(requestLine(caddyEntry({}, { arco_request_id: uuid }))).length, 6);
});

test('rounds the duration to whole milliseconds and keeps only the five fields', () => {
  const line = requestLine(caddyEntry({ headers: { Cookie: ['REDACTED'] } }));
  assert.deepEqual(Object.keys(line), ['arco', 'status', 'method', 'path', 'ms', 'requestId']);
  assert.equal(line.ms, 12);
  assert.equal(line.status, 200);
});

test('leaves anything that is not an access entry alone', () => {
  assert.equal(requestLine({ level: 'info', logger: 'frankenphp', msg: 'FrankenPHP started' }), null);
  assert.equal(requestLine('text'), null);
  assert.equal(requestLine(null), null);
});

test('rewrites access entries in the stream and passes every other line through as it came', async () => {
  const source = new PassThrough();
  const sink = new PassThrough();
  const out = [];
  sink.on('data', (chunk) => out.push(chunk.toString()));
  const done = pipeAccessLog(source, sink);
  const access = JSON.stringify(caddyEntry({ uri: '/health' }));
  // Lines split across chunks, a plain text line and a JSON line that is not Caddy's.
  source.write(access.slice(0, 20));
  source.write(`${access.slice(20)}\nplain output\n{"message":"app"`);
  source.end('}\n');
  await done;
  assert.equal(out.join(''), [
    JSON.stringify({ arco: 'request', status: 200, method: 'GET', path: '/health', ms: 12, requestId: null }),
    'plain output',
    '{"message":"app"}',
    '',
  ].join('\n'));
});
