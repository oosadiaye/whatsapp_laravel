import { test } from 'node:test';
import assert from 'node:assert/strict';
import { splitEmailInput } from '../../resources/js/email-chips.js';

test('splits on commas, semicolons, spaces and newlines', () => {
    const { valid, invalid } = splitEmailInput('a@x.com, b@y.org;c@z.net\nd@w.io  e@v.co');
    assert.deepEqual(valid, ['a@x.com', 'b@y.org', 'c@z.net', 'd@w.io', 'e@v.co']);
    assert.deepEqual(invalid, []);
});

test('lower-cases and removes duplicates', () => {
    assert.deepEqual(splitEmailInput('Bob@Example.com bob@example.com').valid, ['bob@example.com']);
});

test('reports what is not an email address', () => {
    const { valid, invalid } = splitEmailInput('good@x.com nonsense bob@yahoo @nope.com');
    assert.deepEqual(valid, ['good@x.com']);
    assert.deepEqual(invalid, ['nonsense', 'bob@yahoo', '@nope.com']);
});

test('rejects angle-bracket and quote tricks', () => {
    assert.deepEqual(splitEmailInput('"a"@x.com <b@x.com>').valid, []);
});

test('empty and null input give nothing', () => {
    assert.deepEqual(splitEmailInput('').valid, []);
    assert.deepEqual(splitEmailInput(null).invalid, []);
});
