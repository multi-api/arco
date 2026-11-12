'use strict';
// The process kind: the customer's own binary (Go first) listens on 127.0.0.1:8000 behind the Node proxy.
const fs = require('node:fs');
const path = require('node:path');
const supervisor = require('./supervisor');

const PORT = 8000;
const HOST = '127.0.0.1';
const TMP = '/tmp/arco';
const START_TIMEOUT_MS = 10_000;
// How long a process gets to end after SIGTERM before it is killed. Lambda spins down idle instances only.
const STOP_GRACE_MS = 1000;

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
  let stopping = false;
  const child = supervisor.spawnProcess(executable, config.command.slice(1), {
    cwd: path.join(root, 'app'),
    env: { ...env, PORT: String(PORT), HOST, ARCO: '1', HOME: TMP },
    stdio: 'inherit',
    onExit: (code, signal) => {
      if (stopping) {
        // Lambda asked for the shutdown: any end of the process is a clean one. A non-zero exit would be reported
        // as Runtime.ExitError in platform.spinDownReport. A Go binary without a signal handler dies by SIGTERM.
        log('process_exit', { code, signal, shutdown: true });
        process.exit(0);
        return;
      }
      log('process_exit', { code, signal });
      process.exit(code ?? 1);
    },
  });
  // supervisor.spawnProcess forwards the signal to the process; a process that ignores it is killed after the grace.
  for (const name of ['SIGTERM', 'SIGINT']) {
    process.on(name, () => {
      if (stopping) return;
      stopping = true;
      setTimeout(() => child.kill('SIGKILL'), STOP_GRACE_MS).unref();
    });
  }
  await supervisor.waitForPort(HOST, PORT, START_TIMEOUT_MS, 'process');
  log('process_ready');
  return child;
}

module.exports = { PORT, HOST, startProcess, isInternalPath: supervisor.isInternalPath };
