/**
 * "Check my voice setup": answers, from the agent's OWN browser and network, the
 * questions that decide whether a call can be heard, which no server-side test can:
 *
 *   1. Is the microphone allowed and working?
 *   2. Is the softphone registered with Africa's Talking?
 *   3. Can this network carry call audio? (ICE candidate gathering: a direct UDP route,
 *      a symmetric NAT, or a blocked network that needs a TURN relay.)
 *   4. Are other BlastIQ tabs open here that would compete for the call?
 *   5. Can the agent hear sound through the same path call audio takes?
 *
 * Every step is a small function with injectable browser objects so the logic is
 * unit-tested (`npm run test:js`); the Alpine component at the bottom only sequences
 * them and renders the result.
 */

import { createMicPreflight, describeMicError } from './mic-preflight.js';
import { TAB_CHANNEL } from './tab-presence.js';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// ── 1. Microphone ────────────────────────────────────────────────────────────

/**
 * @param {Navigator} nav injectable for tests
 * @returns {Promise<{ok: boolean, kind?: string, message: string, deviceCount: number|null}>}
 */
export async function checkMicrophone(nav) {
    try {
        await createMicPreflight(nav)();
    } catch (error) {
        return { ok: false, kind: error?.kind ?? 'failed', message: describeMicError(error), deviceCount: null };
    }

    let deviceCount = null;
    try {
        const devices = await nav.mediaDevices.enumerateDevices();
        deviceCount = devices.filter((device) => device.kind === 'audioinput').length;
    } catch {
        // Device listing is informational only.
    }

    return { ok: true, message: '', deviceCount };
}

// ── 2. Softphone registration ────────────────────────────────────────────────

export function checkSoftphone(win) {
    const meta = (name) => win.document?.querySelector?.(`meta[name="${name}"]`)?.content ?? null;

    return {
        registered: !!win.bqVoiceClient?.ready,
        hasToken: !!meta('at-voice-token'),
        clientName: meta('at-client-name'),
    };
}

// ── 3. Network route for call audio ──────────────────────────────────────────

function candidateField(candidate, field) {
    if (candidate[field] !== undefined && candidate[field] !== null) {
        return candidate[field];
    }

    // Older browsers only expose the SDP line:
    // candidate:<f> <comp> <proto> <prio> <addr> <port> typ <type> raddr <a> rport <p>
    const parts = String(candidate.candidate ?? '').split(' ');
    switch (field) {
        case 'type': return parts[parts.indexOf('typ') + 1];
        case 'port': return Number(parts[5]);
        case 'relatedPort': return parts.includes('rport') ? Number(parts[parts.indexOf('rport') + 1]) : undefined;
        default: return undefined;
    }
}

/**
 * Does ONE STUN server answer from this network? Asked on its own connection, so a silent
 * server cannot be mistaken for an agreeing one (see checkNetwork).
 */
export async function checkStunReachable({ PeerConnection, url, timeoutMs = 3500 }) {
    let timer;
    const pc = new PeerConnection({ iceServers: [{ urls: url }] });

    try {
        pc.createDataChannel('probe');

        const answered = new Promise((resolve) => {
            pc.onicecandidate = (event) => {
                const candidate = event?.candidate;
                if (!candidate) {
                    resolve(false); // gathering finished without a server-reflexive candidate
                } else if (candidateField(candidate, 'type') === 'srflx') {
                    resolve(true);
                }
            };
        });
        const timeout = new Promise((resolve) => {
            timer = setTimeout(() => resolve(false), timeoutMs);
        });

        await pc.setLocalDescription(await pc.createOffer());

        return await Promise.race([answered, timeout]);
    } finally {
        clearTimeout(timer);
        try { pc.close(); } catch { /* already closed */ }
    }
}

/**
 * Gather ICE candidates exactly like a call would and classify what the network allows.
 *
 *  - srflx/prflx present  = this network has a UDP route to the internet (STUN works).
 *  - same local port mapped to DIFFERENT public ports by two STUN servers = symmetric
 *    NAT, where the router picks a new mapping per destination, which breaks direct
 *    audio (typical of mobile data, phone hotspots and some offices).
 *  - only host candidates = UDP is blocked; audio needs a TURN relay over TCP/TLS.
 */
export async function checkNetwork({ PeerConnection, iceServers, timeoutMs = 7000 }) {
    const counts = { host: 0, srflx: 0, prflx: 0, relay: 0 };

    if (typeof PeerConnection !== 'function') {
        return { supported: false, udpRoute: false, symmetricNat: false, timedOut: false, serversAnswered: 0, counts };
    }

    // Which of the listed STUN servers answer AT ALL, each asked on its own connection.
    // Needed because Chrome merges identical candidates: two servers that both see the same
    // public address produce ONE srflx candidate, indistinguishable from "the second server
    // was silent". Only when two servers answered does a single merged candidate prove the
    // router uses one consistent public address (i.e. is NOT a symmetric NAT).
    const stunUrls = (iceServers ?? [])
        .flatMap((server) => [].concat(server.urls ?? []))
        .filter((url) => /^stuns?:/i.test(url));
    const reachability = Promise.all(stunUrls.map((url) => checkStunReachable({ PeerConnection, url }).catch(() => false)));

    const mappedPorts = new Map(); // local (related) port -> set of public ports seen
    let timedOut = false;
    let timer;
    const pc = new PeerConnection({ iceServers });

    try {
        pc.createDataChannel('probe'); // gathering needs a media section; a data channel needs no microphone

        const complete = new Promise((resolve) => {
            pc.onicecandidate = (event) => {
                const candidate = event?.candidate;
                if (!candidate) {
                    resolve('complete');
                    return;
                }

                const type = candidateField(candidate, 'type');
                if (type in counts) {
                    counts[type] += 1;
                }
                if (type === 'srflx') {
                    const local = candidateField(candidate, 'relatedPort');
                    const publicPort = candidateField(candidate, 'port');
                    if (local !== undefined && publicPort !== undefined) {
                        mappedPorts.set(local, (mappedPorts.get(local) ?? new Set()).add(publicPort));
                    }
                }
            };
        });
        const timeout = new Promise((resolve) => {
            timer = setTimeout(() => resolve('timeout'), timeoutMs);
        });

        await pc.setLocalDescription(await pc.createOffer());
        timedOut = (await Promise.race([complete, timeout])) === 'timeout';
    } finally {
        clearTimeout(timer);
        try { pc.close(); } catch { /* already closed */ }
    }

    const serversAnswered = (await reachability).filter(Boolean).length;

    return {
        supported: true,
        udpRoute: counts.srflx + counts.prflx > 0,
        symmetricNat: [...mappedPorts.values()].some((ports) => ports.size > 1),
        timedOut,
        serversAnswered,
        counts,
    };
}

// ── 4. Other tabs ────────────────────────────────────────────────────────────

/** @returns {Promise<number|null>} other BlastIQ tabs in this browser, or null if it cannot be told */
export async function countOtherTabs({ BroadcastChannel: Channel, tabId, waitMs = 400 }) {
    if (typeof Channel !== 'function') {
        return null;
    }

    const channel = new Channel(TAB_CHANNEL);
    const nonce = Math.random().toString(36).slice(2);
    const others = new Set();

    channel.onmessage = (event) => {
        const data = event?.data;
        if (data?.type === 'pong' && data.nonce === nonce && data.from !== tabId) {
            others.add(data.from);
        }
    };
    channel.postMessage({ type: 'ping', nonce, from: tabId });

    await sleep(waitMs);
    channel.close();

    return others.size;
}

// ── 5. Test sound through the call-audio path ────────────────────────────────

/**
 * Play a short tone through a MediaStream into an <audio autoplay> element, the same
 * path remote call audio takes, so hearing it proves the speaker, the output device
 * and the autoplay policy are all fine. Must be called from a click.
 */
export async function playTestTone({ AudioContext: Context, document: doc, durationMs = 1200 }) {
    const context = new Context();
    await context.resume?.();

    const oscillator = context.createOscillator();
    oscillator.frequency.value = 440;
    const gain = context.createGain();
    gain.gain.value = 0.2;
    const destination = context.createMediaStreamDestination();
    oscillator.connect(gain);
    gain.connect(destination);

    const element = doc.createElement('audio');
    element.autoplay = true;
    element.srcObject = destination.stream;
    doc.body.appendChild(element);

    oscillator.start();
    await sleep(durationMs);
    oscillator.stop();
    element.remove();
    await context.close();
}

// ── Verdict + report ─────────────────────────────────────────────────────────

/**
 * Turn raw results into plain-language findings.
 *
 * @returns {Array<{level: 'ok'|'warn'|'fail', title: string, detail: string}>}
 */
export function interpretResults(results) {
    const findings = [];
    const add = (level, title, detail = '') => findings.push({ level, title, detail });

    if (!results.secureContext) {
        add('fail', 'This page is not secure',
            'Browsers only allow the microphone on secure (https) pages, so calls cannot work here. Open the site with https://.');
    }

    if (results.microphone?.ok) {
        const count = results.microphone.deviceCount;
        add('ok', 'Microphone works', count ? `${count} microphone${count === 1 ? '' : 's'} found.` : '');
    } else if (results.microphone) {
        add('fail', 'Microphone problem', results.microphone.message);
    }

    const phone = results.softphone;
    if (phone?.registered) {
        add('ok', 'Phone line is connected', phone.clientName ? `Registered as ${phone.clientName}.` : '');
    } else if (phone) {
        add('fail', 'Phone line is not connected',
            phone.hasToken
                ? 'The softphone has not registered with Africa’s Talking. Reload the page and check again.'
                : 'Voice calling is not set up for this account (no voice credentials were issued). An admin needs to check Settings → Voice.');
    }

    const net = results.network;
    if (net && net.supported === false) {
        add('warn', 'Network check not available', 'This browser cannot run the route test.');
    } else if (net && !net.udpRoute) {
        add('fail', 'Your network may block call audio',
            'No route to the internet over UDP was found, which is what call audio uses. A firewall, VPN or phone hotspot is probably blocking it. '
            + 'Calls usually connect but stay silent here; a TURN relay (over TCP/TLS) on the server is the fix.'
            + (net.timedOut ? ' (The test timed out.)' : ''));
    } else if (net?.symmetricNat) {
        add('warn', 'Your network may break call audio',
            'Your router assigns a different public address for each destination (a symmetric NAT). This is common on mobile data, phone hotspots and some offices, and call audio often fails across it without a TURN relay. '
            + 'If calls here connect but are silent, this is why.');
    } else if (net) {
        // A symmetric NAT shows up as TWO different public ports for one local port. With a
        // normal router both test servers see the same address and Chrome merges them into
        // one candidate, so a clean result is only proven when at least two servers answered.
        const confirmed = (net.serversAnswered ?? 0) >= 2;
        add('ok', 'Network route found',
            (net.counts?.relay > 0 ? 'A relay server also answered. ' : '')
            + (confirmed
                ? 'A direct route to the internet is confirmed: two independent test servers saw the same public address, so this is not a symmetric NAT.'
                : 'A route to the internet was found, but fewer than two test servers answered, so a symmetric NAT cannot be ruled out. If calls here connect but are silent, run the check again or ask for a TURN relay.'));
    }

    if (typeof results.otherTabs === 'number' && results.otherTabs > 0) {
        const n = results.otherTabs;
        add('warn', `${n} other BlastIQ tab${n === 1 ? ' is' : 's are'} open`,
            `You have ${n} other BlastIQ tab${n === 1 ? '' : 's'} open in this browser. Only one tab should be open: Africa’s Talking delivers an incoming call to just one of them, so a forgotten tab can take the call while the one you are looking at never rings. Close the others. `
            + 'The same login open on another browser or device counts too, and cannot be detected from here.');
    }

    if (results.toneHeard === false) {
        add('fail', 'You did not hear the test sound',
            'Check the speaker volume, that the right output device is selected (headset versus speakers), and that this tab is not muted.');
    } else if (results.toneHeard === true) {
        add('ok', 'Sound works');
    }

    return findings;
}

/** Plain text a person can paste to support. Contains nothing secret. */
export function buildReport(results, { userAgent = '', now = '' } = {}) {
    const yesNo = (value) => (value ? 'yes' : 'no');
    const mic = results.microphone;
    const phone = results.softphone;
    const net = results.network;
    const counts = net?.counts ?? {};

    const lines = [
        `BlastIQ voice check ${now}`.trim(),
        `Browser: ${userAgent}`,
        `Secure page (https): ${yesNo(results.secureContext)}`,
        `Microphone: ${mic?.ok ? `OK (${mic.deviceCount ?? '?'} input device(s))` : `PROBLEM (${mic?.kind ?? 'unknown'}): ${mic?.message ?? ''}`}`,
        `Phone line: ${phone?.registered ? `registered as ${phone.clientName ?? '?'}` : 'NOT registered'}`,
        `Network: UDP route ${yesNo(net?.udpRoute)}; candidates host=${counts.host ?? 0} srflx=${counts.srflx ?? 0} prflx=${counts.prflx ?? 0} relay=${counts.relay ?? 0}; symmetric NAT ${yesNo(net?.symmetricNat)}; timed out ${yesNo(net?.timedOut)}; TURN relay configured on server ${yesNo(net?.turnConfigured)}`,
        `Other BlastIQ tabs in this browser: ${results.otherTabs ?? 'unknown'}`,
        `Test sound heard: ${results.toneHeard === undefined || results.toneHeard === null ? 'not tested' : yesNo(results.toneHeard)}`,
        '',
        'Findings:',
        ...interpretResults(results).map((f) => `- [${f.level.toUpperCase()}] ${f.title}${f.detail ? `: ${f.detail}` : ''}`),
    ];

    return lines.join('\n');
}

// ── Alpine component ─────────────────────────────────────────────────────────

/** Extra public STUN servers, used ONLY by the probe, from two different providers, so a symmetric NAT shows up as differing addresses. */
const PROBE_EXTRA_STUN = ['stun:stun1.l.google.com:19302', 'stun:stun.cloudflare.com:3478'];

function configuredIceServers(doc) {
    try {
        const parsed = JSON.parse(doc.querySelector('meta[name=bq-ice-servers]')?.getAttribute('content') ?? '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

const hasTurn = (servers) => servers.some((server) => [].concat(server.urls ?? []).some((url) => /^turns?:/i.test(url)));

export const voiceCheck = () => ({
    open: false,
    running: false,
    results: {},
    findings: [],
    stepNote: '',
    tonePlaying: false,
    toneAsked: false,
    copied: false,

    async start() {
        this.open = true;
        await this.run();
    },

    async run() {
        if (this.running) return;
        this.running = true;
        this.copied = false;
        this.toneAsked = false;
        this.findings = [];
        const results = { secureContext: window.isSecureContext !== false, toneHeard: null };

        // The probe builds a throwaway RTCPeerConnection; the call-quality hook in
        // outbound-call.js remembers the last one created, so put its value back.
        const previousPeer = window.__bqLastPeerConnection;

        try {
            this.stepNote = 'Checking the microphone… (allow it if the browser asks)';
            results.microphone = await checkMicrophone(navigator);
            this.results = { ...results };

            this.stepNote = 'Checking the phone line…';
            results.softphone = checkSoftphone(window);

            this.stepNote = 'Testing your network route for call audio… (a few seconds)';
            const configured = configuredIceServers(document);
            const alreadyListed = (url) => configured.some((s) => [].concat(s.urls ?? []).includes(url));
            const probeServers = [...configured, ...PROBE_EXTRA_STUN.filter((url) => !alreadyListed(url)).map((urls) => ({ urls }))];
            results.network = {
                ...(await checkNetwork({ PeerConnection: window.RTCPeerConnection, iceServers: probeServers })),
                turnConfigured: hasTurn(configured),
            };

            this.stepNote = 'Looking for other open tabs…';
            window.__bqTabId ??= Math.random().toString(36).slice(2);
            results.otherTabs = await countOtherTabs({ BroadcastChannel: window.BroadcastChannel, tabId: window.__bqTabId });
        } finally {
            window.__bqLastPeerConnection = previousPeer;
            this.results = { ...results };
            this.findings = interpretResults(this.results);
            this.stepNote = '';
            this.running = false;
        }
    },

    async playTone() {
        if (this.tonePlaying) return;
        this.tonePlaying = true;
        try {
            await playTestTone({ AudioContext: window.AudioContext ?? window.webkitAudioContext, document });
            this.toneAsked = true;
        } catch {
            this.answerTone(false);
        } finally {
            this.tonePlaying = false;
        }
    },

    answerTone(heard) {
        this.results = { ...this.results, toneHeard: heard };
        this.findings = interpretResults(this.results);
        this.toneAsked = false;
    },

    get worst() {
        if (this.findings.some((f) => f.level === 'fail')) return 'fail';
        if (this.findings.some((f) => f.level === 'warn')) return 'warn';
        return this.findings.length ? 'ok' : 'idle';
    },

    async copyReport() {
        const text = buildReport(this.results, { userAgent: navigator.userAgent, now: new Date().toISOString() });
        try {
            await navigator.clipboard.writeText(text);
            this.copied = true;
        } catch {
            window.prompt('Copy this report:', text);
        }
    },
});

// Registered for the Blade view's x-data="voiceCheck()"; skipped outside a browser (unit tests).
if (typeof window !== 'undefined') {
    window.voiceCheck = voiceCheck;
}
