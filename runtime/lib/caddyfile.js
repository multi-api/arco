'use strict';
const path = require('node:path');

const iniValue = (value) => JSON.stringify(typeof value === 'boolean' ? (value ? '1' : '0') : String(value));

function buildCaddyfile(config, { host, port, root }) {
  const worker = config.workerScript;
  const workerCount = worker ? config.workers.min : 0;
  const maxThreads = Math.max(config.workers.max, config.threads + workerCount);

  const lines = ['{', '\tadmin off', '\tauto_https off', '\tfrankenphp {', `\t\tnum_threads ${config.threads}`, `\t\tmax_threads ${maxThreads}`];
  for (const [key, value] of Object.entries(config.phpIni || {})) lines.push(`\t\tphp_ini ${key} ${iniValue(value)}`);
  if (worker) lines.push('\t\tworker {', `\t\t\tfile ${root}/${worker}`, `\t\t\tnum ${workerCount}`, '\t\t}');
  lines.push('\t}', '}', '', `http://:${port} {`, `\tbind ${host}`, `\troot * ${root}/${config.publicDir}`, '\tencode zstd br gzip');
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
