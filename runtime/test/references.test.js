'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { isReference, parseReference } = require('../lib/references');

const vectors = require(path.join(__dirname, '../../../arco-secrets/test-vectors.json'));

for (const vector of vectors) {
  test(`vector ${vector.input}`, () => {
    if (!vector.valid) {
      if (isReference(vector.input)) {
        assert.throws(() => parseReference(vector.input));
      } else {
        assert.equal(isReference(vector.input), false);
      }
      return;
    }
    assert.deepEqual(parseReference(vector.input), {
      source: vector.source,
      name: vector.name,
      jsonKey: vector.jsonKey,
    });
  });
}
