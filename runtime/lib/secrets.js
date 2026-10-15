'use strict';
const { isReference, parseReference } = require('./references');

const chunk = (items, size) => {
  const chunks = [];
  for (let i = 0; i < items.length; i += size) chunks.push(items.slice(i, i + size));
  return chunks;
};

const stringify = (value) => {
  if (typeof value === 'string') return value;
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'number') return String(value);
  return null;
};

async function resolveSecrets(env, { getParameters, batchGetSecretValue }) {
  const references = Object.entries(env)
    .filter(([, value]) => isReference(value))
    .map(([key, value]) => ({ key, value, ref: parseReference(value) }));
  if (references.length === 0) return { resolved: {}, missing: [] };

  const ssmNames = [...new Set(references.filter((r) => r.ref.source === 'ssm').map((r) => r.ref.name))];
  const secretIds = [...new Set(references.filter((r) => r.ref.source === 'secretsmanager').map((r) => r.ref.name))];

  const parameters = new Map();
  for (const names of chunk(ssmNames, 10)) {
    const response = await getParameters(names);
    for (const parameter of response.Parameters || []) parameters.set(parameter.Name, parameter.Value);
  }

  const secrets = [];
  for (const ids of chunk(secretIds, 20)) {
    const response = await batchGetSecretValue(ids);
    secrets.push(...(response.SecretValues || []));
  }

  const resolved = {};
  const missing = [];
  for (const { key, value, ref } of references) {
    let result = null;
    if (ref.source === 'ssm') {
      result = parameters.has(ref.name) ? parameters.get(ref.name) : null;
    } else {
      const secret = secrets.find((s) => s.Name === ref.name || s.ARN === ref.name);
      if (secret && typeof secret.SecretString === 'string') {
        if (ref.jsonKey === null) {
          result = secret.SecretString;
        } else {
          try {
            const decoded = JSON.parse(secret.SecretString);
            if (decoded && Object.prototype.hasOwnProperty.call(decoded, ref.jsonKey)) result = stringify(decoded[ref.jsonKey]);
          } catch {
            result = null;
          }
        }
      }
    }
    if (result === null) missing.push(value);
    else resolved[key] = result;
  }
  return { resolved, missing };
}

module.exports = { resolveSecrets };
