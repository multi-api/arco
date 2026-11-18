'use strict';
// Contract test of the tenant mediator (tenant-domains.js): the same handler Lambda runs, with the real
// packaged public suffix list and an injected fake CloudFront and DNS, driven only through the JSON
// request/response contract. The integration suite (tests/Integration) runs PHP in Lambda containers and
// has no place for a Node handler, so this lives with the runtime tests. The format is internal to Arco
// and the arco-laravel client; this test only keeps the two in step.
const test = require('node:test');
const assert = require('node:assert/strict');
const { createHandler } = require('../tenant-domains');
const { loadSuffixes } = require('../lib/tenants/suffixes');
const { CONFIG, awsError, fakeCloudFront, okDns } = require('./tenants-fakes');

function setup(options = {}) {
  const fake = fakeCloudFront(options);
  const suffixes = loadSuffixes(); // lib/tenants/public_suffix_list.dat, the file the deploy ships
  assert.ok(suffixes, 'the packaged public suffix list loads');
  const handler = createHandler({ config: CONFIG, cloudfront: fake.adapter, dns: okDns, suffixes, log: () => {} });
  return { ...fake, handler };
}

// Everything crosses the wire as JSON, as an Invoke payload does.
const call = async (t, event) => JSON.parse(JSON.stringify(await t.handler(JSON.parse(JSON.stringify(event)))));

test('contract: add, idempotent add, status, list paging and remove with the packaged suffix list', async () => {
  const t = setup({ pageSize: 2 });

  assert.deepEqual(await call(t, { operation: 'add', domain: 'Shop.Customer.com' }), {
    ok: true, result: { domain: 'shop.customer.com', state: 'pending', reason: null },
  });
  const creates = () => t.state.calls.filter((c) => c.method === 'createTenant').length;
  assert.equal(creates(), 1);
  assert.deepEqual(await call(t, { operation: 'add', domain: 'shop.customer.com' }), {
    ok: true, result: { domain: 'shop.customer.com', state: 'pending', reason: null },
  });
  assert.equal(creates(), 1, 'the second add creates nothing');

  assert.deepEqual(await call(t, { operation: 'status', domain: 'shop.customer.com' }), {
    ok: true, result: { domain: 'shop.customer.com', state: 'pending', reason: null },
  });
  assert.equal((await call(t, { operation: 'status', domain: 'nope.customer.com' })).error.code, 'not_found');

  for (const domain of ['a.customer.co.uk', 'b.customer.com.ar', 'c.customer.org']) {
    assert.equal((await call(t, { operation: 'add', domain })).ok, true, domain);
  }
  const seen = [];
  let cursor;
  let pages = 0;
  do {
    const answer = await call(t, { operation: 'list', limit: 2, ...(cursor ? { cursor } : {}) });
    assert.equal(answer.ok, true, JSON.stringify(answer));
    seen.push(...answer.result.domains.map((d) => d.domain));
    cursor = answer.result.cursor;
    pages += 1;
  } while (cursor && pages < 10);
  assert.equal(cursor, null, 'the last page has a null cursor');
  assert.equal(pages, 2);
  assert.deepEqual(seen.sort(), ['a.customer.co.uk', 'b.customer.com.ar', 'c.customer.org', 'shop.customer.com']);

  assert.deepEqual(await call(t, { operation: 'remove', domain: 'shop.customer.com' }), { ok: true, result: { removed: true } });
  assert.deepEqual(await call(t, { operation: 'remove', domain: 'shop.customer.com' }), { ok: true, result: { removed: true } });
  assert.equal((await call(t, { operation: 'status', domain: 'shop.customer.com' })).error.code, 'not_found');
});

test('contract: root domains and public suffixes are apex_not_supported, reserved names are reserved_domain', async () => {
  const t = setup();
  for (const domain of ['customer.com', 'customer.co.uk', 'co.uk', 'github.io']) {
    const answer = await call(t, { operation: 'add', domain });
    assert.equal(answer.ok, false, domain);
    assert.equal(answer.error.code, 'apex_not_supported', domain);
  }
  for (const domain of [CONFIG.target, CONFIG.reserved[0]]) {
    assert.equal((await call(t, { operation: 'add', domain })).error.code, 'reserved_domain', domain);
  }
  assert.equal(t.state.calls.length, 0, 'nothing reached CloudFront');
});

test('contract: a CloudFront error that is not understood comes back as failed, not as a thrown error', async () => {
  const t = setup();
  t.state.fail.createTenant = { error: awsError('InternalFailure', 'boom') };
  const answer = await call(t, { operation: 'add', domain: 'x.customer.com' });
  assert.equal(answer.ok, false);
  assert.equal(answer.error.code, 'failed');
});
