'use strict';
const crypto = require('node:crypto');

const hmac = (key, data) => crypto.createHmac('sha256', key).update(data, 'utf8').digest();
const sha256 = (data) => crypto.createHash('sha256').update(data, 'utf8').digest('hex');

function sign(request, credentials, region, service, now = new Date()) {
  const amzDate = now.toISOString().replace(/[:-]|\.\d{3}/g, '');
  const dateStamp = amzDate.slice(0, 8);

  const headers = {};
  for (const [name, value] of Object.entries(request.headers || {})) {
    headers[name.toLowerCase()] = String(value).trim().replace(/\s+/g, ' ');
  }
  headers.host = request.host;
  headers['x-amz-date'] = amzDate;
  if (credentials.sessionToken) headers['x-amz-security-token'] = credentials.sessionToken;

  const names = Object.keys(headers).sort();
  const canonicalHeaders = names.map((name) => `${name}:${headers[name]}\n`).join('');
  const signedHeaders = names.join(';');
  const canonicalQuery = (request.query || '').split('&').filter(Boolean).sort().join('&');
  const canonicalRequest = [
    request.method,
    request.path || '/',
    canonicalQuery,
    canonicalHeaders,
    signedHeaders,
    sha256(request.body || ''),
  ].join('\n');

  const scope = `${dateStamp}/${region}/${service}/aws4_request`;
  const stringToSign = ['AWS4-HMAC-SHA256', amzDate, scope, sha256(canonicalRequest)].join('\n');
  const signingKey = hmac(hmac(hmac(hmac(`AWS4${credentials.secretAccessKey}`, dateStamp), region), service), 'aws4_request');
  const signature = crypto.createHmac('sha256', signingKey).update(stringToSign, 'utf8').digest('hex');

  return {
    ...headers,
    authorization: `AWS4-HMAC-SHA256 Credential=${credentials.accessKeyId}/${scope}, SignedHeaders=${signedHeaders}, Signature=${signature}`,
  };
}

module.exports = { sign };
