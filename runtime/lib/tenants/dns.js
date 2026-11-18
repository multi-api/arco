'use strict';
const { normalizeHost } = require('./domain');

const MAX_HOPS = 8;
const NO_RECORD = new Set(['ENODATA', 'ENOTFOUND']);

class Unverifiable extends Error {}

async function lookup(resolve, name) {
  try {
    const values = await resolve(name);
    return Array.isArray(values) ? values : [];
  } catch (error) {
    if (error && NO_RECORD.has(error.code)) return [];
    throw new Unverifiable(error && error.code ? error.code : 'resolver error');
  }
}

async function addresses(resolver, name) {
  const [v4, v6] = [await lookup((n) => resolver.resolve4(n), name), await lookup((n) => resolver.resolve6(n), name)];
  return [...v4, ...v6].map((a) => String(a).toLowerCase());
}

function defaultResolver() {
  const { Resolver } = require('node:dns').promises;
  const resolver = new Resolver({ timeout: 3000, tries: 2 });
  return {
    resolveCname: (n) => resolver.resolveCname(n),
    resolve4: (n) => resolver.resolve4(n),
    resolve6: (n) => resolver.resolve6(n),
  };
}

// Never accepts by default: only a positive match returns true.
function createDnsChecker(resolver = defaultResolver()) {
  async function check({ domain, target, endpoint }) {
    const accepted = new Set([normalizeHost(target), normalizeHost(endpoint)].filter((h) => h !== ''));
    try {
      let current = normalizeHost(domain);
      const seen = new Set([current]);
      for (let hop = 0; hop < MAX_HOPS; hop += 1) {
        const cnames = await lookup((n) => resolver.resolveCname(n), current);
        if (cnames.length === 0) {
          if (hop > 0) return false;
          return await flattened();
        }
        const next = normalizeHost(cnames[0]);
        if (accepted.has(next)) return true;
        if (next === '' || seen.has(next)) return false;
        seen.add(next);
        current = next;
      }
      return false;
    } catch (error) {
      if (error instanceof Unverifiable) return false;
      throw error;
    }

    async function flattened() {
      const own = await addresses(resolver, normalizeHost(domain));
      if (own.length === 0) return false;
      const routed = new Set(await addresses(resolver, normalizeHost(endpoint)));
      return own.every((address) => routed.has(address));
    }
  }
  return { check };
}

module.exports = { createDnsChecker };
