'use strict';
const crypto = require('node:crypto');

// bundleVariables(): ARCO_VARIABLES points at one JSON object in the code bucket holding the app's variables
// (s3://<bucket>/variables/<app>/<env>/<sha256>.json). They are loaded at cold start, before secret references are
// resolved, so arco-ssm: and arco-secret: values inside the bundle work as in the Lambda environment.
const POINTER = 'ARCO_VARIABLES';

function parsePointer(value) {
  const match = /^s3:\/\/([^/]+)\/(.+)$/.exec(value);
  if (!match) throw new Error(`${POINTER} must be s3://<bucket>/<key>, got "${value}" (deploy again with arco deploy)`);
  return { bucket: match[1], key: match[2] };
}

// The variables the bundle adds: a name the real environment already has keeps its value (Lambda's own variables,
// ARCO_* and the credentials are never replaced).
async function loadVariables(env, getObject) {
  if (!env[POINTER]) return {};
  const { bucket, key } = parsePointer(env[POINTER]);
  const body = await getObject(bucket, key);
  const expected = /([0-9a-f]{64})\.json$/.exec(key);
  const actual = crypto.createHash('sha256').update(body).digest('hex');
  if (!expected || expected[1] !== actual) {
    throw new Error(`The variable bundle s3://${bucket}/${key} does not match its checksum (got ${actual})`);
  }
  let decoded;
  try {
    decoded = JSON.parse(body.toString('utf8'));
  } catch {
    decoded = null;
  }
  const variables = decoded && decoded.schema === 1 && decoded.variables && typeof decoded.variables === 'object' ? decoded.variables : null;
  if (variables === null || Array.isArray(variables) || Object.values(variables).some((value) => typeof value !== 'string')) {
    throw new Error(`s3://${bucket}/${key} is not a variable bundle (schema 1, string values)`);
  }
  const loaded = {};
  for (const [name, value] of Object.entries(variables)) {
    if (!Object.prototype.hasOwnProperty.call(env, name)) loaded[name] = value;
  }
  return loaded;
}

module.exports = { POINTER, loadVariables, parsePointer };
