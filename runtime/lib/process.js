'use strict';
// The process kind: the customer's own binary (Go first) listens on 127.0.0.1:8000 behind the Node proxy.
const fs = require('node:fs');
const path = require('node:path');
const supervisor = require('./supervisor');

const PORT = 8000;
const HOST = '127.0.0.1';
const TMP = '/tmp/arco';
const START_TIMEOUT_MS = 10_000;

// command is [executable, ...arguments], the executable relative to app/. A zip that lost the executable bit gets a
// copy in /tmp/arco/bin, as resolveBinary does for FrankenPHP.
function resolveExecutable(root, command) {
  const bundled = path.join(root, 'app', command[0]);
  try {
    fs.accessSync(bundled, fs.constants.X_OK);
    return bundled;
  } catch {
    const copy = path.join(TMP, 'bin', path.basename(command[0]));
    if (!fs.existsSync(copy)) {
      fs.copyFileSync(bundled, copy);
      fs.chmodSync(copy, 0o755);
    }
    return copy;
  }
}

async function startProcess(root, config, env, log) {
  const executable = resolveExecutable(root, config.command);
  const child = supervisor.spawnProcess(executable, config.command.slice(1), {
    cwd: path.join(root, 'app'),
    env: { ...env, PORT: String(PORT), HOST, ARCO: '1', HOME: TMP },
    stdio: 'inherit',
    onExit: (code, signal) => {
      log('process_exit', { code, signal });
      process.exit(code ?? 1);
    },
  });
  await supervisor.waitForPort(HOST, PORT, START_TIMEOUT_MS, 'process');
  log('process_ready');
  return child;
}

module.exports = { PORT, HOST, startProcess, isInternalPath: supervisor.isInternalPath };
