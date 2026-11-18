'use strict';
const { createMediator } = require('./lib/tenants/mediator');
const { createDnsChecker } = require('./lib/tenants/dns');
const { createCloudFrontAdapter } = require('./lib/tenants/cloudfront');
const { loadSuffixes } = require('./lib/tenants/suffixes');

function readConfig(env) {
  const get = (name) => (typeof env[name] === 'string' ? env[name].trim() : '');
  const config = {
    distributionId: get('ARCO_TENANT_DISTRIBUTION_ID'),
    connectionGroupId: get('ARCO_TENANT_CONNECTION_GROUP_ID'),
    target: get('ARCO_TENANT_TARGET'),
    endpoint: get('ARCO_TENANT_ENDPOINT'),
    app: get('ARCO_APP'),
    environment: get('ARCO_ENVIRONMENT'),
    reserved: get('ARCO_TENANT_RESERVED').split(',').map((h) => h.trim()).filter((h) => h !== ''),
  };
  const required = ['distributionId', 'connectionGroupId', 'target', 'endpoint', 'app', 'environment'];
  return required.every((key) => config[key] !== '') ? config : null;
}

// deps: { config, cloudfront, dns, suffixes, log } (all injectable for tests).
function createHandler(deps) {
  const mediator = createMediator(deps);
  const log = deps.log ?? ((line) => console.log(line));
  return async function handler(event) {
    const trace = {};
    const response = await mediator.handle(event, trace);
    try {
      const safe = event && typeof event === 'object' ? event : {};
      log(
        JSON.stringify({
          operation: typeof safe.operation === 'string' ? safe.operation.slice(0, 16) : null,
          domain: typeof safe.domain === 'string' ? safe.domain.slice(0, 253) : null,
          ok: response.ok,
          code: response.ok ? null : response.error.code,
          ...(typeof trace.detail === 'string' ? { detail: trace.detail.slice(0, 200) } : {}),
        }),
      );
    } catch {
      // logging must never change the response
    }
    return response;
  };
}

let real;
async function handler(event) {
  real ??= createHandler({
    config: readConfig(process.env),
    cloudfront: createCloudFrontAdapter(),
    dns: createDnsChecker(),
    suffixes: loadSuffixes(),
  });
  return real(event);
}

module.exports = { handler, createHandler, readConfig };
