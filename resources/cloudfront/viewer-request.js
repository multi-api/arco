// Arco's viewer-request CloudFront function (DomainStack, runtime cloudfront-js-2.0). It runs before every request
// reaches the Web Function endpoint.
function handler(event) {
  var request = event.request;

  // Pass the viewer's host to the app; a value the viewer sent is never trusted.
  if (request.headers.host) {
    request.headers['x-forwarded-host'] = { value: request.headers.host.value };
  } else {
    delete request.headers['x-forwarded-host'];
  }

  // The origin access control signs a literal '+' in the query string differently from how the Web Function endpoint
  // checks it, so such requests answer 403 before reaching the app. '+' in a query string means a space
  // (application/x-www-form-urlencoded), so send it as %20, which both sides sign alike. A literal plus is %2B and
  // is left alone, as is every query string without '+'.
  var query = request.rawQueryString();
  if (query && query.indexOf('+') !== -1) {
    request.querystring = query.replace(/\+/g, '%20');
  }

  return request;
}
