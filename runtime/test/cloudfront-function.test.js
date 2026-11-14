'use strict';
// Arco's viewer-request CloudFront function (resources/cloudfront/viewer-request.js), run the way CloudFront runs it:
// a script defining handler(event), with request.rawQueryString() as in cloudfront-js-2.0.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'resources', 'cloudfront', 'viewer-request.js'), 'utf8');
const context = vm.createContext({});
vm.runInContext(source, context);

// The handler runs in its own realm: spread its objects before deepEqual.
// rawQueryString() is undefined without '?', '' with a bare '?', else the query exactly as the viewer sent it.
const run = (rawQuery, headers = { host: { value: 'shop.example' } }) => {
  const querystring = {};
  const request = { method: 'GET', uri: '/auth/github/callback', headers, cookies: {}, querystring, rawQueryString: () => rawQuery };
  return context.handler({ version: '1.0', context: { eventType: 'viewer-request' }, viewer: { ip: '192.0.2.1' }, request });
};

test("sends '+' in the query string as %20, so the origin access control signature matches", () => {
  const raw = 'error=access_denied&error_description=The+user+has+denied+your+application+access.&state=abc';
  assert.equal(run(raw).querystring, 'error=access_denied&error_description=The%20user%20has%20denied%20your%20application%20access.&state=abc');
});

test("rewrites '+' in keys too, keeps order, duplicates and every other byte", () => {
  assert.equal(run('a+b=c&z=1&a+b=d%2B&e=%2b+f&g').querystring, 'a%20b=c&z=1&a%20b=d%2B&e=%2b%20f&g');
});

test('leaves a query string without + untouched', () => {
  for (const raw of [undefined, '', 'a=b', 'a=b%20c&a=d', 'a=b%2Bc', 'q=%E2%9C%93&x=']) {
    const querystring = run(raw).querystring;
    assert.deepEqual(querystring, {}, `query ${JSON.stringify(raw)}`);
  }
});

test('passes the viewer host as x-forwarded-host and drops one the viewer sent without a host', () => {
  assert.deepEqual({ ...run('a=b').headers['x-forwarded-host'] }, { value: 'shop.example' });
  assert.equal(run('a=b', { 'x-forwarded-host': { value: 'evil.example' } }).headers['x-forwarded-host'], undefined);
  assert.deepEqual({ ...run('a=b', { host: { value: 'shop.example' }, 'x-forwarded-host': { value: 'evil.example' } }).headers['x-forwarded-host'] }, { value: 'shop.example' });
});
