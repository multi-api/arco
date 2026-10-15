'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadCredentials } = require('../lib/credentials');

test('reads credentials from the environment', async () => {
  const creds = await loadCredentials({ AWS_ACCESS_KEY_ID: 'AK', AWS_SECRET_ACCESS_KEY: 'SK', AWS_SESSION_TOKEN: 'T' });
  assert.deepEqual(creds, { accessKeyId: 'AK', secretAccessKey: 'SK', sessionToken: 'T' });
});

test('reads credentials from the container credentials endpoint', async () => {
  let seen;
  const fakeFetch = async (url, options) => {
    seen = { url, options };
    return { ok: true, status: 200, json: async () => ({ AccessKeyId: 'AK2', SecretAccessKey: 'SK2', Token: 'T2' }) };
  };
  const creds = await loadCredentials(
    { AWS_CONTAINER_CREDENTIALS_FULL_URI: 'http://169.254.170.2/creds', AWS_CONTAINER_AUTHORIZATION_TOKEN: 'auth' },
    fakeFetch,
  );
  assert.deepEqual(creds, { accessKeyId: 'AK2', secretAccessKey: 'SK2', sessionToken: 'T2' });
  assert.equal(seen.options.headers.Authorization, 'auth');
});

test('fails clearly without credentials', async () => {
  await assert.rejects(loadCredentials({}), /No AWS credentials/);
});
