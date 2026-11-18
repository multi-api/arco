'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { createHandler, readConfig, handler: realHandler } = require('../tenant-domains');
const { loadSuffixes } = require('../lib/tenants/suffixes');
const { CONFIG, ownTags, awsError, tenantFor, fakeCloudFront, okDns, failDns } = require('./tenants-fakes');

const suffixes = loadSuffixes(path.join(__dirname, 'fixtures', 'public_suffix_list.test.dat'));

function setup({ tenants = [], dns = okDns, config = CONFIG, list = suffixes, pageSize } = {}) {
  const fake = fakeCloudFront({ tenants, pageSize });
  const logs = [];
  const handler = createHandler({ config, cloudfront: fake.adapter, dns, suffixes: list, log: (l) => logs.push(l) });
  const methods = () => fake.state.calls.map((c) => c.method);
  const writes = () => fake.state.calls.filter((c) => ['createTenant', 'updateTenant', 'deleteTenant'].includes(c.method));
  return { ...fake, handler, logs, methods, writes };
}

const err = (response, code) => {
  assert.equal(response.ok, false, JSON.stringify(response));
  assert.equal(response.error.code, code, JSON.stringify(response));
  return response.error;
};
const ok = (response) => {
  assert.equal(response.ok, true, JSON.stringify(response));
  return response.result;
};
const add = (t, domain) => t.handler({ operation: 'add', domain });

// ---- request validation ----------------------------------------------------------------

test('malformed events are invalid_request', async () => {
  const t = setup();
  for (const event of [null, undefined, 'add', [], {}, { operation: 'drop' }, { operation: 'add' }, { operation: 'add', domain: 5 },
    { operation: 'status', domain: null }, { operation: 'remove', domain: 'a'.repeat(5000) }, { operation: 7 }]) {
    err(await t.handler(event), 'invalid_request');
  }
  assert.equal(t.state.calls.length, 0);
});

test('invalid domains are invalid_domain for every operation taking one', async () => {
  const t = setup();
  for (const operation of ['add', 'status', 'remove']) {
    for (const domain of ['', 'localhost', '*.customer.com', '1.2.3.4', 'a_b.customer.com', 'a'.repeat(300)]) {
      err(await t.handler({ operation, domain }), 'invalid_domain');
    }
  }
  assert.equal(t.state.calls.length, 0);
});

test('caller-supplied ids, tags and flags in the event are ignored', async () => {
  const t = setup();
  ok(await t.handler({
    operation: 'add', domain: 'shop.customer.com', distributionId: 'EVIL', DistributionId: 'EVIL', connectionGroupId: 'EVIL',
    tags: [{ Key: 'arco:app', Value: 'other' }], Tags: ownTags('other', 'x'), enabled: false, Enabled: false, name: 'x',
  }));
  const input = t.state.calls.find((c) => c.method === 'createTenant').args[0];
  assert.equal(input.DistributionId, 'DIST1');
  assert.equal(input.ConnectionGroupId, 'CG1');
  assert.equal(input.Enabled, true);
  assert.deepEqual(input.Tags, ownTags());
});

// ---- configuration and availability -----------------------------------------------------

test('readConfig needs all six values; reserved is optional', () => {
  const env = {
    ARCO_TENANT_DISTRIBUTION_ID: 'D', ARCO_TENANT_CONNECTION_GROUP_ID: 'C', ARCO_TENANT_TARGET: 't.example.net',
    ARCO_TENANT_ENDPOINT: 'd1.cloudfront.net', ARCO_APP: 'shop', ARCO_ENVIRONMENT: 'prod',
    ARCO_TENANT_RESERVED: 'a.example.com, b.example.com,',
  };
  assert.deepEqual(readConfig(env), {
    distributionId: 'D', connectionGroupId: 'C', target: 't.example.net', endpoint: 'd1.cloudfront.net',
    app: 'shop', environment: 'prod', reserved: ['a.example.com', 'b.example.com'],
  });
  assert.notEqual(readConfig({ ...env, ARCO_TENANT_RESERVED: undefined }), null);
  for (const key of Object.keys(env).filter((k) => k !== 'ARCO_TENANT_RESERVED')) {
    assert.equal(readConfig({ ...env, [key]: '' }), null, key);
    assert.equal(readConfig({ ...env, [key]: undefined }), null, key);
  }
});

test('missing configuration or SDK adapter answers unavailable for every operation', async () => {
  for (const deps of [{ config: null }, { cloudfront: null }]) {
    const fake = fakeCloudFront();
    const handler = createHandler({ config: CONFIG, cloudfront: fake.adapter, dns: okDns, suffixes, log: () => {}, ...deps });
    for (const event of [{ operation: 'add', domain: 'a.customer.com' }, { operation: 'status', domain: 'a.customer.com' },
      { operation: 'remove', domain: 'a.customer.com' }, { operation: 'list' }]) {
      err(await handler(event), 'unavailable');
    }
  }
});

test('the real handler is unavailable without environment variables or SDK', async () => {
  err(await realHandler({ operation: 'list' }), 'unavailable');
});

test('without the public suffix list add answers unavailable, other operations still work', async () => {
  const t = setup({ list: null });
  err(await add(t, 'shop.customer.com'), 'unavailable');
  assert.equal(t.state.calls.length, 0);
  err(await t.handler({ operation: 'status', domain: 'shop.customer.com' }), 'not_found');
  assert.deepEqual(ok(await t.handler({ operation: 'remove', domain: 'shop.customer.com' })), { removed: true });
});

test('unexpected exceptions are returned as failed with name and message', async () => {
  const t = setup();
  t.state.fail.getTenantByDomain = { error: awsError('AccessDenied', 'not allowed') };
  const error = err(await add(t, 'shop.customer.com'), 'failed');
  assert.equal(error.message, 'AccessDenied: not allowed');
  const boom = setup({ dns: { check: async () => { throw new TypeError('boom'); } } });
  assert.equal(err(await add(boom, 'shop.customer.com'), 'failed').message, 'TypeError: boom');
});

test('one JSON log line per invocation, without the full event', async () => {
  const t = setup();
  await t.handler({ operation: 'status', domain: 'shop.customer.com', secret: 'hunter2' });
  assert.equal(t.logs.length, 1);
  assert.deepEqual(JSON.parse(t.logs[0]), { operation: 'status', domain: 'shop.customer.com', ok: false, code: 'not_found' });
  assert.ok(!t.logs[0].includes('hunter2'));
});

// ---- add: domain rules --------------------------------------------------------------------

test('add rejects root domains, suffixes and wildcards before touching AWS', async () => {
  const t = setup();
  for (const domain of ['customer.com', 'customer.co.uk', 'co.uk', 'empresa.com.ar', 'com.ar', 'b.ck', 'github.io', 'foo.unknown']) {
    err(await add(t, domain), 'apex_not_supported');
  }
  assert.equal(t.state.calls.length, 0);
});

test('add accepts subdomains, including deep ones and composite suffixes', async () => {
  const t = setup();
  for (const domain of ['shop.customer.com', 'a.b.c.customer.com', 'shop.customer.co.uk', 'tienda.empresa.com.ar', 'x.me.github.io', 'x.www.ck']) {
    assert.equal(ok(await add(t, domain)).state, 'pending', domain);
  }
});

test('reserved target and domain() names are refused (also with case and trailing dot)', async () => {
  const t = setup();
  err(await add(t, 'domains.shop.example.net'), 'reserved_domain');
  err(await add(t, 'DOMAINS.shop.example.net.'), 'reserved_domain');
  err(await add(t, 'shop.example.com'), 'reserved_domain');
  assert.equal(t.state.calls.length, 0);
});

// ---- add: DNS ------------------------------------------------------------------------------

test('wrong DNS answers dns_incorrect with the expected target and creates nothing', async () => {
  const t = setup({ dns: failDns });
  const error = err(await add(t, 'shop.customer.com'), 'dns_incorrect');
  assert.equal(error.expected, 'domains.shop.example.net');
  assert.deepEqual(t.writes(), []);
});

test('the DNS check receives the normalized domain, target and endpoint', async () => {
  const seen = [];
  const t = setup({ dns: { check: async (args) => { seen.push(args); return true; } } });
  ok(await add(t, 'Shop.Customer.COM.'));
  assert.deepEqual(seen, [{ domain: 'shop.customer.com', target: CONFIG.target, endpoint: CONFIG.endpoint }]);
});

// ---- add: creation -------------------------------------------------------------------------

test('add creates the tenant with ids from config, ownership tags and a CloudFront-validated certificate', async () => {
  const t = setup();
  assert.deepEqual(ok(await add(t, 'Shop.Customer.com')), { domain: 'shop.customer.com', state: 'pending', reason: null });
  assert.deepEqual(t.writes().map((c) => c.method), ['createTenant']);
  assert.deepEqual(t.writes()[0].args[0], {
    DistributionId: 'DIST1',
    ConnectionGroupId: 'CG1',
    Name: 'shop.customer.com',
    Domains: [{ Domain: 'shop.customer.com' }],
    Enabled: true,
    Tags: ownTags(),
    ManagedCertificateRequest: { ValidationTokenHost: 'cloudfront', PrimaryDomainName: 'shop.customer.com' },
  });
});

test('add is idempotent for an owned tenant: no DNS check, no write', async () => {
  let dnsCalls = 0;
  const t = setup({ tenants: [tenantFor('shop.customer.com')], dns: { check: async () => { dnsCalls += 1; return false; } } });
  assert.deepEqual(ok(await add(t, 'shop.customer.com')), { domain: 'shop.customer.com', state: 'pending', reason: null });
  assert.equal(dnsCalls, 0);
  assert.deepEqual(t.writes(), []);
});

test('add on an owned active tenant reports active', async () => {
  const t = setup({ tenants: [tenantFor('shop.customer.com', { Domains: [{ Domain: 'shop.customer.com', Status: 'active' }] })] });
  assert.equal(ok(await add(t, 'shop.customer.com')).state, 'active');
});

test('a tenant held by another app or environment is domain_taken and untouched', async () => {
  for (const tags of [ownTags('other', 'production'), ownTags('shop', 'staging')]) {
    const t = setup({ tenants: [tenantFor('shop.customer.com', { Tags: tags })] });
    err(await add(t, 'shop.customer.com'), 'domain_taken');
    assert.deepEqual(t.writes(), []);
  }
});

test('tenants of another distribution or connection group are foreign and never modified', async () => {
  for (const overrides of [{ DistributionId: 'DIST2' }, { ConnectionGroupId: 'CG2' }]) {
    const t = setup({ tenants: [tenantFor('shop.customer.com', overrides)] });
    err(await add(t, 'shop.customer.com'), 'domain_taken');
    err(await t.handler({ operation: 'status', domain: 'shop.customer.com' }), 'not_found');
    err(await t.handler({ operation: 'remove', domain: 'shop.customer.com' }), 'not_found');
    assert.deepEqual(t.writes(), []);
  }
});

test('a tenant whose tags cannot be read is ownership_unverified, never adopted', async () => {
  const variants = [
    { Tags: undefined },
    { Arn: 'arn:aws:cloudfront::111111111111:distribution-tenant/dt_other' },
    { Arn: undefined },
    { Arn: '' },
    { Tags: { Items: [{ Key: 'arco:app', Value: 5 }, { Key: 'arco:environment', Value: 'production' }] } },
    { ConnectionGroupId: undefined },
    { DistributionId: undefined },
  ];
  for (const overrides of variants) {
    const t = setup({ tenants: [tenantFor('shop.customer.com', overrides)] });
    err(await add(t, 'shop.customer.com'), 'ownership_unverified');
    err(await t.handler({ operation: 'status', domain: 'shop.customer.com' }), 'ownership_unverified');
    err(await t.handler({ operation: 'remove', domain: 'shop.customer.com' }), 'ownership_unverified');
    assert.deepEqual(t.writes(), []);
  }
});

test('a tenant with readable tags that lack ours is foreign: taken, not found, untouched, skipped by list', async () => {
  const variants = [
    { Tags: { Items: [] } },
    { Tags: { Items: [{ Key: 'arco:app', Value: 'shop' }] } },
    { Tags: { Items: [{ Key: 'arco:environment', Value: 'production' }] } },
    { Tags: { Items: [{ Key: 'team', Value: 'x' }] } },
    { Tags: {} },
    { Tags: { Items: 'x' } },
  ];
  for (const overrides of variants) {
    const t = setup({ tenants: [tenantFor('hand.customer.com', overrides), tenantFor('mine.customer.com')] });
    err(await add(t, 'hand.customer.com'), 'domain_taken');
    err(await t.handler({ operation: 'status', domain: 'hand.customer.com' }), 'not_found');
    err(await t.handler({ operation: 'remove', domain: 'hand.customer.com' }), 'not_found');
    assert.deepEqual(t.writes(), []);
    assert.deepEqual(ok(await t.handler({ operation: 'list' })).domains.map((d) => d.domain), ['mine.customer.com']);
  }
});

test('a tenant without a Tags member makes list fail as a whole', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com'), tenantFor('b.customer.com', { Tags: undefined })] });
  err(await t.handler({ operation: 'list' }), 'ownership_unverified');
});

test('remove never deletes a tenant holding more than the requested domain', async () => {
  for (const Enabled of [true, false]) {
    for (const Domains of [
      [{ Domain: 'a.customer.com', Status: 'active' }, { Domain: 'b.customer.com', Status: 'active' }],
      [{ Domain: 'b.customer.com', Status: 'active' }, { Domain: 'a.customer.com', Status: 'active' }],
    ]) {
      const t = setup({ tenants: [tenantFor('a.customer.com', { Enabled, Domains })] });
      err(await t.handler({ operation: 'remove', domain: 'a.customer.com' }), 'ownership_unverified');
      assert.deepEqual(t.writes(), []);
    }
  }
  const mismatch = setup();
  mismatch.adapter.getTenantByDomain = async () => ({ tenant: tenantFor('a.customer.com', { Domains: [{ Domain: 'z.customer.com' }] }), etag: 'E1' });
  err(await mismatch.handler({ operation: 'remove', domain: 'a.customer.com' }), 'ownership_unverified');
  assert.deepEqual(mismatch.writes(), []);
});

test('a duplicate error from create is resolved by looking the domain up', async () => {
  for (const name of ['CNAMEAlreadyExists', 'EntityAlreadyExists']) {
    // owned: someone created it between our lookup and our create
    const owned = setup();
    owned.state.fail.createTenant = { error: awsError(name) };
    const origCreate = owned.adapter.createTenant;
    owned.adapter.createTenant = async (input) => {
      try { return await origCreate(input); } catch (e) {
        owned.put(tenantFor('shop.customer.com'));
        throw e;
      }
    };
    assert.equal(ok(await add(owned, 'shop.customer.com')).state, 'pending');

    // foreign tenant appears
    const foreign = setup();
    foreign.state.fail.createTenant = { error: awsError(name) };
    const origForeign = foreign.adapter.createTenant;
    foreign.adapter.createTenant = async (input) => {
      try { return await origForeign(input); } catch (e) {
        foreign.put(tenantFor('shop.customer.com', { Tags: ownTags('other', 'x') }));
        throw e;
      }
    };
    err(await add(foreign, 'shop.customer.com'), 'domain_taken');

    // unverifiable tenant appears
    const unknown = setup();
    unknown.state.fail.createTenant = { error: awsError(name) };
    const origUnknown = unknown.adapter.createTenant;
    unknown.adapter.createTenant = async (input) => {
      try { return await origUnknown(input); } catch (e) {
        unknown.put(tenantFor('shop.customer.com', { Tags: undefined }));
        throw e;
      }
    };
    err(await add(unknown, 'shop.customer.com'), 'ownership_unverified');

    // nothing visible: in use somewhere we cannot see
    const hidden = setup();
    hidden.state.fail.createTenant = { error: awsError(name) };
    err(await add(hidden, 'shop.customer.com'), 'domain_taken');
  }
});

test('EntityLimitExceeded is quota; other create errors are failed', async () => {
  const quota = setup();
  quota.state.fail.createTenant = { error: awsError('EntityLimitExceeded') };
  err(await add(quota, 'shop.customer.com'), 'quota');
  const other = setup();
  other.state.fail.createTenant = { error: awsError('InvalidArgument', 'bad') };
  assert.equal(err(await add(other, 'shop.customer.com'), 'failed').message, 'InvalidArgument: bad');
});

// ---- state ---------------------------------------------------------------------------------

test('state: active, pending, failed with reason, removing', async () => {
  const t = setup({
    tenants: [
      tenantFor('active.customer.com', { Domains: [{ Domain: 'active.customer.com', Status: 'active' }] }),
      tenantFor('pending.customer.com'),
      tenantFor('issued.customer.com'),
      tenantFor('failed.customer.com'),
      tenantFor('timeout.customer.com'),
      tenantFor('removing.customer.com', { Enabled: false }),
      tenantFor('disabled-active.customer.com', { Enabled: false, Domains: [{ Domain: 'disabled-active.customer.com', Status: 'active' }] }),
    ],
  });
  t.state.certs['dt_pending.customer.com'] = { CertificateStatus: 'pending-validation' };
  t.state.certs['dt_issued.customer.com'] = { CertificateStatus: 'issued' };
  t.state.certs['dt_failed.customer.com'] = { CertificateStatus: 'failed' };
  t.state.certs['dt_timeout.customer.com'] = { CertificateStatus: 'validation-timed-out' };
  const status = async (domain) => ok(await t.handler({ operation: 'status', domain }));
  assert.deepEqual(await status('active.customer.com'), { domain: 'active.customer.com', state: 'active', reason: null });
  assert.deepEqual(await status('pending.customer.com'), { domain: 'pending.customer.com', state: 'pending', reason: null });
  assert.deepEqual(await status('issued.customer.com'), { domain: 'issued.customer.com', state: 'pending', reason: null });
  assert.deepEqual(await status('failed.customer.com'), { domain: 'failed.customer.com', state: 'failed', reason: 'certificate failed' });
  assert.deepEqual(await status('timeout.customer.com'), { domain: 'timeout.customer.com', state: 'failed', reason: 'certificate validation-timed-out' });
  assert.deepEqual(await status('removing.customer.com'), { domain: 'removing.customer.com', state: 'pending', reason: 'removing' });
  assert.deepEqual(await status('disabled-active.customer.com'), { domain: 'disabled-active.customer.com', state: 'pending', reason: 'removing' });
  assert.equal(await status('PENDING.customer.com.').then((r) => r.domain), 'pending.customer.com');
});

test('every failing certificate status maps to failed', async () => {
  for (const status of ['failed', 'validation-timed-out', 'revoked', 'expired', 'inactive']) {
    const t = setup({ tenants: [tenantFor('a.customer.com')] });
    t.state.certs['dt_a.customer.com'] = { CertificateStatus: status };
    const result = ok(await t.handler({ operation: 'status', domain: 'a.customer.com' }));
    assert.deepEqual([result.state, result.reason], ['failed', `certificate ${status}`]);
  }
});

test('state that cannot be verified is ownership_unverified', async () => {
  const noEntry = setup();
  noEntry.adapter.getTenantByDomain = async () => ({ tenant: tenantFor('a.customer.com', { Domains: [{ Domain: 'other.customer.com', Status: 'active' }] }), etag: 'E1' });
  err(await noEntry.handler({ operation: 'status', domain: 'a.customer.com' }), 'ownership_unverified');

  const certFails = setup({ tenants: [tenantFor('a.customer.com')] });
  certFails.state.fail.certificate = { error: awsError('AccessDenied') };
  err(await certFails.handler({ operation: 'status', domain: 'a.customer.com' }), 'ownership_unverified');

  const notFound = setup({ tenants: [tenantFor('a.customer.com')] });
  notFound.state.fail.certificate = { error: awsError('EntityNotFound') };
  assert.equal(ok(await notFound.handler({ operation: 'status', domain: 'a.customer.com' })).state, 'pending');
});

test('status of an unknown domain is not_found', async () => {
  const t = setup();
  err(await t.handler({ operation: 'status', domain: 'nothing.customer.com' }), 'not_found');
});

// ---- list ----------------------------------------------------------------------------------

const manyTenants = (n) => Array.from({ length: n }, (_, i) => tenantFor(`s${i}.customer.com`));
const decode = (cursor) => JSON.parse(Buffer.from(cursor, 'base64url').toString('utf8'));
const listAll = async (t, limit) => {
  const domains = [];
  let cursor = null;
  for (let i = 0; i < 50; i += 1) {
    const result = ok(await t.handler({ operation: 'list', cursor, limit }));
    domains.push(...result.domains.map((d) => d.domain));
    if (result.cursor === null) return domains;
    cursor = result.cursor;
  }
  throw new Error('too many pages');
};

test('list pages through an opaque cursor and ends with null', async () => {
  const t = setup({ tenants: manyTenants(5), pageSize: 2 });
  const first = ok(await t.handler({ operation: 'list', limit: 2 }));
  assert.equal(first.domains.length, 2);
  assert.equal(typeof first.cursor, 'string');
  assert.deepEqual(decode(first.cursor), { m: '2' });
  assert.deepEqual(await listAll(t, 2), ['s0', 's1', 's2', 's3', 's4'].map((s) => `${s}.customer.com`));
  const last = ok(await t.handler({ operation: 'list', cursor: first.cursor, limit: 2 }));
  assert.equal(last.domains.length, 2);
  const calls = t.state.calls.filter((c) => c.method === 'listTenants');
  assert.deepEqual(calls[0].args[0], { distributionId: 'DIST1', marker: undefined, maxItems: 2 });
  const small = setup({ tenants: manyTenants(2) });
  assert.equal(ok(await small.handler({ operation: 'list', limit: 5 })).cursor, null);
});

test('list default limit is 50 and a limit of 100 is accepted', async () => {
  const t = setup();
  ok(await t.handler({ operation: 'list' }));
  ok(await t.handler({ operation: 'list', limit: 100 }));
  const sizes = t.state.calls.filter((c) => c.method === 'listTenants').map((c) => c.args[0].maxItems);
  assert.deepEqual(sizes, [50, 100]);
});

test('list limit and cursor validation', async () => {
  const t = setup({ tenants: manyTenants(2) });
  for (const limit of [0, 101, -1, 1.5, '10', true, NaN]) err(await t.handler({ operation: 'list', limit }), 'invalid_request');
  const b64 = (v) => Buffer.from(typeof v === 'string' ? v : JSON.stringify(v)).toString('base64url');
  for (const cursor of [5, '', '!!!', 'plain', b64('not json'), b64([1]), b64({}), b64({ m: 5 }), b64({ m: '' }), b64({ m: 'x', extra: 1 }),
    `${b64({ m: 'x' })}==`, 'a'.repeat(3000)]) {
    err(await t.handler({ operation: 'list', cursor }), 'invalid_request');
  }
  assert.equal(t.state.calls.length, 0);
});

test('foreign tenants are never exposed and an empty filtered page keeps its cursor', async () => {
  const tenants = [
    tenantFor('mine1.customer.com'),
    tenantFor('theirs1.customer.com', { Tags: ownTags('other', 'production') }),
    tenantFor('theirs2.customer.com', { Tags: ownTags('shop', 'staging') }),
    tenantFor('otherdist.customer.com', { DistributionId: 'DIST2' }),
    tenantFor('othergroup.customer.com', { ConnectionGroupId: 'CG2' }),
    tenantFor('mine2.customer.com'),
  ];
  const t = setup({ tenants, pageSize: 2 });
  const pages = [];
  let cursor = null;
  do {
    const result = ok(await t.handler({ operation: 'list', cursor, limit: 2 }));
    pages.push(result);
    cursor = result.cursor;
  } while (cursor !== null);
  assert.deepEqual(pages.map((p) => p.domains.map((d) => d.domain)), [['mine1.customer.com'], [], ['mine2.customer.com']]);
  assert.equal(pages[1].cursor !== null, true);
  assert.ok(!JSON.stringify(pages).includes('theirs'));
  // summaries with a different distribution or group never cost a full read
  const reads = t.state.calls.filter((c) => c.method === 'getTenant').map((c) => c.args[0]);
  assert.ok(!reads.includes('dt_otherdist.customer.com') && !reads.includes('dt_othergroup.customer.com'));
});

test('a tenant deleted between list and read is skipped', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com'), tenantFor('b.customer.com')] });
  const original = t.adapter.getTenant;
  t.adapter.getTenant = async (id) => (id === 'dt_a.customer.com' ? null : original(id));
  assert.deepEqual(ok(await t.handler({ operation: 'list' })).domains.map((d) => d.domain), ['b.customer.com']);
});

test('list fails as a whole when ownership of any summary cannot be verified', async () => {
  const variants = [
    (t) => { t.state.fail.getTenant = { error: awsError('AccessDenied'), always: true }; },
    (t) => { t.put(tenantFor('c.customer.com', { Id: 'dt_untagged', Tags: undefined })); },
    (t) => { t.put(tenantFor('c.customer.com', { Arn: 'arn:aws:cloudfront::111111111111:distribution-tenant/dt_elsewhere' })); },
    (t) => { t.state.summaries = [{ Name: 'no-id' }]; },
  ];
  for (const mutate of variants) {
    const t = setup({ tenants: [tenantFor('a.customer.com'), tenantFor('b.customer.com')] });
    mutate(t);
    err(await t.handler({ operation: 'list' }), 'ownership_unverified');
  }
});

test('list fails when a page certificate lookup fails, and lists one status per domain', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com', { Domains: [{ Domain: 'a.customer.com', Status: 'active' }, { Domain: 'A2.customer.com.', Status: 'active' }] })] });
  assert.deepEqual(ok(await t.handler({ operation: 'list' })).domains.map((d) => d.domain), ['a.customer.com', 'a2.customer.com']);
  const broken = setup({ tenants: [tenantFor('a.customer.com')] });
  broken.state.fail.certificate = { error: awsError('AccessDenied') };
  err(await broken.handler({ operation: 'list' }), 'ownership_unverified');
});

test('a repeated NextMarker is failed (no progress)', async () => {
  const t = setup({ tenants: manyTenants(1) });
  t.state.pages = { '': { items: [], nextMarker: 'm1' }, m1: { items: [], nextMarker: 'm1' } };
  const first = ok(await t.handler({ operation: 'list' }));
  assert.deepEqual(decode(first.cursor), { m: 'm1' });
  err(await t.handler({ operation: 'list', cursor: first.cursor }), 'failed');
});

test('an empty NextMarker ends the list', async () => {
  const t = setup();
  t.state.pages = { '': { items: [], nextMarker: '' } };
  assert.equal(ok(await t.handler({ operation: 'list' })).cursor, null);
});

// ---- remove --------------------------------------------------------------------------------

test('remove of an absent domain is a success', async () => {
  const t = setup();
  assert.deepEqual(ok(await t.handler({ operation: 'remove', domain: 'gone.customer.com' })), { removed: true });
  assert.deepEqual(t.writes(), []);
});

test('remove disables with the read ETag, then deletes with the ETag the update returned', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  const before = t.state.etags.get('dt_a.customer.com');
  assert.deepEqual(ok(await t.handler({ operation: 'remove', domain: 'A.customer.com' })), { removed: true });
  const [update, del] = t.writes();
  assert.equal(update.method, 'updateTenant');
  assert.deepEqual(update.args, ['dt_a.customer.com', before, {
    Enabled: false, DistributionId: 'DIST1', ConnectionGroupId: 'CG1', Domains: [{ Domain: 'a.customer.com' }],
  }]);
  assert.equal(del.method, 'deleteTenant');
  assert.equal(del.args[0], 'dt_a.customer.com');
  assert.notEqual(del.args[1], before);
  assert.equal(t.store.has('dt_a.customer.com'), false);
});

test('remove re-reads the tenant when the update returns no ETag', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  const original = t.adapter.updateTenant;
  t.adapter.updateTenant = async (...args) => ({ ...(await original(...args)), etag: undefined });
  assert.deepEqual(ok(await t.handler({ operation: 'remove', domain: 'a.customer.com' })), { removed: true });
  assert.equal(t.store.has('dt_a.customer.com'), false);
});

test('remove of an already disabled tenant only deletes', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com', { Enabled: false })] });
  assert.deepEqual(ok(await t.handler({ operation: 'remove', domain: 'a.customer.com' })), { removed: true });
  assert.deepEqual(t.writes().map((c) => c.method), ['deleteTenant']);
});

test('delete refused after disabling is removal_pending', async () => {
  for (const name of ['ResourceNotDisabled', 'PreconditionFailed']) {
    const t = setup({ tenants: [tenantFor('a.customer.com')] });
    t.state.fail.deleteTenant = { error: awsError(name) };
    err(await t.handler({ operation: 'remove', domain: 'a.customer.com' }), 'removal_pending');
    assert.equal(t.store.get('dt_a.customer.com').Enabled, false);
  }
});

test('delete of a tenant that vanished is a success; other delete errors are failed', async () => {
  const gone = setup({ tenants: [tenantFor('a.customer.com', { Enabled: false })] });
  gone.state.fail.deleteTenant = { error: awsError('EntityNotFound') };
  assert.deepEqual(ok(await gone.handler({ operation: 'remove', domain: 'a.customer.com' })), { removed: true });
  const bad = setup({ tenants: [tenantFor('a.customer.com', { Enabled: false })] });
  bad.state.fail.deleteTenant = { error: awsError('AccessDenied', 'no') };
  err(await bad.handler({ operation: 'remove', domain: 'a.customer.com' }), 'failed');
  const stale = setup({ tenants: [tenantFor('a.customer.com', { Enabled: false })] });
  stale.state.fail.deleteTenant = { error: awsError('InvalidIfMatchVersion') };
  err(await stale.handler({ operation: 'remove', domain: 'a.customer.com' }), 'conflict');
});

test('ETag mismatch on update is conflict and nothing is deleted', async () => {
  for (const name of ['InvalidIfMatchVersion', 'PreconditionFailed']) {
    const t = setup({ tenants: [tenantFor('a.customer.com')] });
    t.state.fail.updateTenant = { error: awsError(name) };
    err(await t.handler({ operation: 'remove', domain: 'a.customer.com' }), 'conflict');
    assert.deepEqual(t.writes().map((c) => c.method), ['updateTenant']);
  }
});

test('a missing ETag is conflict and no write is sent without IfMatch', async () => {
  const first = setup({ tenants: [tenantFor('a.customer.com')] });
  first.state.omitEtag.add('dt_a.customer.com');
  err(await first.handler({ operation: 'remove', domain: 'a.customer.com' }), 'conflict');
  assert.deepEqual(first.writes(), []);

  const disabled = setup({ tenants: [tenantFor('a.customer.com', { Enabled: false })] });
  disabled.state.omitEtag.add('dt_a.customer.com');
  err(await disabled.handler({ operation: 'remove', domain: 'a.customer.com' }), 'conflict');
  assert.deepEqual(disabled.writes(), []);

  const afterUpdate = setup({ tenants: [tenantFor('a.customer.com')] });
  const original = afterUpdate.adapter.updateTenant;
  afterUpdate.adapter.updateTenant = async (...args) => {
    const result = await original(...args);
    afterUpdate.state.omitEtag.add('dt_a.customer.com');
    return { ...result, etag: undefined };
  };
  err(await afterUpdate.handler({ operation: 'remove', domain: 'a.customer.com' }), 'conflict');
  assert.deepEqual(afterUpdate.writes().map((c) => c.method), ['updateTenant']);
});

test('remove never touches a foreign tenant', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com', { Tags: ownTags('other', 'production') })] });
  err(await t.handler({ operation: 'remove', domain: 'a.customer.com' }), 'not_found');
  assert.deepEqual(t.writes(), []);
  assert.equal(t.store.get('dt_a.customer.com').Enabled, true);
});

// ---- inactive domains (GetDistributionTenantByDomain only finds active ones) -----------------

test('after add, status finds the tenant by name while its domain is still inactive', async () => {
  const t = setup();
  ok(await add(t, 'shop.customer.com'));
  assert.deepEqual(ok(await t.handler({ operation: 'status', domain: 'shop.customer.com' })), { domain: 'shop.customer.com', state: 'pending', reason: null });
  const lookups = t.methods();
  assert.ok(lookups.includes('getTenantByDomain') && lookups.includes('getTenant'));
});

test('a second add of an inactive-domain tenant is idempotent: no create, no DNS check', async () => {
  let dnsCalls = 0;
  const t = setup({ dns: { check: async () => { dnsCalls += 1; return true; } } });
  ok(await add(t, 'shop.customer.com'));
  assert.equal(dnsCalls, 1);
  assert.deepEqual(ok(await add(t, 'shop.customer.com')), { domain: 'shop.customer.com', state: 'pending', reason: null });
  assert.equal(dnsCalls, 1);
  assert.equal(t.state.calls.filter((c) => c.method === 'createTenant').length, 1);
});

test('remove works on a tenant whose domain is still inactive', async () => {
  const t = setup();
  ok(await add(t, 'shop.customer.com'));
  assert.deepEqual(ok(await t.handler({ operation: 'remove', domain: 'shop.customer.com' })), { removed: true });
  assert.equal(t.store.size, 0);
});

test('a foreign tenant holding the name: add is domain_taken, status and remove are not_found, nothing written', async () => {
  const t = setup({ tenants: [tenantFor('shop.customer.com', { Tags: ownTags('other', 'production') })] });
  err(await add(t, 'shop.customer.com'), 'domain_taken');
  err(await t.handler({ operation: 'status', domain: 'shop.customer.com' }), 'not_found');
  err(await t.handler({ operation: 'remove', domain: 'shop.customer.com' }), 'not_found');
  assert.deepEqual(t.writes(), []);
});

test('a tenant found by name that does not list the domain is not found, except after a duplicate error on create', async () => {
  const other = tenantFor('shop.customer.com', { Domains: [{ Domain: 'else.customer.com', Status: 'inactive' }], Tags: ownTags('other', 'x') });
  const t = setup({ tenants: [other] });
  err(await t.handler({ operation: 'status', domain: 'shop.customer.com' }), 'not_found');
  assert.deepEqual(ok(await t.handler({ operation: 'remove', domain: 'shop.customer.com' })), { removed: true });
  assert.deepEqual(t.writes(), []);
  // add: nothing listing the domain, so it tries to create; the name collides and the lenient lookup says foreign
  t.state.fail.createTenant = { error: awsError('EntityAlreadyExists') };
  err(await add(t, 'shop.customer.com'), 'domain_taken');
  assert.deepEqual(t.writes().map((c) => c.method), ['createTenant']);
});

test('duplicate error with nothing found by either lookup stays domain_taken', async () => {
  const t = setup();
  t.state.fail.createTenant = { error: awsError('CNAMEAlreadyExists') };
  err(await add(t, 'shop.customer.com'), 'domain_taken');
});

// ---- activation once the certificate is issued ----------------------------------------------

const ARN = 'arn:aws:acm:us-east-1:111111111111:certificate/abc';
const issued = (t, id = 'dt_a.customer.com') => { t.state.certs[id] = { CertificateStatus: 'issued', CertificateArn: ARN }; };
const updates = (t) => t.state.calls.filter((c) => c.method === 'updateTenant');

test('issued certificate and inactive domain: status applies it once and answers pending/activating', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  issued(t);
  const etag = t.state.etags.get('dt_a.customer.com');
  assert.deepEqual(ok(await t.handler({ operation: 'status', domain: 'a.customer.com' })), { domain: 'a.customer.com', state: 'pending', reason: 'activating' });
  assert.equal(updates(t).length, 1);
  assert.deepEqual(updates(t)[0].args, ['dt_a.customer.com', etag, {
    DistributionId: 'DIST1', ConnectionGroupId: 'CG1', Domains: [{ Domain: 'a.customer.com' }], Enabled: true,
    Customizations: { Certificate: { Arn: ARN } },
  }]);
  assert.equal(t.writes().length, 1);
  // the tenant now carries it: no second update
  assert.deepEqual(ok(await t.handler({ operation: 'status', domain: 'a.customer.com' })).reason, 'activating');
  assert.equal(updates(t).length, 1);
});

test('the idempotent path of add also activates', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  issued(t);
  assert.equal(ok(await add(t, 'a.customer.com')).reason, 'activating');
  assert.equal(updates(t).length, 1);
});

test('a tenant already carrying the certificate: pending/activating without any call', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com', { Customizations: { Certificate: { Arn: ARN } } })] });
  issued(t);
  assert.deepEqual(ok(await t.handler({ operation: 'status', domain: 'a.customer.com' })).reason, 'activating');
  assert.deepEqual(t.writes(), []);
});

test('existing customizations are preserved in the activation update', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com', { Customizations: { WebAcl: { Action: 'override', Arn: 'arn:waf' } } })] });
  issued(t);
  ok(await t.handler({ operation: 'status', domain: 'a.customer.com' }));
  assert.deepEqual(updates(t)[0].args[2].Customizations, { WebAcl: { Action: 'override', Arn: 'arn:waf' }, Certificate: { Arn: ARN } });
});

test('certificate not issued, issued without ARN, disabled tenant or no ETag: no update', async () => {
  const pending = setup({ tenants: [tenantFor('a.customer.com')] });
  pending.state.certs['dt_a.customer.com'] = { CertificateStatus: 'pending-validation', CertificateArn: ARN };
  assert.deepEqual(ok(await pending.handler({ operation: 'status', domain: 'a.customer.com' })), { domain: 'a.customer.com', state: 'pending', reason: null });
  assert.deepEqual(pending.writes(), []);

  const noArn = setup({ tenants: [tenantFor('a.customer.com')] });
  noArn.state.certs['dt_a.customer.com'] = { CertificateStatus: 'issued' };
  assert.equal(ok(await noArn.handler({ operation: 'status', domain: 'a.customer.com' })).reason, null);
  assert.deepEqual(noArn.writes(), []);

  const disabled = setup({ tenants: [tenantFor('a.customer.com', { Enabled: false })] });
  issued(disabled);
  assert.equal(ok(await disabled.handler({ operation: 'status', domain: 'a.customer.com' })).reason, 'removing');
  assert.deepEqual(disabled.writes(), []);

  const noEtag = setup({ tenants: [tenantFor('a.customer.com')] });
  issued(noEtag);
  noEtag.state.omitEtag.add('dt_a.customer.com');
  assert.equal(ok(await noEtag.handler({ operation: 'status', domain: 'a.customer.com' })).reason, null);
  assert.deepEqual(noEtag.writes(), []);
});

test('a failed update does not fail the operation, reports the error name and is retried by the next status', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  issued(t);
  t.state.fail.updateTenant = { error: awsError('AccessDenied', 'secret details') };
  const response = await t.handler({ operation: 'status', domain: 'a.customer.com' });
  assert.deepEqual(ok(response), { domain: 'a.customer.com', state: 'pending', reason: 'activation failed: AccessDenied' });
  assert.deepEqual(ok(await t.handler({ operation: 'status', domain: 'a.customer.com' })).reason, 'activating');
  assert.equal(updates(t).length, 2);
});

test('the activation error name goes to the log line as detail', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  issued(t);
  t.state.fail.updateTenant = { error: awsError('PreconditionFailed', 'x') };
  await t.handler({ operation: 'status', domain: 'a.customer.com' });
  assert.equal(JSON.parse(t.logs[0]).detail, 'activation failed: PreconditionFailed');
  await t.handler({ operation: 'status', domain: 'a.customer.com' });
  assert.equal('detail' in JSON.parse(t.logs[1]), false);
});

test('list never updates, and foreign or unverifiable tenants are never activated', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  issued(t);
  assert.deepEqual(ok(await t.handler({ operation: 'list' })).domains.map((d) => d.reason), [null]);
  assert.deepEqual(t.writes(), []);

  const foreign = setup({ tenants: [tenantFor('a.customer.com', { Tags: ownTags('other', 'x') })] });
  issued(foreign);
  err(await foreign.handler({ operation: 'status', domain: 'a.customer.com' }), 'not_found');
  err(await add(foreign, 'a.customer.com'), 'domain_taken');
  const unknown = setup({ tenants: [tenantFor('a.customer.com', { Tags: undefined })] });
  issued(unknown);
  err(await unknown.handler({ operation: 'status', domain: 'a.customer.com' }), 'ownership_unverified');
  assert.deepEqual([...foreign.writes(), ...unknown.writes()], []);
});

test('remove never activates', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  issued(t);
  ok(await t.handler({ operation: 'remove', domain: 'a.customer.com' }));
  assert.ok(updates(t).every((c) => c.args[2].Enabled === false && !('Customizations' in c.args[2])));
});

test('active domains and failed certificates keep their states', async () => {
  const active = setup({ tenants: [tenantFor('a.customer.com', { Domains: [{ Domain: 'a.customer.com', Status: 'active' }] })] });
  issued(active);
  assert.equal(ok(await active.handler({ operation: 'status', domain: 'a.customer.com' })).state, 'active');
  assert.deepEqual(active.writes(), []);
  const failed = setup({ tenants: [tenantFor('a.customer.com')] });
  failed.state.certs['dt_a.customer.com'] = { CertificateStatus: 'failed', CertificateArn: ARN };
  assert.equal(ok(await failed.handler({ operation: 'status', domain: 'a.customer.com' })).state, 'failed');
});

// ---- tags come from ListTagsForResource (the tenant reads carry none on real CloudFront) --------

const tagCalls = (t) => t.state.calls.filter((c) => c.method === 'tags').map((c) => c.args[0]);

test('the fake models reality: tenant reads carry no tags', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  assert.equal('Tags' in (await t.adapter.getTenant('dt_a.customer.com')).tenant, false);
  assert.equal('Tags' in (await t.adapter.getTenantByDomain('nothing.customer.com') ?? { tenant: {} }).tenant, false);
});

test('status of an owned tenant whose tags come from tags() works and proceeds to activation', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  t.state.certs['dt_a.customer.com'] = { CertificateStatus: 'issued', CertificateArn: 'arn:acm:x' };
  assert.equal(ok(await t.handler({ operation: 'status', domain: 'a.customer.com' })).reason, 'activating');
  assert.deepEqual(tagCalls(t), ['arn:aws:cloudfront::111111111111:distribution-tenant/dt_a.customer.com']);
  assert.equal(t.state.calls.filter((c) => c.method === 'updateTenant').length, 1);
});

test('tags() throwing is ownership_unverified, nothing is written, and the error name is logged', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com')] });
  t.state.fail.tags = { error: awsError('AccessDenied', 'secret'), always: true };
  err(await t.handler({ operation: 'status', domain: 'a.customer.com' }), 'ownership_unverified');
  assert.equal(JSON.parse(t.logs[0]).detail, 'tags unreadable: AccessDenied');
  err(await t.handler({ operation: 'remove', domain: 'a.customer.com' }), 'ownership_unverified');
  err(await add(t, 'a.customer.com'), 'ownership_unverified');
  assert.deepEqual(t.writes(), []);
});

test('an ARN that does not match the tenant id is never used to read tags', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com', { Arn: 'arn:aws:cloudfront::111111111111:distribution-tenant/dt_other' })] });
  err(await t.handler({ operation: 'status', domain: 'a.customer.com' }), 'ownership_unverified');
  assert.deepEqual(tagCalls(t), []);
});

test('inline tags are kept and tags() is not called', async () => {
  const t = setup();
  t.adapter.getTenantByDomain = async () => ({ tenant: tenantFor('a.customer.com'), etag: 'E1' });
  assert.equal(ok(await t.handler({ operation: 'status', domain: 'a.customer.com' })).state, 'pending');
  assert.deepEqual(tagCalls(t), []);
});

test('list reads tags once per candidate tenant and skips those of other groups without reading', async () => {
  const t = setup({ tenants: [tenantFor('a.customer.com'), tenantFor('b.customer.com', { Tags: ownTags('other', 'x') }),
    tenantFor('c.customer.com', { ConnectionGroupId: 'CG2' }), tenantFor('d.customer.com')] });
  assert.deepEqual(ok(await t.handler({ operation: 'list' })).domains.map((d) => d.domain), ['a.customer.com', 'd.customer.com']);
  assert.equal(tagCalls(t).length, 3);
  assert.ok(!tagCalls(t).some((a) => a.endsWith('dt_c.customer.com')));
});
