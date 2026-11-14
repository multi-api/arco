'use strict';
// Arco Cloud extensions shipped inside an app: app/.arco-cloud/<name>/extension.json. The runtime knows two generic
// keys and nothing about what an extension does:
// - "front" (process apps): a process that listens where the app would and forwards to it.
// - "timeoutReserve" (PHP web functions): seconds that PHP's max_execution_time stops before the function timeout.
const nodeFs = require('node:fs');
const path = require('node:path');

const DIRECTORY = '.arco-cloud';

// The first valid front under appDir/.arco-cloud, by directory name; the others are ignored. Never throws.
function findFront(appDir, log, fs = nodeFs) {
  const base = path.join(appDir, DIRECTORY);
  let names;
  try {
    names = fs.readdirSync(base).sort();
  } catch {
    return null;
  }
  let found = null;
  for (const name of names) {
    const dir = path.join(base, name);
    let command;
    try {
      const manifest = JSON.parse(fs.readFileSync(path.join(dir, 'extension.json'), 'utf8'));
      if (!manifest || typeof manifest !== 'object' || !manifest.front) continue;
      command = manifest.front.command;
      if (!Array.isArray(command) || command.length === 0 || !command.every((part) => typeof part === 'string' && part !== '')) {
        throw new Error('front.command must be a non-empty array of strings');
      }
    } catch (error) {
      if (error && error.code === 'ENOENT') continue; // not an extension directory
      log('extension_failed', { extension: name, error: String(error && error.message || error) });
      continue;
    }
    if (found) {
      log('extension_ignored', { extension: name });
      continue;
    }
    found = { name, dir, command };
  }
  return found;
}

// Upper bound for one extension's timeoutReserve, in seconds.
const MAX_TIMEOUT_RESERVE = 10;

// The largest valid timeoutReserve under appDir/.arco-cloud, 0 when none asks for one. An invalid value logs
// extension_failed and counts as none. Never throws.
function findTimeoutReserve(appDir, log, fs = nodeFs) {
  let names;
  try {
    names = fs.readdirSync(path.join(appDir, DIRECTORY)).sort();
  } catch {
    return 0;
  }
  let reserve = 0;
  for (const name of names) {
    try {
      const manifest = JSON.parse(fs.readFileSync(path.join(appDir, DIRECTORY, name, 'extension.json'), 'utf8'));
      if (!manifest || typeof manifest !== 'object' || !('timeoutReserve' in manifest)) continue;
      const value = manifest.timeoutReserve;
      if (!Number.isInteger(value) || value < 0 || value > MAX_TIMEOUT_RESERVE) {
        throw new Error(`timeoutReserve must be an integer from 0 to ${MAX_TIMEOUT_RESERVE}`);
      }
      reserve = Math.max(reserve, value);
    } catch (error) {
      if (error && (error.code === 'ENOENT' || error.code === 'ENOTDIR')) continue; // not an extension directory
      log('extension_failed', { extension: name, error: String(error && error.message || error) });
    }
  }
  return reserve;
}

module.exports = { findFront, findTimeoutReserve, DIRECTORY, MAX_TIMEOUT_RESERVE };
