'use strict';
const https = require('node:https');
const { sign } = require('./sigv4');
const { loadCredentials } = require('./credentials');

function httpsPost(host, headers, body) {
  return new Promise((resolve, reject) => {
    const request = https.request({ host, method: 'POST', path: '/', headers }, (response) => {
      let data = '';
      response.setEncoding('utf8');
      response.on('data', (chunk) => { data += chunk; });
      response.on('end', () => resolve({ status: response.statusCode, body: data }));
    });
    request.on('error', reject);
    request.setTimeout(5000, () => request.destroy(new Error(`Timed out calling ${host}`)));
    request.end(body);
  });
}

async function callJson({ service, target, region, body, credentials, post = httpsPost }) {
  const host = `${service}.${region}.amazonaws.com`;
  const payload = JSON.stringify(body);
  const headers = sign(
    { method: 'POST', host, path: '/', query: '', headers: { 'content-type': 'application/x-amz-json-1.1', 'x-amz-target': target }, body: payload },
    credentials,
    region,
    service,
  );
  headers['content-length'] = Buffer.byteLength(payload);
  const response = await post(host, headers, payload);
  if (response.status < 200 || response.status >= 300) {
    throw new Error(`${target} failed with HTTP ${response.status}: ${response.body}`);
  }
  return JSON.parse(response.body);
}

function createFetchers(region, deps = {}) {
  let credentialsPromise;
  const credentials = () => (credentialsPromise ??= loadCredentials(deps.env || process.env, deps.fetch || globalThis.fetch));
  return {
    getParameters: async (names) => callJson({
      service: 'ssm', target: 'AmazonSSM.GetParameters', region,
      body: { Names: names, WithDecryption: true }, credentials: await credentials(), post: deps.post,
    }),
    batchGetSecretValue: async (ids) => callJson({
      service: 'secretsmanager', target: 'secretsmanager.BatchGetSecretValue', region,
      body: { SecretIdList: ids }, credentials: await credentials(), post: deps.post,
    }),
  };
}

module.exports = { callJson, createFetchers, httpsPost };
