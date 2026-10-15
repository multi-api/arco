'use strict';
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const path = require('node:path');

function prepareTmp(dir) {
  for (const sub of [
    'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions',
    'storage/framework/views', 'storage/logs', 'caddy/config', 'caddy/data',
  ]) {
    fs.mkdirSync(path.join(dir, sub), { recursive: true });
  }
}

function resolveBinary(root, tmp) {
  const bundled = path.join(root, 'bin/frankenphp');
  try {
    fs.accessSync(bundled, fs.constants.X_OK);
    return bundled;
  } catch {
    const copy = path.join(tmp, 'frankenphp');
    if (!fs.existsSync(copy)) {
      fs.copyFileSync(bundled, copy);
      fs.chmodSync(copy, 0o755);
    }
    return copy;
  }
}

function spawnFrankenphp(binary, caddyfile, env, cwd, log) {
  const child = spawn(binary, ['run', '--config', caddyfile, '--adapter', 'caddyfile'], { cwd, env, stdio: 'inherit' });
  child.on('exit', (code, signal) => {
    log('frankenphp_exit', { code, signal });
    process.exit(code ?? 1);
  });
  for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => child.kill(signal));
  return child;
}

function waitForPort(host, port, timeoutMs = 9000) {
  const deadline = Date.now() + timeoutMs;
  return new Promise((resolve, reject) => {
    const attempt = () => {
      const socket = net.connect(port, host);
      socket.once('connect', () => { socket.destroy(); resolve(); });
      socket.once('error', () => {
        socket.destroy();
        if (Date.now() > deadline) reject(new Error(`FrankenPHP did not listen on ${host}:${port} within ${timeoutMs} ms`));
        else setTimeout(attempt, 25);
      });
    };
    attempt();
  });
}

const HOP_BY_HOP = new Set(['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade', 'te', 'trailer']);

function startProxy(host, port, upstreamHost, upstreamPort) {
  const agent = new http.Agent({ keepAlive: true, maxSockets: 256 });
  const server = http.createServer((req, res) => {
    const headers = {};
    for (const [name, value] of Object.entries(req.headers)) if (!HOP_BY_HOP.has(name)) headers[name] = value;
    const remote = req.socket.remoteAddress;
    headers['x-forwarded-for'] = req.headers['x-forwarded-for'] ? `${req.headers['x-forwarded-for']}, ${remote}` : remote;
    headers['x-forwarded-proto'] ??= 'https';
    headers['x-forwarded-host'] ??= req.headers.host;

    const upstream = http.request({ host: upstreamHost, port: upstreamPort, method: req.method, path: req.url, headers, agent }, (up) => {
      const out = {};
      for (const [name, value] of Object.entries(up.headers)) if (!HOP_BY_HOP.has(name)) out[name] = value;
      res.writeHead(up.statusCode, up.statusMessage, out);
      up.pipe(res);
    });
    upstream.on('error', () => {
      if (!res.headersSent) res.writeHead(502, { 'content-type': 'text/plain' });
      res.end('Bad Gateway');
    });
    req.on('aborted', () => upstream.destroy());
    req.pipe(upstream);
  });
  server.keepAliveTimeout = 65_000;
  return new Promise((resolve) => server.listen(port, host, () => resolve(server)));
}

module.exports = { prepareTmp, resolveBinary, spawnFrankenphp, waitForPort, startProxy };
