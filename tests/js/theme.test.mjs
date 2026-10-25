import { test } from 'node:test';
import assert from 'node:assert/strict';

/**
 * resources/js/theme.js against a minimal fake browser.
 *
 * The regression this guards (found in a real browser): Livewire's wire:navigate swaps
 * the whole <html> element, which drops the `dark` class while localStorage still says
 * "dark". The inline <head> bootstrap only runs on a full page load, so theme.js must
 * re-apply the saved choice on every `livewire:navigated`.
 */

function installFakeBrowser({ saved = null, prefersDark = false } = {}) {
    const classes = new Set();
    const store = new Map(saved === null ? [] : [['bq-theme', saved]]);
    const docListeners = {};
    const mqListeners = [];
    const fired = [];

    globalThis.document = {
        documentElement: {
            classList: {
                toggle(name, on) { on ? classes.add(name) : classes.delete(name); return classes.has(name); },
                contains: (name) => classes.has(name),
            },
        },
        addEventListener: (type, fn) => { (docListeners[type] ||= []).push(fn); },
    };
    globalThis.localStorage = {
        getItem: (k) => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => { store.set(k, String(v)); },
    };
    globalThis.CustomEvent = class { constructor(type, init) { this.type = type; this.detail = init?.detail; } };
    globalThis.window = {
        matchMedia: () => ({ matches: prefersDark, addEventListener: (_t, fn) => mqListeners.push(fn) }),
        dispatchEvent: (e) => { fired.push(e); return true; },
    };

    return {
        classes,
        store,
        fired,
        /** Simulate Livewire swapping in a fresh <html> that has no class. */
        swapPage: () => { classes.clear(); (docListeners['livewire:navigated'] || []).forEach((fn) => fn()); },
        osChanges: (matches) => mqListeners.forEach((fn) => fn({ matches })),
    };
}

// theme.js attaches listeners at import time, so each scenario needs a fresh module instance.
let n = 0;
const load = () => import(`../../resources/js/theme.js?case=${n++}`);

test('toggle flips the class, saves the choice and announces it', async () => {
    const b = installFakeBrowser({ prefersDark: false });
    await load();

    const dark = window.bqTheme.toggle();

    assert.equal(dark, true);
    assert.ok(b.classes.has('dark'));
    assert.equal(b.store.get('bq-theme'), 'dark');
    assert.equal(b.fired.at(-1).type, 'bq-theme-changed');
    assert.equal(b.fired.at(-1).detail.dark, true);

    assert.equal(window.bqTheme.toggle(), false);
    assert.equal(b.store.get('bq-theme'), 'light');
});

test('a saved dark choice is re-applied after Livewire swaps the page', async () => {
    const b = installFakeBrowser({ saved: 'dark', prefersDark: false });
    await load();
    b.classes.add('dark');

    b.swapPage();

    assert.ok(b.classes.has('dark'), 'dark survives wire:navigate');
});

test('with no saved choice, a navigation re-applies the OS preference', async () => {
    const b = installFakeBrowser({ saved: null, prefersDark: true });
    await load();

    b.swapPage();

    assert.ok(b.classes.has('dark'));
});

test('a saved light choice beats an OS dark preference after navigation', async () => {
    const b = installFakeBrowser({ saved: 'light', prefersDark: true });
    await load();

    b.swapPage();

    assert.ok(!b.classes.has('dark'));
});

test('OS changes are followed only until the user has chosen', async () => {
    const free = installFakeBrowser({ saved: null });
    await load();
    free.osChanges(true);
    assert.ok(free.classes.has('dark'), 'follows the OS while undecided');

    const chosen = installFakeBrowser({ saved: 'light' });
    await load();
    chosen.osChanges(true);
    assert.ok(!chosen.classes.has('dark'), 'ignores the OS once a choice is saved');
});
