'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadSuffixes } = require('../lib/tenants/suffixes');

// The list the mediator ships (publicsuffix.org, MPL 2.0). Without it every add is refused.
test('the shipped public suffix list loads and tells root domains from subdomains', () => {
  const suffixes = loadSuffixes();
  assert.ok(suffixes, 'lib/tenants/public_suffix_list.dat is part of the package');
  assert.equal(suffixes.registrableDomain('tienda.cliente.cl'), 'cliente.cl');
  assert.equal(suffixes.registrableDomain('cliente.cl'), 'cliente.cl');
  assert.equal(suffixes.registrableDomain('shop.customer.co.uk'), 'customer.co.uk');
  assert.equal(suffixes.registrableDomain('a.b.customer.com.ar'), 'customer.com.ar');
  assert.equal(suffixes.registrableDomain('shop.github.io'), 'shop.github.io', 'private suffixes count');
  assert.equal(suffixes.registrableDomain('co.uk'), null);
});
