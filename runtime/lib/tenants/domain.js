'use strict';
const crypto = require('node:crypto');

const LABEL = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/;

// Lowercases and drops one trailing dot. Returns null for anything that is not a plain,
// multi-label, non-IP hostname. No wildcards, no underscores, no unicode (callers send punycode).
function normalizeDomain(input) {
  if (typeof input !== 'string' || input.length > 253 + 1) return null;
  let domain = input.toLowerCase();
  if (domain.endsWith('.')) domain = domain.slice(0, -1);
  if (domain.length === 0 || domain.length > 253) return null;
  const labels = domain.split('.');
  if (labels.length < 2) return null;
  if (!labels.every((label) => LABEL.test(label))) return null;
  if (/^[0-9]+$/.test(labels[labels.length - 1])) return null; // IPv4 and numeric TLDs
  return domain;
}

function normalizeHost(value) {
  if (typeof value !== 'string') return '';
  const host = value.trim().toLowerCase();
  return host.endsWith('.') ? host.slice(0, -1) : host;
}

// Deterministic tenant name valid for [a-zA-Z0-9][a-zA-Z0-9-.]{1,126}[a-zA-Z0-9].
function tenantName(domain) {
  if (domain.length <= 128) return domain;
  const hash = crypto.createHash('sha256').update(domain).digest('hex').slice(0, 16);
  const prefix = domain.slice(0, 100).replace(/[-.]+$/, '');
  return `${prefix}-${hash}`;
}

module.exports = { normalizeDomain, normalizeHost, tenantName };
