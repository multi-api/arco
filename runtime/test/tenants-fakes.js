'use strict';
// Shared fakes for the tenant mediator tests (not a test file: the runner globs *.test.js).

const CONFIG = {
  distributionId: 'DIST1',
  connectionGroupId: 'CG1',
  target: 'domains.shop.example.net',
  endpoint: 'd111.cloudfront.net',
  app: 'shop',
  environment: 'production',
  reserved: ['shop.example.com'],
};

const ownTags = (app = 'shop', environment = 'production') => ({
  Items: [{ Key: 'arco:app', Value: app }, { Key: 'arco:environment', Value: environment }],
});

function awsError(name, message = name) {
  const error = new Error(message);
  error.name = name;
  return error;
}

function tenantFor(domain, overrides = {}) {
  return {
    Id: `dt_${domain}`,
    Arn: `arn:aws:cloudfront::111111111111:distribution-tenant/dt_${domain}`,
    Name: domain,
    DistributionId: 'DIST1',
    ConnectionGroupId: 'CG1',
    Enabled: true,
    Domains: [{ Domain: domain, Status: 'inactive' }],
    Tags: ownTags(),
    ...overrides,
  };
}

// In-memory CloudFront adapter. `fail` maps a method name to an error to throw (once, or every
// time with {always: true}); `certs` maps tenant ids to managed certificate details.
function fakeCloudFront({ tenants = [], pageSize = 100 } = {}) {
  const store = new Map();
  let etagCounter = 1;
  const state = {
    calls: [],
    fail: {},
    certs: {},
    etags: new Map(),
    omitEtag: new Set(),
    pages: null,
    markers: [],
    summaries: null,
  };
  const put = (tenant) => {
    store.set(tenant.Id, tenant);
    state.etags.set(tenant.Id, `E${etagCounter++}`);
  };
  tenants.forEach(put);
  const record = (method, ...args) => {
    state.calls.push({ method, args });
    const failure = state.fail[method];
    if (failure) {
      if (!failure.always) delete state.fail[method];
      throw failure.error;
    }
  };
  // Real CloudFront: GetDistributionTenantByDomain only finds ACTIVE domains.
  const byDomain = (domain) => [...store.values()].find((t) => (t.Domains ?? []).some((d) => d.Domain === domain && d.Status === 'active'));
  const byIdOrName = (key) => store.get(key) ?? [...store.values()].find((t) => t.Name === key);
  // Real CloudFront: the tenant reads carry NO tags; they are served only through tags(arn).
  const view = ({ Tags, ...rest }) => structuredClone(rest);
  const etagOf = (id) => (state.omitEtag.has(id) ? undefined : state.etags.get(id));

  const adapter = {
    async getTenantByDomain(domain) {
      record('getTenantByDomain', domain);
      const tenant = byDomain(domain);
      return tenant ? { tenant: view(tenant), etag: etagOf(tenant.Id) } : null;
    },
    async getTenant(id) {
      record('getTenant', id);
      const tenant = byIdOrName(id);
      return tenant ? { tenant: view(tenant), etag: etagOf(tenant.Id) } : null;
    },
    async createTenant(input) {
      record('createTenant', input);
      const tenant = {
        Id: `dt_${input.Name}`,
        Arn: `arn:aws:cloudfront::111111111111:distribution-tenant/dt_${input.Name}`,
        Name: input.Name,
        DistributionId: input.DistributionId,
        ConnectionGroupId: input.ConnectionGroupId,
        Enabled: input.Enabled,
        Domains: input.Domains.map((d) => ({ Domain: d.Domain, Status: 'inactive' })),
        Tags: input.Tags,
      };
      put(tenant);
      return { tenant, etag: state.etags.get(tenant.Id) };
    },
    async updateTenant(id, etag, input) {
      record('updateTenant', id, etag, input);
      if (etag !== state.etags.get(id)) throw awsError('PreconditionFailed');
      const tenant = store.get(id);
      Object.assign(tenant, { Enabled: input.Enabled ?? tenant.Enabled });
      if (input.Customizations) tenant.Customizations = input.Customizations;
      state.etags.set(id, `E${etagCounter++}`);
      return { tenant, etag: etagOf(id) };
    },
    async deleteTenant(id, etag) {
      record('deleteTenant', id, etag);
      if (etag !== state.etags.get(id)) throw awsError('PreconditionFailed');
      if (store.get(id).Enabled !== false) throw awsError('ResourceNotDisabled');
      store.delete(id);
    },
    async listTenants({ distributionId, marker, maxItems }) {
      record('listTenants', { distributionId, marker, maxItems });
      state.markers.push(marker);
      if (state.pages) return state.pages[marker ?? ''] ?? { items: [], nextMarker: undefined };
      const all = state.summaries ?? [...store.values()];
      const start = marker ? Number(marker) : 0;
      const items = all.slice(start, start + pageSize).map(({ Tags, ...summary }) => summary);
      const end = start + pageSize;
      return { items, nextMarker: end < all.length ? String(end) : undefined };
    },
    // A tenant stored with `Tags: undefined` models tags that cannot be read: the call fails.
    async tags(arn) {
      record('tags', arn);
      const tenant = [...store.values()].find((t) => t.Arn === arn);
      if (!tenant) throw awsError('EntityNotFound');
      if (!tenant.Tags) throw awsError('AccessDenied');
      return Array.isArray(tenant.Tags.Items) ? structuredClone(tenant.Tags.Items) : [];
    },
    async certificate(id) {
      record('certificate', id);
      return state.certs[id] ?? null;
    },
  };
  return { adapter, store, state, put };
}

const okDns = { check: async () => true };
const failDns = { check: async () => false };

module.exports = { CONFIG, ownTags, awsError, tenantFor, fakeCloudFront, okDns, failDns };
