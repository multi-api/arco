'use strict';
const path = require('node:path');

// Applied under the app's phpIni(), which can override each key: php.net's interim hardening for
// CVE-2026-109618/109619 (2026-10-21). Keep in sync with PhpIni::SECURITY_DEFAULTS (runtime/console/PhpIni.php).
const SECURITY_INI = { 'session.upload_progress.enabled': false, fatal_error_backtraces: false };

// Lambda Web Functions add no request id: one is made up for a request that carries none of the headers
// ShareRequestId (multiapi/arco-laravel) and lib/accesslog.js read, so the app's log lines and the access line share it.
const REQUEST_ID_HEADERS = ['X-Request-ID', 'X-Amzn-RequestId', 'X-Amz-Request-Id', 'X-Amzn-Trace-Id'];

const iniValue = (value) => JSON.stringify(typeof value === 'boolean' ? (value ? '1' : '0') : String(value));

function buildCaddyfile(config, { host, port, root }) {
  const worker = config.workerScript;
  const workerCount = worker ? config.workers.min : 0;
  const maxThreads = Math.max(config.workers.max, config.threads + workerCount);

  const lines = ['{', '\tadmin off', '\tauto_https off', '\tfrankenphp {', `\t\tnum_threads ${config.threads}`, `\t\tmax_threads ${maxThreads}`];
  for (const [key, value] of Object.entries({ ...SECURITY_INI, ...(config.phpIni || {}) })) lines.push(`\t\tphp_ini ${key} ${iniValue(value)}`);
  if (worker) lines.push('\t\tworker {', `\t\t\tfile ${root}/${worker}`, `\t\t\tnum ${workerCount}`, '\t\t}');
  lines.push('\t}', '}', '', `http://:${port} {`, `\tbind ${host}`, `\troot * ${root}/${config.publicDir}`, '\tencode zstd br gzip');
  lines.push('\t@noRequestId {', ...REQUEST_ID_HEADERS.map((name) => `\t\theader !${name}`), '\t}', '\trequest_header @noRequestId X-Request-ID {http.request.uuid}');
  // accessLog(): to stdout, where lib/accesslog.js rewrites each entry before it reaches CloudWatch.
  // The entry shows the headers as they arrived, so it also carries the made-up id (the same uuid all through a request).
  if (config.accessLog) lines.push('\tlog {', '\t\toutput stdout', '\t\tformat json', '\t}', '\tlog_append arco_request_id {http.request.uuid}');
  if (worker) {
    const script = path.basename(worker);
    lines.push('\tphp_server {', `\t\tindex ${script}`, `\t\ttry_files {path} ${script}`, '\t}');
  } else {
    lines.push('\tphp_server');
  }
  lines.push('}', '');
  return lines.join('\n');
}

module.exports = { buildCaddyfile };
