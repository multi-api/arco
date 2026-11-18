'use strict';
// The only file of the tenant mediator that touches the AWS SDK. Provided by the nodejs24.x
// Lambda runtime. Errors are rethrown as-is; the core inspects error.name.

const COMMANDS = [
  'CloudFrontClient',
  'CreateDistributionTenantCommand',
  'GetDistributionTenantByDomainCommand',
  'GetDistributionTenantCommand',
  'UpdateDistributionTenantCommand',
  'DeleteDistributionTenantCommand',
  'ListDistributionTenantsCommand',
  'GetManagedCertificateDetailsCommand',
  'ListTagsForResourceCommand',
];

// Returns null when the SDK or any command is missing: the mediator answers `unavailable`.
function createCloudFrontAdapter(load = () => require('@aws-sdk/client-cloudfront')) {
  let sdk;
  try {
    sdk = load();
  } catch {
    return null;
  }
  if (!sdk || COMMANDS.some((name) => typeof sdk[name] !== 'function')) return null;

  const client = new sdk.CloudFrontClient({ region: 'us-east-1' });
  const send = (command) => client.send(command);
  const notFound = (error) => {
    if (error && error.name === 'EntityNotFound') return null;
    throw error;
  };
  const wrap = (out) => ({ tenant: out.DistributionTenant, etag: out.ETag });

  return {
    getTenantByDomain: (domain) =>
      send(new sdk.GetDistributionTenantByDomainCommand({ Domain: domain })).then(wrap, notFound),
    getTenant: (id) => send(new sdk.GetDistributionTenantCommand({ Identifier: id })).then(wrap, notFound),
    createTenant: (input) => send(new sdk.CreateDistributionTenantCommand(input)).then(wrap),
    updateTenant: (id, etag, input) =>
      send(new sdk.UpdateDistributionTenantCommand({ ...input, Id: id, IfMatch: etag })).then(wrap),
    deleteTenant: (id, etag) =>
      send(new sdk.DeleteDistributionTenantCommand({ Id: id, IfMatch: etag })).then(() => undefined),
    listTenants: ({ distributionId, marker, maxItems }) =>
      send(
        new sdk.ListDistributionTenantsCommand({
          AssociationFilter: { DistributionId: distributionId },
          ...(marker ? { Marker: marker } : {}),
          MaxItems: maxItems,
        }),
      ).then((out) => ({ items: out.DistributionTenantList ?? [], nextMarker: out.NextMarker })),
    // Responses of the tenant reads carry no tags on real CloudFront: they come from the tenant's ARN.
    tags: (arn) =>
      send(new sdk.ListTagsForResourceCommand({ Resource: arn })).then((out) =>
        out.Tags && Array.isArray(out.Tags.Items) ? out.Tags.Items : [],
      ),
    certificate: (id) =>
      send(new sdk.GetManagedCertificateDetailsCommand({ Identifier: id })).then(
        (out) => out.ManagedCertificateDetails ?? null,
        notFound,
      ),
  };
}

module.exports = { createCloudFrontAdapter };
