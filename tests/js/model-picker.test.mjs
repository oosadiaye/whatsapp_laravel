import { test } from 'node:test';
import assert from 'node:assert/strict';
import { filterModels, formatPrice, describeModel, initialSelection } from '../../resources/js/model-picker.js';

const MODELS = [
    { id: 'openai/gpt-4o', name: 'OpenAI: GPT-4o', free: false, vision: true, context: 128000, prompt_per_million: 2.5, completion_per_million: 10 },
    { id: 'meta-llama/llama-3.3-70b-instruct:free', name: 'Meta: Llama 3.3 70B (free)', free: true, vision: false, context: 65536, prompt_per_million: 0, completion_per_million: 0 },
    { id: 'google/gemma-3-27b-it:free', name: 'Google: Gemma 3 27B (free)', free: true, vision: true, context: 96000, prompt_per_million: 0, completion_per_million: 0 },
    { id: 'openrouter/auto', name: 'Auto Router', free: false, vision: false, context: 2000000, prompt_per_million: null, completion_per_million: null },
];

const ids = (list) => list.map((m) => m.id);

test('no filters returns every model', () => {
    assert.equal(filterModels(MODELS, {}).length, 4);
});

test('free filter keeps only free models', () => {
    assert.deepEqual(ids(filterModels(MODELS, { onlyFree: true })), [
        'meta-llama/llama-3.3-70b-instruct:free',
        'google/gemma-3-27b-it:free',
    ]);
});

test('image filter keeps only models that accept images', () => {
    assert.deepEqual(ids(filterModels(MODELS, { onlyVision: true })), ['openai/gpt-4o', 'google/gemma-3-27b-it:free']);
});

test('free and image filters combine (AND)', () => {
    assert.deepEqual(ids(filterModels(MODELS, { onlyFree: true, onlyVision: true })), ['google/gemma-3-27b-it:free']);
});

test('search matches name or id, case-insensitively, every word', () => {
    assert.deepEqual(ids(filterModels(MODELS, { query: 'GEMMA' })), ['google/gemma-3-27b-it:free']);
    assert.deepEqual(ids(filterModels(MODELS, { query: 'meta 70b' })), ['meta-llama/llama-3.3-70b-instruct:free']);
    assert.deepEqual(ids(filterModels(MODELS, { query: 'openai/' })), ['openai/gpt-4o']);
    assert.deepEqual(ids(filterModels(MODELS, { query: 'nothing-matches' })), []);
});

test('filtering does not mutate the input list', () => {
    const copy = JSON.parse(JSON.stringify(MODELS));
    filterModels(MODELS, { onlyFree: true, query: 'x' });
    assert.deepEqual(MODELS, copy);
});

test('formatPrice shows Free, a rounded price, or Varies', () => {
    assert.equal(formatPrice(MODELS[1]), 'Free');
    assert.equal(formatPrice(MODELS[0]), '$2.5 in · $10 out per 1M tokens');
    assert.equal(formatPrice(MODELS[3]), 'Price varies');
});

test('describeModel shows the id of an unlisted model instead of a blank', () => {
    assert.equal(describeModel(MODELS, 'openai/gpt-4o'), 'OpenAI: GPT-4o');
    assert.equal(describeModel(MODELS, 'some/retired-model'), 'some/retired-model');
    assert.equal(describeModel(MODELS, ''), '');
});

test('initialSelection drops ids that are not OpenRouter style (e.g. a leftover Gemini default)', () => {
    assert.equal(initialSelection('gemini-2.0-flash'), '');
    assert.equal(initialSelection('openai/gpt-4o'), 'openai/gpt-4o');
    assert.equal(initialSelection(''), '');
    assert.equal(initialSelection(undefined), '');
});
