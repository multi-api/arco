'use strict';
// Variables and secrets, shared by the web runtime (index.js) and the classic-Lambda worker (worker.js).
const { resolveSecrets } = require('./secrets');
const { POINTER, loadVariables } = require('./variables');
const { createFetchers } = require('./aws');

async function loadEnvironment(log) {
  const region = process.env.AWS_REGION || process.env.AWS_DEFAULT_REGION;
  const fetchers = createFetchers(region);
  if (process.env[POINTER]) {
    // bundleVariables(): before the secrets, so references inside the bundle are resolved too.
    const loaded = await loadVariables(process.env, fetchers.getObject);
    Object.assign(process.env, loaded);
    log('variables', { loaded: Object.keys(loaded).length });
  }
  const { resolved, missing } = await resolveSecrets(process.env, fetchers);
  if (missing.length > 0) {
    log('secrets_missing', { missing });
    process.exit(1);
  }
  Object.assign(process.env, resolved);
  log('secrets', { resolved: Object.keys(resolved).length });
}

module.exports = { loadEnvironment };
