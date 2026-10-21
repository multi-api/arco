'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { buildCaddyfile } = require('../lib/caddyfile');

test('worker mode', () => {
  const caddyfile = buildCaddyfile(
    {
      mode: 'direct',
      publicDir: 'app/public',
      workerScript: 'app/public/frankenphp-worker.php',
      threads: 8,
      workers: { min: 4, max: 64 },
      phpIni: { memory_limit: '512M', 'opcache.enable': true, 'date.timezone': 'America/Santiago' },
    },
    { host: '0.0.0.0', port: 3000, root: '/var/task' },
  );

  assert.equal(caddyfile, [
    '{',
    '\tadmin off',
    '\tauto_https off',
    '\tfrankenphp {',
    '\t\tnum_threads 8',
    '\t\tmax_threads 64',
    '\t\tphp_ini session.upload_progress.enabled "0"',
    '\t\tphp_ini fatal_error_backtraces "0"',
    '\t\tphp_ini memory_limit "512M"',
    '\t\tphp_ini opcache.enable "1"',
    '\t\tphp_ini date.timezone "America/Santiago"',
    '\t\tworker {',
    '\t\t\tfile /var/task/app/public/frankenphp-worker.php',
    '\t\t\tnum 4',
    '\t\t}',
    '\t}',
    '}',
    '',
    'http://:3000 {',
    '\tbind 0.0.0.0',
    '\troot * /var/task/app/public',
    '\tencode zstd br gzip',
    '\tphp_server {',
    '\t\tindex frankenphp-worker.php',
    '\t\ttry_files {path} frankenphp-worker.php',
    '\t}',
    '}',
    '',
  ].join('\n'));
});

test('classic mode raises max_threads to fit num_threads', () => {
  const caddyfile = buildCaddyfile(
    { mode: 'proxy', publicDir: 'app/public', workerScript: null, threads: 80, workers: { min: 4, max: 64 }, phpIni: {} },
    { host: '127.0.0.1', port: 8000, root: '/var/task' },
  );
  assert.match(caddyfile, /\t\tnum_threads 80\n\t\tmax_threads 80\n/);
  assert.match(caddyfile, /\n\tphp_server\n}/);
  assert.doesNotMatch(caddyfile, /worker/);
});

test('security defaults apply unless the app sets the key (CVE-2026-109618/109619 hardening)', () => {
  const base = { mode: 'proxy', publicDir: 'app/public', workerScript: null, threads: 8, workers: { min: 1, max: 8 } };
  const ctx = { host: '127.0.0.1', port: 8000, root: '/var/task' };
  const defaults = buildCaddyfile({ ...base, phpIni: {} }, ctx);
  assert.match(defaults, /\t\tphp_ini session\.upload_progress\.enabled "0"\n\t\tphp_ini fatal_error_backtraces "0"\n/);
  const overridden = buildCaddyfile({ ...base, phpIni: { 'session.upload_progress.enabled': true } }, ctx);
  assert.match(overridden, /php_ini session\.upload_progress\.enabled "1"/);
  assert.doesNotMatch(overridden, /session\.upload_progress\.enabled "0"/);
});
