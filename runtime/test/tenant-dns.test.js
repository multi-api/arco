'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { createDnsChecker } = require('../lib/tenants/dns');

const TARGET = 'domains.shop.example.net';
const ENDPOINT = 'd111.cloudfront.net';

function code(c) {
  const error = new Error(c);
  error.code = c;
  return error;
}

// zones: {name: {cname?: [..], a?: [..], aaaa?: [..], error?: code}}
function resolver(zones) {
  const get = (name, key, kind) => {
    const zone = zones[name.toLowerCase()];
    if (!zone) return Promise.reject(code('ENOTFOUND'));
    if (zone.error && zone.error.kind === kind) return Promise.reject(code(zone.error.code));
    if (!zone[key]) return Promise.reject(code('ENODATA'));
    return Promise.resolve(zone[key]);
  };
  return {
    resolveCname: (n) => get(n, 'cname', 'cname'),
    resolve4: (n) => get(n, 'a', 'a'),
    resolve6: (n) => get(n, 'aaaa', 'aaaa'),
  };
}

const check = (zones, domain = 'shop.customer.com') =>
  createDnsChecker(resolver(zones)).check({ domain, target: TARGET, endpoint: ENDPOINT });

test('direct CNAME to the target is accepted (case and trailing dot ignored)', async () => {
  assert.equal(await check({ 'shop.customer.com': { cname: [`${TARGET.toUpperCase()}.`] } }), true);
});

test('CNAME chain domain -> x -> target is accepted', async () => {
  assert.equal(await check({ 'shop.customer.com': { cname: ['x.cdn.net'] }, 'x.cdn.net': { cname: [TARGET] } }), true);
});

test('CNAME chain reaching the routing endpoint is accepted', async () => {
  assert.equal(await check({ 'shop.customer.com': { cname: ['x.cdn.net'] }, 'x.cdn.net': { cname: [ENDPOINT] } }), true);
});

test('a CNAME to something else is rejected, also when the chain ends with addresses only', async () => {
  assert.equal(await check({ 'shop.customer.com': { cname: ['other.example.org'] } }), false);
  assert.equal(
    await check({ 'shop.customer.com': { cname: ['x.cdn.net'] }, 'x.cdn.net': { a: ['1.1.1.1'] }, [ENDPOINT]: { a: ['1.1.1.1'] } }),
    false,
  );
});

test('flattened domain: addresses that are a subset of the endpoint are accepted', async () => {
  const zones = {
    'shop.customer.com': { a: ['1.1.1.1'], aaaa: ['2001:db8::1'] },
    [ENDPOINT]: { a: ['1.1.1.1', '2.2.2.2'], aaaa: ['2001:DB8::1', '2001:db8::2'] },
  };
  assert.equal(await check(zones), true);
});

test('flattened domain: one extra address is rejected', async () => {
  const zones = {
    'shop.customer.com': { a: ['1.1.1.1', '9.9.9.9'] },
    [ENDPOINT]: { a: ['1.1.1.1', '2.2.2.2'] },
  };
  assert.equal(await check(zones), false);
});

test('a domain with no records at all is rejected', async () => {
  assert.equal(await check({}), false);
  assert.equal(await check({ 'shop.customer.com': {}, [ENDPOINT]: { a: ['1.1.1.1'] } }), false);
});

test('resolver errors other than "no record" are never accepted', async () => {
  assert.equal(await check({ 'shop.customer.com': { error: { kind: 'cname', code: 'ESERVFAIL' } } }), false);
  assert.equal(await check({ 'shop.customer.com': { cname: ['x.cdn.net'] }, 'x.cdn.net': { error: { kind: 'cname', code: 'ETIMEOUT' } } }), false);
  const zones = {
    'shop.customer.com': { a: ['1.1.1.1'], error: { kind: 'aaaa', code: 'ESERVFAIL' } },
    [ENDPOINT]: { a: ['1.1.1.1'] },
  };
  assert.equal(await check(zones), false);
  const endpointFails = {
    'shop.customer.com': { a: ['1.1.1.1'] },
    [ENDPOINT]: { a: ['1.1.1.1'], error: { kind: 'aaaa', code: 'ETIMEOUT' } },
  };
  assert.equal(await check(endpointFails), false);
});

test('CNAME loops and over-long chains are rejected', async () => {
  assert.equal(await check({ 'shop.customer.com': { cname: ['a.x.net'] }, 'a.x.net': { cname: ['shop.customer.com'] } }), false);
  const zones = { 'shop.customer.com': { cname: ['h1.x.net'] } };
  for (let i = 1; i <= 10; i += 1) zones[`h${i}.x.net`] = { cname: [`h${i + 1}.x.net`] };
  zones['h10.x.net'] = { cname: [TARGET] };
  assert.equal(await check(zones), false);
});

test('a chain of exactly 8 hops ending at the target is accepted', async () => {
  const zones = { 'shop.customer.com': { cname: ['h1.x.net'] } };
  for (let i = 1; i < 7; i += 1) zones[`h${i}.x.net`] = { cname: [`h${i + 1}.x.net`] };
  zones['h7.x.net'] = { cname: [TARGET] };
  assert.equal(await check(zones), true);
});
