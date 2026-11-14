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
    '\t@noRequestId {',
    '\t\theader !X-Request-ID',
    '\t\theader !X-Amzn-RequestId',
    '\t\theader !X-Amz-Request-Id',
    '\t\theader !X-Amzn-Trace-Id',
    '\t}',
    '\trequest_header @noRequestId X-Request-ID {http.request.uuid}',
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

test('max_execution_time follows the function timeout (ARCO_TIMEOUT) unless phpIni() sets it', () => {
  const base = { mode: 'direct', publicDir: 'app/public', workerScript: null, threads: 8, workers: { min: 1, max: 8 } };
  const ctx = { host: '0.0.0.0', port: 3000, root: '/var/task' };
  // Without a timeout (an older deploy, or the variable missing) PHP keeps its own default: no line.
  assert.doesNotMatch(buildCaddyfile({ ...base, phpIni: {} }, ctx), /max_execution_time/);
  assert.doesNotMatch(buildCaddyfile({ ...base, phpIni: {} }, { ...ctx, timeout: Number(undefined) }), /max_execution_time/);
  assert.match(buildCaddyfile({ ...base, phpIni: {} }, { ...ctx, timeout: 120 }), /\t\tphp_ini max_execution_time "120"\n/);
  const overridden = buildCaddyfile({ ...base, phpIni: { max_execution_time: 0 } }, { ...ctx, timeout: 120 });
  assert.match(overridden, /php_ini max_execution_time "0"/);
  assert.doesNotMatch(overridden, /max_execution_time "120"/);
});

test('accessLog() sends Caddy access log entries to stdout as JSON', () => {
  const base = { mode: 'direct', publicDir: 'app/public', workerScript: null, threads: 8, workers: { min: 4, max: 64 }, phpIni: {} };
  const target = { host: '0.0.0.0', port: 3000, root: '/var/task' };
  assert.doesNotMatch(buildCaddyfile(base, target), /\blog\b/);
  assert.match(buildCaddyfile({ ...base, accessLog: true }, target), /\trequest_header @noRequestId X-Request-ID {http.request.uuid}\n\tlog {\n\t\toutput stdout\n\t\tformat json\n\t}\n\tlog_append arco_request_id {http.request.uuid}\n\tphp_server\n}/);
});

// Telemetry: Caddy writes one JSON line per access log entry to lib/caddycount.js on 127.0.0.1:<countPort>, which counts
// the lines. Caddy keeps one default logger per site without a host name, so the counter is a global logger that
// includes every access logger rather than a second `log` in the site block (that one would replace accessLog()'s).
// The entries carry no request data (URI, query, headers, IP, ids): Caddy prints them to stderr if the counter's
// connection is lost.
const COUNT_LOGGER = [
  '\tlog arco_count {',
  '\t\toutput net 127.0.0.1:40123 {',
  '\t\t\tsoft_start',
  '\t\t}',
  '\t\tformat filter {',
  '\t\t\twrap json',
  '\t\t\tfields {',
  '\t\t\t\trequest delete',
  '\t\t\t\tresp_headers delete',
  '\t\t\t\tuser_id delete',
  '\t\t\t\tbytes_read delete',
  '\t\t\t\tuuid delete',
  '\t\t\t\tarco_request_id delete',
  '\t\t\t}',
  '\t\t}',
  '\t\tinclude http.log.access',
  '\t}',
  '',
].join('\n');

test('telemetry adds the arco_count logger and enables access logs to a discarded output', () => {
  const base = { mode: 'direct', publicDir: 'app/public', workerScript: null, threads: 8, workers: { min: 4, max: 64 }, phpIni: {} };
  const caddyfile = buildCaddyfile(base, { host: '0.0.0.0', port: 3000, root: '/var/task', countPort: 40123 });
  assert.ok(caddyfile.includes(`\t}\n${COUNT_LOGGER}}\n`), caddyfile);
  assert.match(caddyfile, /\trequest_header @noRequestId X-Request-ID {http.request.uuid}\n\tlog {\n\t\toutput discard\n\t}\n\tphp_server\n}/);
  assert.doesNotMatch(caddyfile, /output stdout|log_append/);
});

test('telemetry keeps the accessLog() logger as it is', () => {
  const base = { mode: 'direct', publicDir: 'app/public', workerScript: null, threads: 8, workers: { min: 4, max: 64 }, phpIni: {}, accessLog: true };
  const target = { host: '0.0.0.0', port: 3000, root: '/var/task' };
  const without = buildCaddyfile(base, target);
  const withTelemetry = buildCaddyfile(base, { ...target, countPort: 40123 });
  assert.equal(withTelemetry, without.replace('\t}\n}\n', `\t}\n${COUNT_LOGGER}}\n`));
  assert.doesNotMatch(withTelemetry, /output discard/);
});

test('without a counter port there is no arco_count logger and no site log', () => {
  const base = { mode: 'direct', publicDir: 'app/public', workerScript: null, threads: 8, workers: { min: 4, max: 64 }, phpIni: {} };
  for (const countPort of [undefined, null, 0, 'x']) {
    const caddyfile = buildCaddyfile(base, { host: '0.0.0.0', port: 3000, root: '/var/task', countPort });
    assert.doesNotMatch(caddyfile, /arco_count|\blog\b/);
  }
});

test('every field log_append adds is deleted from arco_count entries', () => {
  const base = { mode: 'direct', publicDir: 'app/public', threads: 8, workers: { min: 4, max: 64 }, phpIni: {} };
  const target = { host: '0.0.0.0', port: 3000, root: '/var/task', countPort: 40123 };
  let appended = 0;
  for (const accessLog of [true, false]) {
    for (const workerScript of [null, 'app/public/frankenphp-worker.php']) {
      const caddyfile = buildCaddyfile({ ...base, accessLog, workerScript }, target);
      const counter = caddyfile.slice(caddyfile.indexOf('\tlog arco_count {'), caddyfile.indexOf('\t\tinclude http.log.access'));
      for (const [, field] of caddyfile.matchAll(/\blog_append\s+(\S+)/g)) {
        appended++;
        assert.ok(counter.includes(`\t\t\t\t${field} delete\n`), `${field} reaches arco_count entries:\n${caddyfile}`);
      }
    }
  }
  assert.ok(appended > 0, 'the check sees log_append');
});
