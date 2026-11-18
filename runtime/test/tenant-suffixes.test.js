'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const { parseSuffixList, loadSuffixes } = require('../lib/tenants/suffixes');
const { normalizeDomain, tenantName } = require('../lib/tenants/domain');

const FIXTURE = path.join(__dirname, 'fixtures', 'public_suffix_list.test.dat');
const psl = loadSuffixes(FIXTURE);

test('registrable domain follows the PSL algorithm', () => {
  const cases = [
    ['shop.customer.com', 'customer.com'],
    ['a.b.c.customer.com', 'customer.com'],
    ['customer.com', 'customer.com'],
    ['com', null],
    ['customer.co.uk', 'customer.co.uk'],
    ['shop.customer.co.uk', 'customer.co.uk'],
    ['co.uk', null],
    ['shop.empresa.com.ar', 'empresa.com.ar'],
    ['com.ar', null],
    ['a.b.ck', 'a.b.ck'],
    ['b.ck', null], // *.ck makes b.ck a suffix
    ['www.ck', 'www.ck'], // !www.ck exception: ck is the suffix
    ['x.www.ck', 'www.ck'],
    ['me.github.io', 'me.github.io'], // private section honoured
    ['x.me.github.io', 'me.github.io'],
    ['github.io', null],
    ['foo.unknown', 'foo.unknown'], // default "*" rule
    ['unknown', null],
  ];
  for (const [host, expected] of cases) assert.equal(psl.registrableDomain(host), expected, host);
});

test('comments, blanks and trailing text are ignored; empty lists are rejected', () => {
  const parsed = parseSuffixList('// comment\n\n  org  // trailing\r\n');
  assert.equal(parsed.registrableDomain('a.b.org'), 'b.org');
  assert.equal(parseSuffixList('// only comments\n'), null);
});

test('loadSuffixes returns null when the file is missing and for the bundled list that is absent', () => {
  assert.equal(loadSuffixes(path.join(os.tmpdir(), 'arco-no-such-psl-file.dat')), null);
  const empty = fs.mkdtempSync(path.join(os.tmpdir(), 'arco-psl-'));
  try {
    fs.writeFileSync(path.join(empty, 'e.dat'), '');
    assert.equal(loadSuffixes(path.join(empty, 'e.dat')), null);
  } finally {
    fs.rmSync(empty, { recursive: true, force: true });
  }
});

test('domain validation table', () => {
  const valid = [
    ['shop.customer.com', 'shop.customer.com'],
    ['SHOP.Customer.COM', 'shop.customer.com'],
    ['shop.customer.com.', 'shop.customer.com'],
    ['a-b.c1.io', 'a-b.c1.io'],
    ['xn--bcher-kva.example.com', 'xn--bcher-kva.example.com'],
  ];
  for (const [input, expected] of valid) assert.equal(normalizeDomain(input), expected, input);

  const invalid = [
    '', '.', 'localhost', 'customer', '*.customer.com', 'a..b.com', '-a.customer.com', 'a-.customer.com',
    'a_b.customer.com', 'a b.customer.com', '1.2.3.4', '::1', '[::1]', 'customer.com..', ' shop.customer.com',
    'shop.customer.com/path', 'shop.customer.com:443', `${'a'.repeat(64)}.customer.com`,
    `${'a.'.repeat(130)}com`, 'bücher.example.com', 'a.123', null, undefined, 42, {}, ['a.b'],
  ];
  for (const input of invalid) assert.equal(normalizeDomain(input), null, String(input));
  assert.equal(normalizeDomain(`${'a'.repeat(63)}.customer.com`), `${'a'.repeat(63)}.customer.com`);
});

test('tenant names are deterministic and match the CloudFront pattern', () => {
  const pattern = /^[a-zA-Z0-9][a-zA-Z0-9-.]{1,126}[a-zA-Z0-9]$/;
  assert.equal(tenantName('shop.customer.com'), 'shop.customer.com');
  const long = `${'a'.repeat(60)}.${'b'.repeat(60)}.${'c'.repeat(60)}.customer.com`;
  const name = tenantName(long);
  assert.ok(pattern.test(name) && name.length <= 128, name);
  assert.equal(tenantName(long), name);
  assert.notEqual(tenantName(`${long}x`), name);
  assert.ok(pattern.test(tenantName(`${'a'.repeat(99)}.${'b'.repeat(60)}.customer.com`)));
});
