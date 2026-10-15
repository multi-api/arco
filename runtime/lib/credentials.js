'use strict';
const fs = require('node:fs');

async function loadCredentials(env = process.env, fetchImpl = globalThis.fetch) {
  if (env.AWS_ACCESS_KEY_ID && env.AWS_SECRET_ACCESS_KEY) {
    return { accessKeyId: env.AWS_ACCESS_KEY_ID, secretAccessKey: env.AWS_SECRET_ACCESS_KEY, sessionToken: env.AWS_SESSION_TOKEN };
  }
  if (env.AWS_CONTAINER_CREDENTIALS_FULL_URI) {
    const headers = {};
    if (env.AWS_CONTAINER_AUTHORIZATION_TOKEN) {
      headers.Authorization = env.AWS_CONTAINER_AUTHORIZATION_TOKEN;
    } else if (env.AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE) {
      headers.Authorization = fs.readFileSync(env.AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE, 'utf8').trim();
    }
    const response = await fetchImpl(env.AWS_CONTAINER_CREDENTIALS_FULL_URI, { headers });
    if (!response.ok) throw new Error(`The credentials endpoint returned HTTP ${response.status}`);
    const body = await response.json();
    return { accessKeyId: body.AccessKeyId, secretAccessKey: body.SecretAccessKey, sessionToken: body.Token };
  }
  throw new Error('No AWS credentials found in the environment (AWS_ACCESS_KEY_ID or AWS_CONTAINER_CREDENTIALS_FULL_URI).');
}

module.exports = { loadCredentials };
