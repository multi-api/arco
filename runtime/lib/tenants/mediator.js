'use strict';
const { normalizeDomain, normalizeHost, tenantName } = require('./domain');

const OPERATIONS = new Set(['add', 'status', 'list', 'remove']);
const FAILED_CERTIFICATES = new Set(['failed', 'validation-timed-out', 'revoked', 'expired', 'inactive']);
const DUPLICATE = new Set(['CNAMEAlreadyExists', 'EntityAlreadyExists']);
const CONFLICT = new Set(['InvalidIfMatchVersion', 'PreconditionFailed']);
const MAX_RAW_STRING = 1024;

class Fail extends Error {
  constructor(code, message, expected) {
    super(message);
    this.code = code;
    this.expected = expected;
  }
}

const unverified = (what = 'ownership') => new Fail('ownership_unverified', `The ${what} of the tenant could not be verified.`);
const errorName = (error) => (error && typeof error === 'object' && typeof error.name === 'string' ? error.name : '');

function encodeCursor(marker) {
  return Buffer.from(JSON.stringify({ m: marker }), 'utf8').toString('base64url');
}

function decodeCursor(cursor) {
  const bad = new Fail('invalid_request', 'The cursor is not valid.');
  if (typeof cursor !== 'string' || cursor === '' || cursor.length > 2048) throw bad;
  let value;
  try {
    value = JSON.parse(Buffer.from(cursor, 'base64url').toString('utf8'));
  } catch {
    throw bad;
  }
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw bad;
  if (Object.keys(value).length !== 1 || typeof value.m !== 'string' || value.m === '') throw bad;
  if (encodeCursor(value.m) !== cursor) throw bad;
  return value.m;
}

function createMediator({ config, cloudfront, dns, suffixes }) {
  function ownership(tenant) {
    if (!tenant || typeof tenant !== 'object') return 'unverified';
    const tags = tenant.Tags && Array.isArray(tenant.Tags.Items) ? tenant.Tags.Items : null;
    const tag = (key) => {
      const found = tags ? tags.find((t) => t && t.Key === key) : undefined;
      if (!found) return tags ? null : undefined; // null: tags were read and the key is absent
      return typeof found.Value === 'string' ? found.Value : undefined;
    };
    const pairs = [
      [tenant.DistributionId, config.distributionId],
      [tenant.ConnectionGroupId, config.connectionGroupId],
      [tag('arco:app'), config.app],
      [tag('arco:environment'), config.environment],
    ];
    // Readable and different (or readable tags without ours): verifiably not this environment's.
    if (pairs.some(([actual, wanted]) => (typeof actual === 'string' && actual !== wanted) || actual === null)) return 'foreign';
    if (pairs.some(([actual]) => typeof actual !== 'string')) return 'unverified';
    return 'owned';
  }

  // opts.activate (status and idempotent add only; never list): apply an issued certificate. opts.etag, opts.trace.
  async function state(tenant, domain, opts = {}) {
    const entry = Array.isArray(tenant.Domains)
      ? tenant.Domains.find((d) => d && normalizeHost(d.Domain) === domain)
      : undefined;
    if (!entry) throw unverified('domain');
    if (tenant.Enabled === false) return { domain, state: 'pending', reason: 'removing' };
    if (tenant.Enabled === true && entry.Status === 'active') return { domain, state: 'active', reason: null };
    if (typeof tenant.Id !== 'string' || tenant.Id === '') throw unverified('state');
    let certificate;
    try {
      certificate = await cloudfront.certificate(tenant.Id);
    } catch (error) {
      if (errorName(error) === 'EntityNotFound') certificate = null;
      else throw unverified('state');
    }
    const status = certificate && certificate.CertificateStatus;
    if (FAILED_CERTIFICATES.has(status)) return { domain, state: 'failed', reason: `certificate ${status}` };
    const arn = certificate && certificate.CertificateArn;
    if (tenant.Enabled === true && status === 'issued' && typeof arn === 'string' && arn !== '') {
      const current = tenant.Customizations && tenant.Customizations.Certificate && tenant.Customizations.Certificate.Arn;
      if (current === arn) return { domain, state: 'pending', reason: 'activating' };
      if (opts.activate && opts.etag) {
        try {
          await cloudfront.updateTenant(tenant.Id, opts.etag, {
            DistributionId: config.distributionId,
            ConnectionGroupId: config.connectionGroupId,
            Domains: tenant.Domains.map((d) => ({ Domain: d.Domain })),
            Enabled: true,
            Customizations: { ...(tenant.Customizations ?? {}), Certificate: { Arn: arn } },
          });
          return { domain, state: 'pending', reason: 'activating' };
        } catch (error) {
          const name = errorName(error) || 'Error';
          if (opts.trace) opts.trace.detail = `activation failed: ${name}`;
          return { domain, state: 'pending', reason: `activation failed: ${name}` };
        }
      }
    }
    return { domain, state: 'pending', reason: null };
  }

  function requireDomain(raw) {
    const domain = normalizeDomain(raw);
    if (domain === null) throw new Fail('invalid_domain', 'The domain is not a valid hostname.');
    return domain;
  }

  // The single place that completes a tenant read before ownership is judged. Real CloudFront returns
  // no tags from the tenant reads: they are read with the tenant's own ARN (never a guessed one). Without
  // readable tags the tenant stays without them and ownership() answers unverified (fail closed).
  async function withTags(tenant, trace) {
    if (!tenant || typeof tenant !== 'object') return tenant;
    if (tenant.Tags && Array.isArray(tenant.Tags.Items)) return tenant;
    const arn = tenant.Arn;
    const id = tenant.Id;
    if (typeof arn !== 'string' || arn === '' || typeof id !== 'string' || id === '' || !arn.endsWith(`distribution-tenant/${id}`)) {
      return tenant;
    }
    try {
      const items = await cloudfront.tags(arn);
      if (!Array.isArray(items)) return tenant;
      return { ...tenant, Tags: { Items: items } };
    } catch (error) {
      if (trace) trace.detail = `tags unreadable: ${errorName(error) || 'Error'}`;
      return tenant;
    }
  }

  // Looks the domain up and classifies the tenant that holds it: {found, owner, tenant, etag}.
  // GetDistributionTenantByDomain only finds ACTIVE domains, so a miss falls back to the tenant name
  // `add` creates (deterministic from the domain). A name hit counts only when it lists the domain,
  // unless `lenient` (after a duplicate error from create, where the name itself is what collided).
  async function find(domain, lenient = false, trace) {
    const hit = await cloudfront.getTenantByDomain(domain);
    if (hit && hit.tenant) {
      const tenant = await withTags(hit.tenant, trace);
      return { found: true, owner: ownership(tenant), tenant, etag: hit.etag };
    }
    const named = await cloudfront.getTenant(tenantName(domain));
    if (!named || !named.tenant) return { found: false };
    const lists = Array.isArray(named.tenant.Domains) && named.tenant.Domains.some((d) => d && normalizeHost(d.Domain) === domain);
    if (!lists && !lenient) return { found: false };
    const tenant = await withTags(named.tenant, trace);
    return { found: true, owner: ownership(tenant), tenant, etag: named.etag };
  }

  async function existing(domain, found, trace) {
    if (found.owner === 'owned') return state(found.tenant, domain, { activate: true, etag: found.etag, trace });
    if (found.owner === 'foreign') throw new Fail('domain_taken', 'The domain is in use by another application or environment.');
    throw unverified();
  }

  async function add(raw, trace) {
    const domain = requireDomain(raw);
    if (!suffixes) throw new Fail('unavailable', 'The public suffix list is not available.');
    const reserved = new Set([config.target, ...config.reserved].map(normalizeHost));
    if (reserved.has(domain)) throw new Fail('reserved_domain', 'The domain is reserved for this environment.');
    const registrable = suffixes.registrableDomain(domain);
    if (registrable === null || registrable === domain) {
      throw new Fail('apex_not_supported', 'Only subdomains are supported, not root domains or public suffixes.');
    }

    const found = await find(domain, false, trace);
    if (found.found) return existing(domain, found, trace);

    const correct = await dns.check({ domain, target: config.target, endpoint: config.endpoint });
    if (!correct) {
      throw new Fail('dns_incorrect', `The DNS of ${domain} does not lead to ${config.target}.`, config.target);
    }

    try {
      await cloudfront.createTenant({
        DistributionId: config.distributionId,
        ConnectionGroupId: config.connectionGroupId,
        Name: tenantName(domain),
        Domains: [{ Domain: domain }],
        Enabled: true,
        Tags: {
          Items: [
            { Key: 'arco:app', Value: config.app },
            { Key: 'arco:environment', Value: config.environment },
          ],
        },
        ManagedCertificateRequest: { ValidationTokenHost: 'cloudfront', PrimaryDomainName: domain },
      });
    } catch (error) {
      const name = errorName(error);
      if (name === 'EntityLimitExceeded') throw new Fail('quota', 'CloudFront refused the tenant: a limit was exceeded.');
      if (!DUPLICATE.has(name)) throw error;
      const again = await find(domain, true, trace);
      if (!again.found) throw new Fail('domain_taken', 'CloudFront reports the domain as already in use.');
      return existing(domain, again, trace);
    }
    return { domain, state: 'pending', reason: null };
  }

  async function status(raw, trace) {
    const domain = requireDomain(raw);
    const found = await find(domain, false, trace);
    if (!found.found || found.owner === 'foreign') throw new Fail('not_found', 'This environment has no tenant for the domain.');
    if (found.owner !== 'owned') throw unverified();
    return state(found.tenant, domain, { activate: true, etag: found.etag, trace });
  }

  async function list(cursor, limit, trace) {
    const max = limit === undefined || limit === null ? 50 : limit;
    if (!Number.isInteger(max) || max < 1 || max > 100) throw new Fail('invalid_request', 'The limit must be an integer from 1 to 100.');
    const marker = cursor === undefined || cursor === null ? undefined : decodeCursor(cursor);

    const page = await cloudfront.listTenants({ distributionId: config.distributionId, marker, maxItems: max });
    const domains = [];
    for (const summary of page.items ?? []) {
      if (!summary || typeof summary !== 'object') throw unverified();
      if (
        (typeof summary.ConnectionGroupId === 'string' && summary.ConnectionGroupId !== config.connectionGroupId) ||
        (typeof summary.DistributionId === 'string' && summary.DistributionId !== config.distributionId)
      ) continue;
      if (typeof summary.Id !== 'string' || summary.Id === '') throw unverified();
      let full;
      try {
        full = await cloudfront.getTenant(summary.Id);
      } catch {
        throw unverified();
      }
      if (!full || !full.tenant) continue; // deleted meanwhile
      const tenant = await withTags(full.tenant, trace);
      const owner = ownership(tenant);
      if (owner === 'foreign') continue;
      if (owner !== 'owned') throw unverified();
      const entries = Array.isArray(tenant.Domains) ? tenant.Domains : null;
      if (!entries) throw unverified('domain');
      for (const entry of entries) {
        const name = normalizeHost(entry && entry.Domain);
        if (name === '') throw unverified('domain');
        domains.push(await state(tenant, name));
      }
    }
    const next = page.nextMarker;
    if (typeof next === 'string' && next !== '') {
      if (next === marker) throw new Fail('failed', 'CloudFront returned the same page marker again.');
      return { domains, cursor: encodeCursor(next) };
    }
    return { domains, cursor: null };
  }

  async function remove(raw, trace) {
    const domain = requireDomain(raw);
    const found = await find(domain, false, trace);
    if (!found.found) return { removed: true };
    if (found.owner === 'foreign') throw new Fail('not_found', 'This environment has no tenant for the domain.');
    if (found.owner !== 'owned') throw unverified();
    const { tenant } = found;
    if (typeof tenant.Id !== 'string' || tenant.Id === '') throw unverified();
    const conflict = () => new Fail('conflict', 'The tenant changed during the operation; try again.');
    let etag = found.etag;

    // Arco only creates single-domain tenants: never delete one that holds anything else.
    if (!Array.isArray(tenant.Domains) || tenant.Domains.length !== 1 || normalizeHost(tenant.Domains[0] && tenant.Domains[0].Domain) !== domain) {
      throw unverified('domain');
    }

    if (tenant.Enabled !== false) {
      if (!etag) throw conflict();
      let updated;
      try {
        updated = await cloudfront.updateTenant(tenant.Id, etag, {
          Enabled: false,
          DistributionId: config.distributionId,
          ConnectionGroupId: config.connectionGroupId,
          Domains: tenant.Domains.map((d) => ({ Domain: d.Domain })),
        });
      } catch (error) {
        const name = errorName(error);
        if (name === 'EntityNotFound') return { removed: true };
        if (CONFLICT.has(name)) throw conflict();
        throw error;
      }
      etag = updated && updated.etag;
      if (!etag) {
        const again = await cloudfront.getTenant(tenant.Id);
        if (!again || !again.tenant) return { removed: true };
        if (ownership(await withTags(again.tenant, trace)) !== 'owned') throw unverified();
        etag = again.etag;
      }
      if (!etag) throw conflict();
    }

    if (!etag) throw conflict();
    try {
      await cloudfront.deleteTenant(tenant.Id, etag);
    } catch (error) {
      const name = errorName(error);
      if (name === 'EntityNotFound') return { removed: true };
      if (name === 'ResourceNotDisabled' || name === 'PreconditionFailed') {
        throw new Fail('removal_pending', 'The tenant is disabled but CloudFront does not allow deleting it yet; try again later.');
      }
      if (name === 'InvalidIfMatchVersion') throw conflict();
      throw error;
    }
    return { removed: true };
  }

  function parse(event) {
    const bad = (message) => new Fail('invalid_request', message);
    if (!event || typeof event !== 'object' || Array.isArray(event)) throw bad('The request must be a JSON object.');
    const { operation } = event;
    if (typeof operation !== 'string' || !OPERATIONS.has(operation)) throw bad('Unknown operation.');
    if (operation === 'list') return { operation, cursor: event.cursor, limit: event.limit };
    if (typeof event.domain !== 'string' || event.domain.length > MAX_RAW_STRING) throw bad('The domain must be a string.');
    return { operation, domain: event.domain };
  }

  // Never throws: every outcome is a response object.
  async function handle(event, trace = {}) {
    try {
      if (!config || !cloudfront) throw new Fail('unavailable', 'The tenant domains mediator is not configured.');
      const request = parse(event);
      let result;
      if (request.operation === 'add') result = await add(request.domain, trace);
      else if (request.operation === 'status') result = await status(request.domain, trace);
      else if (request.operation === 'remove') result = await remove(request.domain, trace);
      else result = await list(request.cursor, request.limit, trace);
      return { ok: true, result };
    } catch (error) {
      if (error instanceof Fail) {
        const body = { code: error.code, message: error.message };
        if (error.expected !== undefined) body.expected = error.expected;
        return { ok: false, error: body };
      }
      const name = errorName(error) || 'Error';
      const message = error && typeof error.message === 'string' ? error.message : String(error);
      return { ok: false, error: { code: 'failed', message: `${name}: ${message}` } };
    }
  }

  return { handle };
}

module.exports = { createMediator };
