// Run with: npm run test:js   (Node's built-in test runner, no extra dependency)
import test from 'node:test';
import assert from 'node:assert/strict';
import { createMicPreflight, describeMicError } from '../../resources/js/mic-preflight.js';

// A fake MediaStream whose tracks record whether they were stopped.
function fakeStream() {
    const track = { stopped: false, stop() { this.stopped = true; } };
    return { track, getTracks: () => [track] };
}

function domError(name) {
    const e = new Error(name);
    e.name = name;
    return e;
}

test('resolves and releases the microphone when access is granted', async () => {
    const stream = fakeStream();
    const nav = {
        permissions: { query: async () => ({ state: 'granted' }) },
        mediaDevices: { getUserMedia: async () => stream },
    };

    assert.equal(await createMicPreflight(nav)(), true);
    // The pre-flight only proves access. Holding the mic open would leave the
    // browser's recording indicator on and block the SDK from opening it.
    assert.equal(stream.track.stopped, true);
});

test('prompts when permission is undecided, because it runs inside a click', async () => {
    const stream = fakeStream();
    let prompted = false;
    const nav = {
        permissions: { query: async () => ({ state: 'prompt' }) },
        mediaDevices: { getUserMedia: async () => { prompted = true; return stream; } },
    };

    await createMicPreflight(nav)();

    assert.equal(prompted, true);
});

test('fails fast, without prompting, when the site is already blocked', async () => {
    let asked = false;
    const nav = {
        permissions: { query: async () => ({ state: 'denied' }) },
        mediaDevices: { getUserMedia: async () => { asked = true; return fakeStream(); } },
    };

    await assert.rejects(createMicPreflight(nav)(), (e) => {
        assert.equal(e.name, 'NotAllowedError'); // the banners already key off this name
        assert.equal(e.kind, 'denied');
        return true;
    });
    assert.equal(asked, false);
});

test('maps a refused prompt to a denied error', async () => {
    const nav = {
        permissions: { query: async () => ({ state: 'prompt' }) },
        mediaDevices: { getUserMedia: async () => { throw domError('NotAllowedError'); } },
    };

    await assert.rejects(createMicPreflight(nav)(), (e) => e.kind === 'denied' && e.name === 'NotAllowedError');
});

test('still works where the Permissions API cannot query the microphone (Firefox/Safari)', async () => {
    const stream = fakeStream();
    const nav = {
        permissions: { query: async () => { throw new TypeError('not supported'); } },
        mediaDevices: { getUserMedia: async () => stream },
    };

    assert.equal(await createMicPreflight(nav)(), true);
});

test('still works when there is no Permissions API at all', async () => {
    const nav = { mediaDevices: { getUserMedia: async () => fakeStream() } };

    assert.equal(await createMicPreflight(nav)(), true);
});

test('reports a missing microphone distinctly', async () => {
    const nav = { mediaDevices: { getUserMedia: async () => { throw domError('NotFoundError'); } } };

    await assert.rejects(createMicPreflight(nav)(), (e) => e.kind === 'no_device');
});

test('reports a microphone another app is holding distinctly', async () => {
    const nav = { mediaDevices: { getUserMedia: async () => { throw domError('NotReadableError'); } } };

    await assert.rejects(createMicPreflight(nav)(), (e) => e.kind === 'busy');
});

test('reports an insecure page, where mediaDevices does not exist', async () => {
    await assert.rejects(createMicPreflight({})(), (e) => e.kind === 'unavailable');
    await assert.rejects(createMicPreflight({ mediaDevices: {} })(), (e) => e.kind === 'unavailable');
});

test('every failure has a plain-language message that says what to do', () => {
    for (const kind of ['denied', 'no_device', 'busy', 'unavailable', 'failed']) {
        const msg = describeMicError({ kind });
        assert.ok(msg.length > 30, `${kind} message too thin: ${msg}`);
    }
    assert.match(describeMicError({ kind: 'denied' }), /padlock|site settings|allow/i);
    assert.match(describeMicError({ kind: 'unavailable' }), /https|secure/i);
    // Anything unexpected still produces a usable message rather than "undefined".
    assert.ok(describeMicError(new Error('boom')).length > 30);
    assert.ok(describeMicError(undefined).length > 30);
});
