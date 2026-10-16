// The REAL call banners (resources/js/outbound-call.js), driven under Node with a fake
// browser, to pin how they behave when the microphone is blocked, missing or fine.
//
//   incoming : the agent clicks Accept        -> incomingAtCall.acceptCall()
//   outgoing : AT bridges the answered customer to this browser, with NO click
//              -> outgoingCall.onIncoming()
//
// The Africa's Talking SDK never reports a failed microphone (it quietly declines the
// call), so these banners are the only thing that can tell the agent. A blocked microphone
// must show the "microphone blocked" screen and must NOT claim the call or call answer().
import test from 'node:test';
import assert from 'node:assert/strict';

// ── A minimal fake browser, installed BEFORE the module is imported ──────────
const mic = { permission: 'granted', error: null, tracksStopped: 0 };

const fakeNavigator = {
    permissions: { query: async () => ({ state: mic.permission }) },
    mediaDevices: {
        getUserMedia: async () => {
            if (mic.error) {
                const e = new Error(mic.error);
                e.name = mic.error;
                throw e;
            }
            return { getTracks: () => [{ stop: () => { mic.tracksStopped += 1; } }] };
        },
        enumerateDevices: async () => [],
    },
};

Object.defineProperty(globalThis, 'navigator', { value: fakeNavigator, configurable: true, writable: true });
globalThis.window = globalThis;
globalThis.addEventListener = () => {};
globalThis.BroadcastChannel = undefined; // Node's native one would hold the process open
globalThis.document = {
    readyState: 'complete',
    visibilityState: 'visible',
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener: () => {},
    createElement: () => ({ dataset: {} }),
    head: { appendChild: () => {} },
    body: {},
};

await import('../../resources/js/outbound-call.js');

// ── Helpers ──────────────────────────────────────────────────────────────────
function setMic({ permission = 'granted', error = null } = {}) {
    mic.permission = permission;
    mic.error = error;
}

/** Replace the shared softphone's methods with recorders. */
function stubSoftphone() {
    const seen = [];
    const vc = globalThis.bqVoiceClient;
    vc.isAvailable = () => true;
    vc.answer = () => seen.push('answer');
    vc.attach = () => {};
    vc.detach = () => {};
    vc.hangupCall = () => seen.push('hangup');
    return seen;
}

function incomingBanner(seen) {
    const requests = [];
    const banner = globalThis.incomingAtCall({ callId: 11, sessionId: 'sess-1', contactName: 'X', phone: '+234', csrf: 't' });
    banner.post = async (url) => { requests.push(url); return { ok: true, status: 200 }; };
    banner._incomingArrived = true; // AT's voice leg has already reached this browser
    return { banner, requests, seen };
}

function outgoingBanner() {
    const banner = globalThis.outgoingCall({ callId: 12, sessionId: 's', customerPhone: '+234', contactName: 'X', csrf: 't' });
    banner.state = 'calling';
    banner.safePost = async () => {};

    return banner;
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

// ── Incoming: the agent clicks Accept ────────────────────────────────────────
test('incoming: with the microphone fine, Accept claims the call and answers', async () => {
    setMic();
    const seen = stubSoftphone();
    const { banner, requests } = incomingBanner(seen);

    await banner.acceptCall();

    assert.deepEqual(requests, ['/calls/11/claim']);
    assert.deepEqual(seen, ['answer']);
    assert.equal(banner.state, 'connecting');
});

test('incoming: a blocked microphone shows the blocked screen and neither claims nor answers', async () => {
    setMic({ permission: 'denied' });
    const seen = stubSoftphone();
    const { banner, requests } = incomingBanner(seen);

    await banner.acceptCall();

    assert.equal(banner.state, 'mic_denied');
    assert.deepEqual(requests, [], 'a call must not be claimed when the agent cannot talk');
    assert.deepEqual(seen, []);
});

test('incoming: a refused prompt is treated as blocked too', async () => {
    setMic({ permission: 'prompt', error: 'NotAllowedError' });
    const seen = stubSoftphone();
    const { banner, requests } = incomingBanner(seen);

    await banner.acceptCall();

    assert.equal(banner.state, 'mic_denied');
    assert.deepEqual(requests, []);
});

test('incoming: no microphone at all is reported in words, without claiming', async () => {
    setMic({ error: 'NotFoundError' });
    const seen = stubSoftphone();
    const { banner, requests } = incomingBanner(seen);

    await banner.acceptCall();

    assert.equal(banner.state, 'connect_failed');
    assert.match(banner.errorMessage, /no microphone was found/i);
    assert.doesNotMatch(banner.errorMessage, /\[mic\]/, 'internal phase tag must not leak to the agent');
    assert.deepEqual(requests, []);
});

test('incoming: after the agent fixes the microphone, Try again claims and answers', async () => {
    setMic({ permission: 'denied' });
    const seen = stubSoftphone();
    const { banner, requests } = incomingBanner(seen);
    await banner.acceptCall();
    assert.equal(banner.state, 'mic_denied');

    setMic(); // they clicked Allow
    await banner.retryAccept();

    assert.deepEqual(requests, ['/calls/11/claim']);
    assert.deepEqual(seen, ['answer']);
});

// ── Outgoing: AT bridges the customer to this browser, no click ──────────────
test('outgoing: with the microphone fine, the bridged call is answered', async () => {
    setMic();
    const seen = stubSoftphone();
    const banner = outgoingBanner();

    banner.onIncoming();
    await settle();

    assert.deepEqual(seen, ['answer']);
    assert.equal(banner.state, 'connecting');
});

test('outgoing: a microphone blocked since the click shows the blocked screen, not a dead call', async () => {
    // The click-time check passed, then permission was revoked: the SDK would just
    // decline the call silently, so the banner must catch it.
    setMic({ permission: 'denied' });
    const seen = stubSoftphone();
    const banner = outgoingBanner();

    banner.onIncoming();
    await settle();

    assert.equal(banner.state, 'mic_denied');
    assert.deepEqual(seen, [], 'must not answer into a blocked microphone');
    assert.equal(banner.errorMessage, '', 'a plain block uses the screen\'s default wording');
});

test('outgoing: no microphone / busy microphone say what is actually wrong', async () => {
    const seen = stubSoftphone();

    setMic({ error: 'NotFoundError' });
    const missing = outgoingBanner();
    missing.onIncoming();
    await settle();
    assert.equal(missing.state, 'mic_denied');
    assert.match(missing.errorMessage, /no microphone was found/i);

    setMic({ error: 'NotReadableError' });
    const busy = outgoingBanner();
    busy.onIncoming();
    await settle();
    assert.match(busy.errorMessage, /another app or tab/i);

    assert.deepEqual(seen, []);
});

test('outgoing: Try again re-checks the microphone before answering', async () => {
    setMic({ permission: 'denied' });
    const seen = stubSoftphone();
    const banner = outgoingBanner();
    banner.onIncoming();
    await settle();

    // Still blocked: Try again must NOT answer blindly.
    banner.retryAccept();
    await settle();
    assert.equal(banner.state, 'mic_denied');
    assert.deepEqual(seen, []);

    // Fixed: now it answers.
    setMic();
    banner.retryAccept();
    await settle();
    assert.deepEqual(seen, ['answer']);
    assert.equal(banner.state, 'connecting');
});

test('outgoing: a call hung up while the microphone check was running is not answered', async () => {
    setMic();
    const seen = stubSoftphone();
    const banner = outgoingBanner();

    banner.onIncoming();      // starts the (async) check
    banner.state = 'ended';   // agent hung up / server terminated in the meantime
    await settle();

    assert.deepEqual(seen, [], 'answering a finished call would resurrect it');
});

test('outgoing: a duplicate incomingcall does not start a second answer', async () => {
    setMic();
    const seen = stubSoftphone();
    const banner = outgoingBanner();

    banner.onIncoming();
    banner.onIncoming();
    await settle();

    assert.deepEqual(seen, ['answer']);
});
