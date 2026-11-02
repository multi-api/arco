'use strict';
const crypto = require('node:crypto');
const http = require('node:http');
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

function httpGet(url, headers) {
  return new Promise((resolve, reject) => {
    const request = (url.startsWith('http:') ? http : https).get(url, { headers }, (response) => {
      const chunks = [];
      response.on('data', (chunk) => { chunks.push(chunk); });
      response.on('end', () => resolve({ status: response.statusCode, body: Buffer.concat(chunks) }));
    });
    request.on('error', reject);
    request.setTimeout(5000, () => request.destroy(new Error(`Timed out reading ${url}`)));
  });
}

const EMPTY_SHA256 = crypto.createHash('sha256').update('').digest('hex');

// S3 GetObject: virtual-hosted on AWS, path style on ARCO_S3_ENDPOINT (an emulator), like runtime/bootstrap.
async function getObject({ bucket, key, region, credentials, endpoint, get = httpGet }) {
  const path = `/${key.split('/').map(encodeURIComponent).join('/')}`;
  const base = endpoint ? new URL(endpoint) : null;
  const host = base ? base.host : `${bucket}.s3.${region}.amazonaws.com`;
  const signedPath = base ? `/${encodeURIComponent(bucket)}${path}` : path;
  const headers = sign({ method: 'GET', host, path: signedPath, query: '', headers: { 'x-amz-content-sha256': EMPTY_SHA256 }, body: '' }, credentials, region, 's3');
  const response = await get(`${base ? base.protocol : 'https:'}//${host}${signedPath}`, headers);
  if (response.status < 200 || response.status >= 300) {
    throw new Error(`Cannot read s3://${bucket}/${key}: HTTP ${response.status}: ${response.body.toString('utf8').slice(0, 300)} (check s3:GetObject on variables/* in the execution role)`);
  }
  return response.body;
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
  const env = deps.env || process.env;
  return {
    getObject: async (bucket, key) => getObject({
      bucket, key, region, credentials: await credentials(), endpoint: env.ARCO_S3_ENDPOINT, get: deps.get,
    }),
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

module.exports = { callJson, createFetchers, getObject, httpGet, httpsPost };
