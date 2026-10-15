'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { resolveSecrets } = require('../lib/secrets');

test('resolves references and keeps exact string values', async () => {
  const calls = { ssm: [], secrets: [] };
  const result = await resolveSecrets(
    {
      APP_NAME: 'Shop',
      APP_KEY: 'arco-ssm:/shop/prod/app-key',
      DB_PASSWORD: 'arco-secret:shop/prod/db#password',
      DB_PORT: 'arco-secret:shop/prod/db#port',
      DB_SSL: 'arco-secret:shop/prod/db#ssl',
      CERT: 'arco-secret:shop/prod/cert',
    },
    {
      getParameters: async (names) => {
        calls.ssm.push(names);
        return { Parameters: [{ Name: '/shop/prod/app-key', Value: 'base64:a#b\nline2' }], InvalidParameters: [] };
      },
      batchGetSecretValue: async (ids) => {
        calls.secrets.push(ids);
        return {
          SecretValues: [
            { ARN: 'arn:aws:secretsmanager:us-east-1:1:secret:shop/prod/db-Ab12', Name: 'shop/prod/db', SecretString: '{"password":"p#ss","port":5432,"ssl":true}' },
            { ARN: 'arn:aws:secretsmanager:us-east-1:1:secret:shop/prod/cert-Cd34', Name: 'shop/prod/cert', SecretString: '-----BEGIN-----\nabc\n-----END-----' },
          ],
          Errors: [],
        };
      },
    },
  );

  assert.deepEqual(result.missing, []);
  assert.deepEqual(result.resolved, {
    APP_KEY: 'base64:a#b\nline2',
    DB_PASSWORD: 'p#ss',
    DB_PORT: '5432',
    DB_SSL: 'true',
    CERT: '-----BEGIN-----\nabc\n-----END-----',
  });
  assert.deepEqual(calls.ssm, [['/shop/prod/app-key']]);
  assert.deepEqual(calls.secrets, [['shop/prod/db', 'shop/prod/cert']]);
});

test('reports missing references and makes no calls without references', async () => {
  const none = await resolveSecrets({ A: 'plain' }, {
    getParameters: async () => assert.fail('no SSM call expected'),
    batchGetSecretValue: async () => assert.fail('no Secrets Manager call expected'),
  });
  assert.deepEqual(none, { resolved: {}, missing: [] });

  const result = await resolveSecrets(
    { A: 'arco-ssm:/nope', B: 'arco-secret:db#missing' },
    {
      getParameters: async () => ({ Parameters: [], InvalidParameters: ['/nope'] }),
      batchGetSecretValue: async () => ({ SecretValues: [{ ARN: 'arn:x', Name: 'db', SecretString: '{"user":"u"}' }], Errors: [] }),
    },
  );
  assert.deepEqual(result.missing, ['arco-ssm:/nope', 'arco-secret:db#missing']);
});

test('batches SSM by 10 and Secrets Manager by 20', async () => {
  const env = {};
  for (let i = 1; i <= 11; i++) env[`P${i}`] = `arco-ssm:/p/${i}`;
  for (let i = 1; i <= 21; i++) env[`S${i}`] = `arco-secret:s/${i}`;
  const ssmBatches = [];
  const secretBatches = [];
  await resolveSecrets(env, {
    getParameters: async (names) => {
      ssmBatches.push(names.length);
      return { Parameters: names.map((n) => ({ Name: n, Value: 'v' })), InvalidParameters: [] };
    },
    batchGetSecretValue: async (ids) => {
      secretBatches.push(ids.length);
      return { SecretValues: ids.map((id) => ({ ARN: `arn:${id}`, Name: id, SecretString: 'v' })), Errors: [] };
    },
  });
  assert.deepEqual(ssmBatches, [10, 1]);
  assert.deepEqual(secretBatches, [20, 1]);
});
