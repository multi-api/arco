'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { findFront, findTimeoutReserve } = require('../lib/extensions');

function app(t, extensions = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'arco-ext-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  for (const [name, content] of Object.entries(extensions)) {
    fs.mkdirSync(path.join(dir, '.arco-cloud', name), { recursive: true });
    fs.writeFileSync(path.join(dir, '.arco-cloud', name, 'extension.json'), typeof content === 'string' ? content : JSON.stringify(content));
  }
  const logs = [];
  return { dir, logs, log: (phase, data = {}) => logs.push({ phase, data }) };
}

test('findFront returns null without .arco-cloud', (t) => {
  const { dir, log, logs } = app(t);
  assert.equal(findFront(dir, log), null);
  assert.deepEqual(logs, []);
});

test('findFront returns the command of one valid extension', (t) => {
  const { dir, log } = app(t, { 'a-ext': { front: { command: ['bin/front', '--x'] } } });
  assert.deepEqual(findFront(dir, log), { name: 'a-ext', dir: path.join(dir, '.arco-cloud', 'a-ext'), command: ['bin/front', '--x'] });
});

test('findFront picks a-ext over b-ext and logs extension_ignored for b-ext', (t) => {
  const { dir, log, logs } = app(t, {
    'b-ext': { front: { command: ['b'] } },
    'a-ext': { front: { command: ['a'] } },
  });
  assert.equal(findFront(dir, log).name, 'a-ext');
  assert.deepEqual(logs, [{ phase: 'extension_ignored', data: { extension: 'b-ext' } }]);
});

test('findFront skips a broken JSON with extension_failed', (t) => {
  const { dir, log, logs } = app(t, { 'a-ext': '{nope', 'b-ext': { front: { command: ['b'] } } });
  assert.equal(findFront(dir, log).name, 'b-ext');
  assert.equal(logs.length, 1);
  assert.equal(logs[0].phase, 'extension_failed');
  assert.equal(logs[0].data.extension, 'a-ext');
});

test('findFront skips an invalid command and a manifest without a front', (t) => {
  const { dir, log, logs } = app(t, { a: { front: { command: [] } }, b: { other: {} }, c: { front: { command: ['c'] } } });
  assert.equal(findFront(dir, log).name, 'c');
  assert.deepEqual(logs.map((l) => [l.phase, l.data.extension]), [['extension_failed', 'a']]);
});

test('findTimeoutReserve is 0 without .arco-cloud or without the key', (t) => {
  const empty = app(t);
  assert.equal(findTimeoutReserve(empty.dir, empty.log), 0);
  const front = app(t, { 'a-ext': { front: { command: ['bin/front'] } } });
  assert.equal(findTimeoutReserve(front.dir, front.log), 0);
  assert.deepEqual([...empty.logs, ...front.logs], []);
});

test('findTimeoutReserve returns the largest reserve of every extension', (t) => {
  const { dir, log, logs } = app(t, { a: { timeoutReserve: 1 }, b: { timeoutReserve: 2, other: true }, c: {} });
  fs.mkdirSync(path.join(dir, '.arco-cloud', 'no-manifest'));
  fs.writeFileSync(path.join(dir, '.arco-cloud', 'stray-file'), 'x');
  assert.equal(findTimeoutReserve(dir, log), 2);
  assert.deepEqual(logs, []);
});

test('findTimeoutReserve skips an invalid reserve or manifest and logs it', (t) => {
  const { dir, log, logs } = app(t, { a: { timeoutReserve: 1.5 }, b: { timeoutReserve: -1 }, c: { timeoutReserve: 11 }, d: { timeoutReserve: '2' }, e: '{', f: { timeoutReserve: 3 } });
  assert.equal(findTimeoutReserve(dir, log), 3);
  assert.deepEqual(logs.map((entry) => [entry.phase, entry.data.extension]), [['extension_failed', 'a'], ['extension_failed', 'b'], ['extension_failed', 'c'], ['extension_failed', 'd'], ['extension_failed', 'e']]);
});

test('a manifest with only timeoutReserve is not a front', (t) => {
  const { dir, log, logs } = app(t, { xray: { timeoutReserve: 2 } });
  assert.equal(findFront(dir, log), null);
  assert.deepEqual(logs, []);
});
