'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { loadVariables, parsePointer } = require('../lib/variables');
const { createFetchers } = require('../lib/aws');

const bundle = (variables) => JSON.stringify({ schema: 1, variables });
const keyOf = (body) => `variables/shop/staging/${crypto.createHash('sha256').update(body).digest('hex')}.json`;

test('without ARCO_VARIABLES nothing is fetched', async () => {
  const loaded = await loadVariables({ APP_NAME: 'Shop' }, async () => assert.fail('no S3 call expected'));
  assert.deepEqual(loaded, {});
});

test('loads the bundle without overriding the real environment', async () => {
  const body = bundle({ APP_NAME: 'Shop', APP_KEY: 'arco-ssm:/shop/staging/app-key', AWS_REGION: 'eu-west-1', 'LINE': 'a\nb ñ' });
  const key = keyOf(body);
  const calls = [];
  const loaded = await loadVariables(
    { ARCO_VARIABLES: `s3://arco-1-us-east-1/${key}`, AWS_REGION: 'us-east-1' },
    async (bucket, objectKey) => { calls.push([bucket, objectKey]); return Buffer.from(body); },
  );
  assert.deepEqual(calls, [['arco-1-us-east-1', key]]);
  assert.deepEqual(loaded, { APP_NAME: 'Shop', APP_KEY: 'arco-ssm:/shop/staging/app-key', LINE: 'a\nb ñ' });
});

test('refuses a body that does not match its key, or that is not a bundle', async () => {
  const body = bundle({ A: '1' });
  const pointer = `s3://b/${keyOf(body)}`;
  await assert.rejects(loadVariables({ ARCO_VARIABLES: pointer }, async () => Buffer.from(bundle({ A: '2' }))), /checksum/);
  for (const bad of [JSON.stringify({ schema: 2, variables: {} }), JSON.stringify({ schema: 1, variables: { A: 1 } }), 'not json']) {
    await assert.rejects(loadVariables({ ARCO_VARIABLES: `s3://b/${keyOf(bad)}` }, async () => Buffer.from(bad)), /not a variable bundle/);
  }
});

test('parses the pointer', () => {
  assert.deepEqual(parsePointer('s3://bucket/variables/a/b/c.json'), { bucket: 'bucket', key: 'variables/a/b/c.json' });
  assert.throws(() => parsePointer('https://bucket/key'), /ARCO_VARIABLES/);
  assert.throws(() => parsePointer('s3://bucket'), /ARCO_VARIABLES/);
});

test('getObject signs a GET to the regional virtual-hosted endpoint, or to ARCO_S3_ENDPOINT path style', async () => {
  const requests = [];
  const get = async (url, headers) => { requests.push({ url, headers }); return { status: 200, body: Buffer.from('{}') }; };
  const env = { AWS_ACCESS_KEY_ID: 'AKID', AWS_SECRET_ACCESS_KEY: 'secret', AWS_SESSION_TOKEN: 'token' };

  const body = await createFetchers('us-east-1', { env, get }).getObject('arco-1-us-east-1', 'variables/shop/staging/x.json');
  assert.equal(body.toString(), '{}');
  assert.equal(requests[0].url, 'https://arco-1-us-east-1.s3.us-east-1.amazonaws.com/variables/shop/staging/x.json');
  assert.equal(requests[0].headers.host, 'arco-1-us-east-1.s3.us-east-1.amazonaws.com');
  assert.equal(requests[0].headers['x-amz-security-token'], 'token');
  assert.equal(requests[0].headers['x-amz-content-sha256'], crypto.createHash('sha256').update('').digest('hex'));
  assert.match(requests[0].headers.authorization, /^AWS4-HMAC-SHA256 Credential=AKID\/\d{8}\/us-east-1\/s3\/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-security-token, /);

  await createFetchers('us-east-1', { env: { ...env, ARCO_S3_ENDPOINT: 'http://127.0.0.1:4566' }, get }).getObject('b', 'k/x.json');
  assert.equal(requests[1].url, 'http://127.0.0.1:4566/b/k/x.json');
  assert.equal(requests[1].headers.host, '127.0.0.1:4566');

  const failing = createFetchers('us-east-1', { env, get: async () => ({ status: 403, body: Buffer.from('AccessDenied') }) });
  await assert.rejects(failing.getObject('b', 'k'), /s3:\/\/b\/k.*HTTP 403.*AccessDenied/);
});
