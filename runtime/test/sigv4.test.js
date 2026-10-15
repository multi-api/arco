'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { sign } = require('../lib/sigv4');

// Example from the AWS docs "Create a signed AWS API request" (IAM ListUsers).
test('matches the documented SigV4 example', () => {
  const headers = sign(
    {
      method: 'GET',
      host: 'iam.amazonaws.com',
      path: '/',
      query: 'Action=ListUsers&Version=2010-05-08',
      headers: { 'content-type': 'application/x-www-form-urlencoded; charset=utf-8' },
      body: '',
    },
    { accessKeyId: 'AKIDEXAMPLE', secretAccessKey: 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY' },
    'us-east-1',
    'iam',
    new Date('2015-08-30T12:36:00Z'),
  );

  assert.equal(headers['x-amz-date'], '20150830T123600Z');
  assert.equal(
    headers.authorization,
    'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/iam/aws4_request, '
      + 'SignedHeaders=content-type;host;x-amz-date, '
      + 'Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7',
  );
});

test('adds and signs the session token', () => {
  const headers = sign(
    { method: 'POST', host: 'ssm.us-east-1.amazonaws.com', path: '/', query: '', headers: {}, body: '{}' },
    { accessKeyId: 'AK', secretAccessKey: 'SK', sessionToken: 'TOKEN' },
    'us-east-1',
    'ssm',
  );
  assert.equal(headers['x-amz-security-token'], 'TOKEN');
  assert.match(headers.authorization, /SignedHeaders=host;x-amz-date;x-amz-security-token,/);
});
