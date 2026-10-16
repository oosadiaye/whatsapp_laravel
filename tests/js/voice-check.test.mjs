import test from 'node:test';
import assert from 'node:assert/strict';
import {
    checkNetwork,
    checkStunReachable,
    checkSoftphone,
    countOtherTabs,
    interpretResults,
    buildReport,
} from '../../resources/js/voice-check.js';
import { startTabPresence } from '../../resources/js/tab-presence.js';

// ── Fakes ────────────────────────────────────────────────────────────────────

/** A peer connection that emits a scripted list of ICE candidates, then completes. */
function fakePeerConnection(script, { complete = true } = {}) {
    return class FakePC {
        constructor(config) {
            this.config = config;
            this.iceGatheringState = 'new';
            this.closed = false;
            FakePC.last = this;
        }
        createDataChannel() {}
        async createOffer() { return { type: 'offer', sdp: 'v=0' }; }
        async setLocalDescription() {
            queueMicrotask(() => {
                for (const candidate of script) this.onicecandidate?.({ candidate });
                if (complete) {
                    this.iceGatheringState = 'complete';
                    this.onicecandidate?.({ candidate: null });
                }
            });
        }
        close() { this.closed = true; }
    };
}

const host = { type: 'host', protocol: 'udp', address: 'abc.local', port: 40000 };
const srflx = (port, relatedPort = 40000) => ({ type: 'srflx', protocol: 'udp', address: '41.1.2.3', port, relatedPort });
const relay = { type: 'relay', protocol: 'udp', address: '5.6.7.8', port: 3478, relatedPort: 40000 };

// A BroadcastChannel hub: a message reaches every OTHER instance on the same name.
function broadcastChannelHub() {
    const open = [];
    return class FakeBroadcastChannel {
        constructor(name) { this.name = name; this.onmessage = null; open.push(this); }
        postMessage(data) {
            for (const other of open) {
                if (other !== this && other.name === this.name) {
                    queueMicrotask(() => other.onmessage?.({ data }));
                }
            }
        }
        close() { open.splice(open.indexOf(this), 1); }
    };
}

// ── Network ──────────────────────────────────────────────────────────────────

test('network: a public (server-reflexive) route means direct UDP audio can work', async () => {
    const PC = fakePeerConnection([host, srflx(50001), srflx(50001)]);

    const result = await checkNetwork({ PeerConnection: PC, iceServers: [{ urls: 'stun:x' }] });

    assert.equal(result.counts.srflx, 2);
    assert.equal(result.udpRoute, true);
    assert.equal(result.symmetricNat, false);
    assert.equal(PC.last.closed, true, 'the probe connection must always be closed');
});

test('network: a different public port per STUN server is a symmetric NAT', async () => {
    // Same local port mapped to two different public ports = the NAT picks a new
    // mapping per destination, which breaks direct media to Africa\'s Talking.
    const PC = fakePeerConnection([host, srflx(50001), srflx(50002)]);

    const result = await checkNetwork({ PeerConnection: PC, iceServers: [] });

    assert.equal(result.udpRoute, true);
    assert.equal(result.symmetricNat, true);
});

test('network: no server-reflexive candidate means UDP is blocked', async () => {
    const PC = fakePeerConnection([host]);

    const result = await checkNetwork({ PeerConnection: PC, iceServers: [] });

    assert.equal(result.udpRoute, false);
    assert.equal(result.counts.host, 1);
});

test('network: a relay candidate is reported when a TURN server answered', async () => {
    const result = await checkNetwork({ PeerConnection: fakePeerConnection([host, srflx(50001), relay]), iceServers: [] });

    assert.equal(result.counts.relay, 1);
});

test('network: gives up after the timeout and still reports what it found', async () => {
    const PC = fakePeerConnection([host], { complete: false });

    const result = await checkNetwork({ PeerConnection: PC, iceServers: [], timeoutMs: 30 });

    assert.equal(result.timedOut, true);
    assert.equal(result.counts.host, 1);
    assert.equal(PC.last.closed, true);
});

test('network: parses the type from the candidate string where .type is missing', async () => {
    const legacy = { candidate: 'candidate:1 1 udp 1677729535 41.1.2.3 50001 typ srflx raddr 10.0.0.2 rport 40000' };

    const result = await checkNetwork({ PeerConnection: fakePeerConnection([legacy]), iceServers: [] });

    assert.equal(result.counts.srflx, 1);
});

test('network: reports unsupported browsers rather than throwing', async () => {
    const result = await checkNetwork({ PeerConnection: undefined, iceServers: [] });

    assert.equal(result.supported, false);
});

// ── STUN reachability (is each test server answering at all?) ────────────────

/** A PC whose single STUN server answers only if its url is in `answering`. */
function fakeStunAwarePC(answering) {
    return class FakePC {
        constructor(config) { this.url = config.iceServers?.[0]?.urls; this.closed = false; FakePC.made.push(this); }
        createDataChannel() {}
        async createOffer() { return { type: 'offer' }; }
        async setLocalDescription() {
            queueMicrotask(() => {
                if (answering.includes(this.url)) this.onicecandidate?.({ candidate: srflx(50001) });
                else this.onicecandidate?.({ candidate: null }); // gathering finished, no srflx: it was silent
            });
        }
        close() { this.closed = true; }
    };
}

test('stun: a server that yields a server-reflexive candidate is reachable', async () => {
    const PC = fakeStunAwarePC(['stun:a']);
    PC.made = [];

    assert.equal(await checkStunReachable({ PeerConnection: PC, url: 'stun:a' }), true);
    assert.equal(await checkStunReachable({ PeerConnection: PC, url: 'stun:b' }), false);
    assert.ok(PC.made.every((pc) => pc.closed), 'every probe connection is closed');
});

test('stun: a silent server is reported unreachable after the timeout instead of hanging', async () => {
    class Hung {
        createDataChannel() {}
        async createOffer() { return {}; }
        async setLocalDescription() {} // never yields a candidate nor completes
        close() { this.closed = true; }
    }

    assert.equal(await checkStunReachable({ PeerConnection: Hung, url: 'stun:x', timeoutMs: 30 }), false);
});

test('network: counts how many of the listed STUN servers answered', async () => {
    const PC = fakeStunAwarePC(['stun:a', 'stun:c']);
    PC.made = [];

    const result = await checkNetwork({
        PeerConnection: PC,
        iceServers: [{ urls: 'stun:a' }, { urls: 'stun:b' }, { urls: 'stun:c' }],
    });

    assert.equal(result.serversAnswered, 2);
});

test('network: serversAnswered is 0 when there are no STUN servers to ask', async () => {
    const result = await checkNetwork({ PeerConnection: fakePeerConnection([host]), iceServers: [] });

    assert.equal(result.serversAnswered, 0);
});

// ── Softphone ────────────────────────────────────────────────────────────────

test('softphone: reads registration state and the client name from the page', () => {
    const win = {
        bqVoiceClient: { ready: true },
        document: { querySelector: (sel) => (sel.includes('at-client-name') ? { content: 'agent_1' } : sel.includes('at-voice-token') ? { content: 'tok' } : null) },
    };

    assert.deepEqual(checkSoftphone(win), { registered: true, hasToken: true, clientName: 'agent_1' });
});

test('softphone: not registered and no token when voice is not set up', () => {
    const win = { document: { querySelector: () => null } };

    assert.deepEqual(checkSoftphone(win), { registered: false, hasToken: false, clientName: null });
});

// ── Other tabs ───────────────────────────────────────────────────────────────

test('tabs: counts OTHER open tabs and ignores itself', async () => {
    const BC = broadcastChannelHub();
    const stopA = startTabPresence({ BroadcastChannel: BC, tabId: 'tab-a' });
    const stopB = startTabPresence({ BroadcastChannel: BC, tabId: 'tab-b' });
    const stopSelf = startTabPresence({ BroadcastChannel: BC, tabId: 'tab-me' }); // this page's own responder

    const others = await countOtherTabs({ BroadcastChannel: BC, tabId: 'tab-me', waitMs: 20 });

    assert.equal(others, 2);
    [stopA, stopB, stopSelf].forEach((stop) => stop());
});

test('tabs: zero when this is the only tab, and safe where BroadcastChannel is missing', async () => {
    const BC = broadcastChannelHub();
    assert.equal(await countOtherTabs({ BroadcastChannel: BC, tabId: 'only', waitMs: 10 }), 0);
    assert.equal(await countOtherTabs({ BroadcastChannel: undefined, tabId: 'only', waitMs: 10 }), null);
});

test('tabs: a closed tab stops answering', async () => {
    const BC = broadcastChannelHub();
    const stop = startTabPresence({ BroadcastChannel: BC, tabId: 'gone' });
    stop();

    assert.equal(await countOtherTabs({ BroadcastChannel: BC, tabId: 'me', waitMs: 10 }), 0);
});

// ── Verdict ──────────────────────────────────────────────────────────────────

const healthy = () => ({
    secureContext: true,
    microphone: { ok: true, message: '', deviceCount: 1 },
    softphone: { registered: true, hasToken: true, clientName: 'agent_1' },
    network: { supported: true, udpRoute: true, symmetricNat: false, timedOut: false, serversAnswered: 2, counts: { host: 1, srflx: 1, relay: 0, prflx: 0 }, turnConfigured: false },
    otherTabs: 0,
});

const levels = (results) => interpretResults(results).map((v) => v.level);

test('verdict: only says the route is confirmed when two test servers were reachable', () => {
    const confirmed = healthy(); // serversAnswered: 2, one merged candidate = same public address from both
    confirmed.network = { ...confirmed.network, counts: { host: 6, srflx: 1, relay: 0, prflx: 0 } };
    const unverified = healthy();
    unverified.network = { ...unverified.network, serversAnswered: 1, counts: { host: 6, srflx: 1, relay: 0, prflx: 0 } };

    const detailOf = (r) => interpretResults(r).find((f) => f.title === 'Network route found').detail;

    // Chrome merges identical candidates, so ONE srflx from two answering servers means
    // both saw the same address: a consistent (non-symmetric) NAT.
    assert.match(detailOf(confirmed), /confirmed/i);
    assert.match(detailOf(unverified), /cannot be ruled out/i);
    assert.doesNotMatch(detailOf(unverified), /confirmed/i);
});

test('verdict: a healthy setup has no problems', () => {
    assert.ok(!levels(healthy()).includes('fail'));
    assert.ok(!levels(healthy()).includes('warn'));
});

test('verdict: a blocked microphone is a failure with the browser instructions', () => {
    const r = healthy();
    r.microphone = { ok: false, kind: 'denied', message: 'The microphone is blocked for this site. Click the padlock.', deviceCount: 1 };

    const v = interpretResults(r).find((x) => x.level === 'fail');

    assert.ok(v);
    assert.match(v.title, /microphone/i);
    assert.match(v.detail, /padlock/i);
});

test('verdict: not registered is a failure', () => {
    const r = healthy();
    r.softphone = { registered: false, hasToken: true, clientName: 'agent_1' };

    assert.ok(levels(r).includes('fail'));
});

test('verdict: no UDP route says a relay (TURN) is needed', () => {
    const r = healthy();
    r.network = { ...r.network, udpRoute: false, counts: { host: 1, srflx: 0, relay: 0, prflx: 0 } };

    const v = interpretResults(r).find((x) => x.level === 'fail' && /network|audio/i.test(x.title));

    assert.ok(v);
    assert.match(v.detail, /TURN|relay/i);
});

test('verdict: a symmetric NAT is flagged as a warning with the cause named', () => {
    const r = healthy();
    r.network = { ...r.network, symmetricNat: true };

    const v = interpretResults(r).find((x) => x.level === 'warn');

    assert.ok(v);
    assert.match(v.detail, /symmetric|hotspot|mobile/i);
});

test('verdict: other open tabs are a warning because they compete for the call', () => {
    const r = healthy();
    r.otherTabs = 2;

    const v = interpretResults(r).find((x) => x.level === 'warn');

    assert.ok(v);
    assert.match(v.detail, /2/);
    assert.match(v.detail, /one tab|only one|close/i);
});

test('verdict: an insecure page is a failure before anything else', () => {
    const r = healthy();
    r.secureContext = false;

    assert.ok(levels(r).includes('fail'));
});

// ── Report ───────────────────────────────────────────────────────────────────

test('report: plain text that a person can paste to support, with no secrets', () => {
    const r = healthy();
    r.toneHeard = true;

    const text = buildReport(r, { userAgent: 'TestBrowser/1.0', now: '2026-10-16T12:00:00Z' });

    assert.match(text, /BlastIQ voice check/);
    assert.match(text, /TestBrowser\/1\.0/);
    assert.match(text, /microphone/i);
    assert.match(text, /srflx/i);
    assert.doesNotMatch(text, /token|apikey|password/i);
});
