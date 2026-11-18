'use strict';
const fs = require('node:fs');
const path = require('node:path');

const DEFAULT_PATH = path.join(__dirname, 'public_suffix_list.dat');

// Parses the Public Suffix List format. ICANN and PRIVATE sections are both honoured: a customer
// domain under a private suffix (for example github.io) is a subdomain of someone else's registry.
function parseSuffixList(text) {
  const rules = new Set();
  const wildcards = new Set();
  const exceptions = new Set();
  for (const raw of String(text).split(/\r?\n/)) {
    const line = raw.trim().split(/\s+/)[0] ?? '';
    if (line === '' || line.startsWith('//')) continue;
    const rule = line.toLowerCase();
    if (rule.startsWith('!')) exceptions.add(rule.slice(1));
    else if (rule.startsWith('*.')) wildcards.add(rule.slice(2));
    else rules.add(rule);
  }
  if (rules.size + wildcards.size + exceptions.size === 0) return null;

  // Number of labels of the public suffix of a hostname (PSL algorithm, default rule "*").
  function suffixLength(labels) {
    let best = 1;
    for (let i = 0; i < labels.length; i += 1) {
      const candidate = labels.slice(i).join('.');
      const length = labels.length - i;
      if (exceptions.has(candidate)) return length - 1;
      if (rules.has(candidate)) best = Math.max(best, length);
      if (length >= 2 && wildcards.has(labels.slice(i + 1).join('.'))) best = Math.max(best, length);
    }
    return best;
  }

  // The registrable domain (eTLD+1), or null when the hostname is itself a public suffix.
  function registrableDomain(hostname) {
    const labels = String(hostname).toLowerCase().split('.');
    const length = suffixLength(labels);
    if (labels.length <= length) return null;
    return labels.slice(labels.length - length - 1).join('.');
  }

  return { registrableDomain };
}

// Reads the bundled list. Returns null when it is missing or empty: callers must then fail closed.
function loadSuffixes(file = DEFAULT_PATH) {
  try {
    return parseSuffixList(fs.readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}

module.exports = { parseSuffixList, loadSuffixes, DEFAULT_PATH };
