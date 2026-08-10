// Phase 18 (rewritten) — Africa's Talking WebRTC softphone.
//
// This file owns three things:
//   1. window.bqVoiceClient — a PERSISTENT softphone, registered once per page
//      from the capability token in the layout's <meta name="at-voice-token">.
//      It must be online BEFORE a call arrives, otherwise AT's <Dial> to this
//      agent's client races page setup and the caller hears silence.
//   2. window.outgoingCall — Alpine factory for the outbound banner. The server
//      dials the customer (REST), AT bridges the answered call to this client as
//      an `incomingcall`, and since the agent initiated it we auto-answer.
//   3. window.incomingAtCall — Alpine factory for the inbound banner. The human
//      clicks Accept; we answer the AT leg the webhook <Dial>ed to this client.
//
// API NOTE: verified against africastalking-client@1.0.7's documented surface —
//   new Africastalking.Client(token)
//   events: ready | notready | calling | incomingcall | callaccepted | hangup | offline | closed
//   methods: call(number) | answer() | hangup() | dtmf(d) | muteAudio() | unmuteAudio() | hold() | unhold()
// The package is npm-deprecated; if you swap to AT's current vendor SDK, the
// only surface that must keep working is the small set bqVoiceClient.boot() wires.
//
// RUNTIME NOTE: africastalking-client@1.0.7 has a module-cache state-leak bug
// where a static `import` retains broken WebSocket state across reconnect
// attempts, creating a zombie client that never emits `ready`. To avoid this
// the SDK is NOT imported via npm — it is loaded from the SELF-HOSTED script in
// layouts/app.blade.php (vendor/africastalking-1.0.7.js), keeping the CSP
// same-origin and the supply chain audited. On reconnect the script is removed
// and re-loaded with a cache-busting query param so the module cache is bypassed.

import { startStatsCollection, postQuality } from './call-stats-collector';
import { createCallStateMixin } from './call-state-mixin';
import { iceServers } from './ice-servers';

// Reuse the already-loaded self-hosted SDK's URL for reloads (never an external
// CDN — same-origin CSP + pinned/audited artifact). The layout's <script> tag
// carries data-at-sdk; a page without it still resolves to the canonical path.
const AT_SDK_SRC = (() => {
    const existing = document.querySelector('script[data-at-sdk]')?.getAttribute('src');
    return existing || '/vendor/africastalking-1.0.7.js';
})();

/** Load the AT WebRTC SDK from the self-hosted script (first load) or
 *  force-reload it (reconnect). Resolves true when the SDK is present. */
function loadAtSdk(forceReload = false) {
    if (!forceReload && typeof window.Africastalking?.Client !== 'undefined') {
        return Promise.resolve(true);
    }
    document.querySelectorAll('script[data-at-sdk]').forEach(s => s.remove());
    delete window.Africastalking;
    return new Promise((resolve) => {
        const s = document.createElement('script');
        s.src = `${AT_SDK_SRC}?t=${Date.now()}`;
        s.async = true;
        s.dataset.atSdk = '';
        const timer = setTimeout(() => { s.remove(); resolve(false); }, 15000);
        s.onload = () => { clearTimeout(timer); setTimeout(() => resolve(true), 100); };
        s.onerror = () => { clearTimeout(timer); s.remove(); resolve(false); };
        document.head.appendChild(s);
    });
}

// ─── Persistent softphone singleton ─────────────────────────────────────
// One registered client per page. Per-call Alpine banners register themselves
// so SDK events route to the right call. Because the UI can show more than one
// banner at once (e.g. an outbound call up while an inbound rings), routing
// matches `incomingcall` to the banner expecting that caller's number instead
// of blindly handing it to whichever banner attached last.
window.bqVoiceClient = {
    client: null,
    ready: false,
    _booted: false,
    // All attached banners, oldest first. The most recent is the "primary".
    _banners: [],
    // Buffer the most recent incoming call from AT. The banner that handles
    // it (outbound auto-answer / inbound accept) is mounted by a 3s Livewire
    // poll, so AT's `incomingcall` event can arrive BEFORE the banner has
    // attached. We stash it and replay it on attach() so the call is never
    // silently dropped.
    _lastIncoming: null,

    // ── Reconnect watchdog ──────────────────────────────────────────────
    // africastalking-client@1.0.7 NEVER emits offline/closed/error (verified in
    // the vendored bundle), so its documented auto-reconnect was dead code and a
    // dropped registration left the softphone `ready === true` yet deaf until a
    // manual page reload. We bridge that gap ourselves: watch the SDK's own
    // signalling WebSocket for close/error, time out a registration that never
    // reaches `ready`, and re-check on tab refocus / network-online. Bounded so a
    // genuinely-down provider doesn't hot-loop; the budget resets on a healthy
    // `ready` or a user refocus.
    _socket: null,
    _socketHandlers: null,
    _readyWatch: null,
    _rebooting: false,
    _rebootAttempts: 0,
    _maxRebootAttempts: 8,
    _readyTimeoutMs: 15000,
    _rebootDelayMs: 5000,

    _primaryBanner() {
        return this._banners[this._banners.length - 1] ?? null;
    },

    /**
     * Route an AT `incomingcall` to the banner that is expecting this caller.
     * The SDK payload doesn't expose a stable call id across versions, but it
     * does carry the far-party number — match that against each banner's
     * phone (inbound) / customerPhone (outbound). Falls back to the primary
     * banner when no number can be derived.
     */
    _routeIncoming(params) {
        const phone = String(
            params?.phoneNumber ?? params?.from ?? params?.callerNumber ?? params?.from_number ?? ''
        ).replace(/\D/g, '');
        if (phone) {
            const expecting = this._banners.find((b) => {
                const want = String(b.phone ?? b.customerPhone ?? '').replace(/\D/g, '');
                return want !== '' && want === phone;
            });
            if (expecting) {
                expecting.onIncoming?.(params);
                return;
            }
        }
        this._primaryBanner()?.onIncoming?.(params);
    },

    /** Register the WebRTC client with a real AT capability token. Idempotent. */
    boot(token) {
        if (this._booted || this._rebooting || !token) return;
        if (typeof window.Africastalking === 'undefined' || !window.Africastalking.Client) {
            console.error('[BQ Voice] africastalking-client SDK not available (blocked or failed to load).');
            return;
        }
        // The SDK opens its signalling WebSocket synchronously inside the
        // constructor. Capture that socket so we can watch it for close/error —
        // the drop signals the SDK itself never surfaces (see _onSocketDrop).
        const restoreWs = this._captureSocketDuringConstruct();
        try {
            // Pass ICE servers (STUN + optional TURN) best-effort. africastalking-
            // client@1.0.7's constructor is token-first; some builds read a second
            // options arg for iceServers, others ignore it. Harmless either way —
            // if honoured, restrictive-NAT callers get a TURN relay.
            this.client = new window.Africastalking.Client(token, { iceServers: iceServers() });
        } catch (e) {
            restoreWs();
            console.error('[BQ Voice] failed to construct AT client', e);
            // Don't leave the softphone permanently dead — retry via the bounded
            // reboot path.
            this._scheduleReboot();
            return;
        }
        this._attachSocket(restoreWs());
        this._booted = true;
        // If registration never reaches `ready` (bad token, blocked WS, silent AT
        // failure) the SDK gives us no error at all — time it out and reboot.
        this._armReadyWatch();

        const c = this.client;
        const on = (evt, fn) => {
            try { c.on(evt, fn, false); } catch (e) { console.warn('[BQ Voice] on() failed for', evt, e); }
        };

        on('ready', () => {
            this.ready = true;
            this._rebootAttempts = 0; // a healthy registration resets the budget
            this._clearReadyWatch();
        });
        on('notready', () => { this.ready = false; this._scheduleReboot(); });
        // 1.0.7 never emits offline/closed, but a maintained SDK would — keep the
        // handlers so a future swap self-heals; the socket watchdog covers 1.0.7.
        on('offline', () => { this.ready = false; this._scheduleReboot(); });
        on('closed', () => { this.ready = false; this._scheduleReboot(); });
        on('calling', () => {});
        // Route call lifecycle to the banner that's expecting this call.
        on('incomingcall', (params) => {
            this._lastIncoming = params;
            this._routeIncoming(params);
        });
        on('callaccepted', () => { this._primaryBanner()?.onAccepted?.(); });
        on('hangup', (cause) => { this._primaryBanner()?.onHangup?.(cause); });
        on('error', (err) => { console.error('[BQ Voice] SDK error', err); this._primaryBanner()?.onError?.(err); });
    },

    /** Wrap window.WebSocket for the SYNCHRONOUS span of the SDK constructor so
     *  we grab the signalling socket it opens. Returns restore(), which puts the
     *  global back and yields the captured socket (or null). A Proxy preserves
     *  statics (WebSocket.OPEN), the prototype, and instanceof for the SDK, and
     *  we only match africastalking URLs so Reverb/Echo's socket is untouched. */
    _captureSocketDuringConstruct() {
        const Original = window.WebSocket;
        if (typeof Original !== 'function' || typeof Proxy === 'undefined') {
            return () => null;
        }
        let captured = null;
        try {
            window.WebSocket = new Proxy(Original, {
                construct(target, args) {
                    const ws = Reflect.construct(target, args);
                    try {
                        if (/africastalking/i.test(String(args?.[0] ?? ''))) captured = ws;
                    } catch (_) { /* ignore */ }
                    return ws;
                },
            });
        } catch (_) {
            window.WebSocket = Original;
            return () => null;
        }
        return () => { window.WebSocket = Original; return captured; };
    },

    _attachSocket(ws) {
        this._detachSocket();
        if (!ws || typeof ws.addEventListener !== 'function') return;
        const onDrop = () => this._onSocketDrop();
        this._socket = ws;
        this._socketHandlers = onDrop;
        try {
            ws.addEventListener('close', onDrop);
            ws.addEventListener('error', onDrop);
        } catch (_) { /* ignore */ }
    },

    _detachSocket() {
        if (this._socket && this._socketHandlers) {
            try {
                this._socket.removeEventListener('close', this._socketHandlers);
                this._socket.removeEventListener('error', this._socketHandlers);
            } catch (_) { /* ignore */ }
        }
        this._socket = null;
        this._socketHandlers = null;
    },

    /** The SDK's signalling socket dropped — the event 1.0.7 never surfaces.
     *  Only react while we believe we're live, then let the bounded reboot heal
     *  it. Without this the softphone sits `ready === true` yet deaf. */
    _onSocketDrop() {
        if (!this._booted && !this.ready) return;
        console.warn('[BQ Voice] signalling socket dropped; reconnecting softphone.');
        this._scheduleReboot();
    },

    _armReadyWatch() {
        this._clearReadyWatch();
        this._readyWatch = setTimeout(() => {
            this._readyWatch = null;
            if (!this.ready) {
                console.warn('[BQ Voice] registration did not reach ready; rebooting softphone.');
                this._scheduleReboot();
            }
        }, this._readyTimeoutMs);
    },

    _clearReadyWatch() {
        if (this._readyWatch) { clearTimeout(this._readyWatch); this._readyWatch = null; }
    },

    /** Single-shot reboot: tear down, force-reload the SDK (bypassing its buggy
     *  module cache), wait, then re-boot. Bounded by _maxRebootAttempts so a
     *  genuinely-down provider can't hot-loop; the counter resets on a healthy
     *  `ready` or a tab refocus. boot() re-arms the ready watchdog, so a reboot
     *  that still doesn't register re-enters here until the budget is spent. */
    async _scheduleReboot() {
        if (this._rebooting) return;
        if (this._rebootAttempts >= this._maxRebootAttempts) {
            console.warn('[BQ Voice] reconnect budget exhausted; will retry on tab refocus or page reload.');
            return;
        }
        this._rebooting = true;
        this._rebootAttempts++;
        this._clearReadyWatch();
        this.ready = false;
        this._booted = false;
        this._detachSocket();
        try { this.client?.hangup(); } catch { /* ignore */ }
        this.client = null;
        // Banners stay registered so an event arriving right after the client
        // comes back isn't dropped; a stale buffered call must NOT be replayed.
        this._lastIncoming = null;

        await new Promise(r => setTimeout(r, this._rebootDelayMs));
        this._rebooting = false;

        const token = document.querySelector('meta[name="at-voice-token"]')?.getAttribute('content');
        if (!token) return;

        const ok = await loadAtSdk(true);
        if (ok) this.boot(token);    // re-arms the ready watch; a failure re-enters here
        else this._scheduleReboot(); // SDK reload failed — try again (bounded)
    },

    attach(banner) {
        this._banners.push(banner);
        // Replay a call that reached us before this banner attached (the
        // poll that mounts the banner can lag AT's incomingcall event).
        if (this._lastIncoming) {
            const pending = this._lastIncoming;
            this._lastIncoming = null;
            this._routeIncoming(pending);
        }
    },
    detach(banner) {
        this._banners = this._banners.filter(b => b !== banner);
        // A buffered call that outlived its banner is stale — drop it so a
        // future attach doesn't deliver a ghost call.
        this._lastIncoming = null;
    },

    isAvailable() { return !!this.client; },
    answer() { try { this.client?.answer(); } catch (e) { console.warn('[BQ Voice] answer failed', e); } },
    hangupCall() { try { this.client?.hangup(); } catch (e) { console.warn('[BQ Voice] hangup failed', e); } },
    mute() { try { this.client?.muteAudio(); } catch (e) { console.warn('[BQ Voice] mute failed', e); } },
    unmute() { try { this.client?.unmuteAudio(); } catch (e) { console.warn('[BQ Voice] unmute failed', e); } },
    hold() { try { this.client?.hold(); } catch (e) { console.warn('[BQ Voice] hold failed', e); } },
    unhold() { try { this.client?.unhold(); } catch (e) { console.warn('[BQ Voice] unhold failed', e); } },
    dtmf(digit) { try { this.client?.dtmf(String(digit)); } catch (e) { console.warn('[BQ Voice] dtmf failed', e); } },

    /** Access to the underlying RTCPeerConnection for quality telemetry.
     *  1.0.7 keeps the PC as a closure-local and exposes it under none of these
     *  names, so we also fall back to the last RTCPeerConnection the page
     *  constructed (captured globally below). Since the AT SDK is this build's
     *  only WebRTC producer, that last-created PC IS the live call's. */
    peer() {
        const c = this.client;
        const fromClient = c ? (c.peer ?? c.peerConnection ?? c.getPeerConnection?.()) : null;
        return fromClient ?? window.__bqLastPeerConnection ?? null;
    },
};

// ─── Capture the call's RTCPeerConnection for quality telemetry ───────────
// The AT SDK constructs its RTCPeerConnection internally and never exposes it,
// so bqVoiceClient.peer() had nothing to hand the stats collector. Wrap the
// constructor once (Proxy preserves statics/prototype/instanceof) and stash the
// most-recently-created PC; peer() falls back to it. The AT SDK is the only
// WebRTC producer in this build, so "last created" is the active call's PC.
(() => {
    const Original = window.RTCPeerConnection;
    if (typeof Original !== 'function' || typeof Proxy === 'undefined') return;
    try {
        window.RTCPeerConnection = new Proxy(Original, {
            construct(target, args) {
                const pc = Reflect.construct(target, args);
                // Only attribute a PC to the call when the softphone is actually
                // registered — a PC constructed at idle (page load, or a browser
                // extension) is never an AT call PC, so it must not overwrite the
                // telemetry target. The AT SDK builds its PC during answer(),
                // which only runs while ready === true, so the real call PC is
                // still captured.
                if (window.bqVoiceClient?.ready) {
                    window.__bqLastPeerConnection = pc;
                    try {
                        pc.addEventListener('connectionstatechange', () => {
                            if (/closed|failed/.test(pc.connectionState)
                                && window.__bqLastPeerConnection === pc) {
                                window.__bqLastPeerConnection = null; // don't leak a dead PC
                            }
                        });
                    } catch (_) { /* ignore */ }
                }
                return pc;
            },
        });
    } catch (_) { /* leave the native constructor in place */ }
})();

// Boot from the layout-rendered token as soon as the DOM is ready. app.js is a
// deferred module so the <head> meta tags are present, but guard for safety.
function bqBootVoiceFromMeta() {
    const token = document.querySelector('meta[name="at-voice-token"]')?.getAttribute('content');
    if (token) window.bqVoiceClient.boot(token);
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bqBootVoiceFromMeta, { once: true });
} else {
    bqBootVoiceFromMeta();
}

// Re-verify the softphone when the tab regains focus or the network returns.
// Mobile browsers suspend background-tab WebSockets and the SDK won't tell us,
// so a refocus is the natural moment to heal a silently-dropped registration.
// Reset the reboot budget — a user returning to the tab deserves a fresh set of
// attempts.
function bqRevalidateVoice() {
    const vc = window.bqVoiceClient;
    if (!vc || vc._rebooting) return;
    const token = document.querySelector('meta[name="at-voice-token"]')?.getAttribute('content');
    if (!token) return;
    vc._rebootAttempts = 0;
    if (!vc._booted) {
        // Only boot directly when the SDK script is actually present. During an
        // in-flight reboot's reload window, window.Africastalking is briefly
        // deleted; booting then would just hit boot()'s "SDK not available"
        // branch and log a spurious error. Leave it — that reboot's own promise
        // chain re-boots once the reload finishes.
        if (typeof window.Africastalking !== 'undefined') vc.boot(token);
        return;
    }
    if (!vc.ready) vc._scheduleReboot();
}
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') bqRevalidateVoice();
});
window.addEventListener('online', bqRevalidateVoice);

// ─── Outbound (agent dialing customer) ───────────────────────────────────
window.outgoingCall = (data) => ({
    ...data,
    ...createCallStateMixin(),
    state: 'calling',
    muted: false,
    held: false,
    keypadOpen: false,
    _statsHandle: null,
    _echoChannel: null,

    init() {
        const vc = window.bqVoiceClient;
        if (!vc || !vc.isAvailable()) {
            this.state = 'failed';
            this.errorMessage = "Voice softphone not registered. Configure Africa's Talking in Settings, then reload.";
            // The server already dialed the customer and is waiting for our leg
            // to bridge; with no client registered it can never answer, so end
            // the server-side call rather than leaving the customer ringing into
            // a void. Best-effort.
            this.safePost(`/calls/${this.callId}/hangup`, {});
            return;
        }
        vc.attach(this);

        // Server-side terminate (manager kill / natural end) clears the banner.
        if (window.userId && window.Echo) {
            this._echoChannel = window.Echo.private(`user.${window.userId}`);
            this._echoChannel.listen('.call.terminated', (e) => {
                if (e.call_id === this.callId) this.teardown('remote');
            });
        }
    },

    /**
     * Alpine destroy() — frees the Reverb listener and tears down when the
     * banner's DOM is removed. Prevents handler accumulation on re-mounts.
     */
    destroy() {
        if (this._echoChannel) {
            try { this._echoChannel.stopListening('.call.terminated'); } catch (_) {}
            this._echoChannel = null;
        }
        if (this.state !== 'ended' && this.state !== 'failed') {
            this.teardown('component_destroyed');
        }
    },

    // The customer (dialed server-side) answered; AT bridged the call to this
    // client. We initiated it, so answer automatically. The mic-permission
    // prompt fires here on the first call.
    onIncoming() {
        this.state = 'connecting';
        window.bqVoiceClient.answer();
    },
    onAccepted() {
        this.state = 'connected';
        this.startDurationTimer();
        this._tryStats();
        // Best-effort call recording for AI analysis (self-guards on the flag +
        // browser support; no-ops otherwise).
        window.bqCallRecorder?.start(this.callId);
    },
    onHangup() { this.teardown('remote'); },
    onError(err) {
        // Mic permission denied surfaces as an SDK error on the auto-answer
        // path. Give the agent a retry state instead of a dead-end 'failed'.
        const raw = String(err?.message ?? err?.name ?? err ?? '');
        if (this.state === 'connecting' && (/permission|microphone|notallowed/i.test(raw))) {
            this.state = 'mic_denied';
            this.errorMessage = '';
            return;
        }
        this.errorMessage = String(err?.message ?? err ?? 'unknown');
        if (this.state !== 'connected') this.state = 'failed';
    },

    /** Retry answer after the mic prompt was denied (re-prompts unless the
     *  origin is permanently blocked in browser settings). */
    retryAccept() {
        this.errorMessage = '';
        this.state = 'connecting';
        window.bqVoiceClient?.answer();
    },

    _tryStats() {
        const peer = window.bqVoiceClient.peer();
        if (peer) this._statsHandle = startStatsCollection(peer);
    },

    toggleMute() {
        this.muted = !this.muted;
        this.muted ? window.bqVoiceClient.mute() : window.bqVoiceClient.unmute();
    },

    toggleHold() {
        this.held = !this.held;
        this.held ? window.bqVoiceClient.hold() : window.bqVoiceClient.unhold();
    },

    toggleKeypad() { this.keypadOpen = !this.keypadOpen; },

    sendDtmf(digit) { window.bqVoiceClient.dtmf(digit); },

    async hangup() {
        await this.safePost(`/calls/${this.callId}/hangup`, {});
        window.bqVoiceClient.hangupCall();
        this.teardown('agent');
    },

    teardown(reason) {
        this.stopDurationTimer();
        window.bqCallRecorder?.stop();  // flush + upload the recording, if any
        const aggregate = this._statsHandle?.stop();
        postQuality(this.callId, this.csrf, aggregate);
        this._statsHandle = null;
        window.bqVoiceClient?.detach(this);
        this.state = reason === 'error' ? 'failed' : 'ended';
    },

    dismiss() { this.state = 'ended'; },
});

// ─── Inbound AT call (customer dialing the virtual number) ────────────────
window.incomingAtCall = (data) => ({
    ...data,
    ...createCallStateMixin(),
    state: 'ringing',
    muted: false,
    held: false,
    keypadOpen: false,
    _statsHandle: null,
    _incomingArrived: false,
    _answerPending: false,
    _acceptTimeout: null,
    _echoChannel: null,

    init() {
        if (this.state === 'ringing') window.bqStartRingtone?.();

        const vc = window.bqVoiceClient;
        if (vc?.isAvailable()) vc.attach(this);

        if (window.userId && window.Echo) {
            this._echoChannel = window.Echo.private(`user.${window.userId}`);
            this._echoChannel.listen('.call.claimed', (event) => {
                // State guard: a late claim event must not yank a live call.
                if (event.call_id === this.callId
                    && event.claimed_by_session_id !== this.sessionId
                    && this.state === 'ringing') {
                    this.state = 'claimed_elsewhere';
                    window.bqStopRingtone?.();
                }
            });
            this._echoChannel.listen('.call.terminated', (event) => {
                if (event.call_id === this.callId) this.teardown('remote');
            });
        }
    },

    /**
     * Alpine destroy() — frees listeners/accept timer and tears down when the
     * banner's DOM is removed. Prevents handler accumulation on re-mounts.
     */
    destroy() {
        this._clearAcceptTimeout();
        if (this._echoChannel) {
            try { this._echoChannel.stopListening('.call.claimed'); } catch (_) {}
            try { this._echoChannel.stopListening('.call.terminated'); } catch (_) {}
            this._echoChannel = null;
        }
        if (this.state !== 'terminated') {
            this.teardown('component_destroyed');
        }
    },

    // AT routed the inbound call to our client. If the agent already clicked
    // Accept (answer pending), answer now; otherwise note that it arrived so
    // Accept can answer immediately.
    onIncoming() {
        this._incomingArrived = true;
        this._clearAcceptTimeout();
        if (this._answerPending) {
            this._answerPending = false;
            window.bqVoiceClient.answer();
        }
    },
    onAccepted() {
        this.state = 'connected';
        this.startDurationTimer();
        this._tryStats();
        // Best-effort call recording for AI analysis (self-guards on the flag +
        // browser support; no-ops otherwise).
        window.bqCallRecorder?.start(this.callId);
    },
    onHangup() { this.teardown('remote'); },
    onError(err) {
        // Only pre-connect errors flip the card to connect_failed. A transient
        // SDK error mid-call must NOT tear down a connected card.
        if (this.state === 'connected') {
            console.warn('[BQ incomingAtCall] SDK error during active call', err);
            return;
        }
        this.errorMessage = `AT SDK: ${err?.message ?? err ?? 'unknown'}`;
        this.state = 'connect_failed';
    },

    _tryStats() {
        const peer = window.bqVoiceClient.peer();
        if (peer) this._statsHandle = startStatsCollection(peer);
    },

    _clearAcceptTimeout() {
        if (this._acceptTimeout) {
            clearTimeout(this._acceptTimeout);
            this._acceptTimeout = null;
        }
    },

    async acceptCall() {
        window.bqStopRingtone?.();
        this.errorMessage = '';
        let phase = 'claim';
        try {
            const claimRes = await this.post(`/calls/${this.callId}/claim`, { session_id: this.sessionId });
            if (claimRes.status === 409) { this.state = 'claimed_elsewhere'; return; }
            if (!claimRes.ok) {
                const body = await this.safeReadJson(claimRes);
                throw new Error(`Claim ${claimRes.status}: ${body?.error ?? 'see server log'}`);
            }

            this.state = 'connecting';
            phase = 'answer';

            const vc = window.bqVoiceClient;
            if (!vc?.isAvailable()) {
                throw new Error("Voice softphone not registered — reload the page (check Africa's Talking settings / script blockers).");
            }
            // Answer the AT leg. If the incomingcall hasn't arrived yet, defer
            // until onIncoming fires — but bound the wait so a never-arriving
            // leg can't leave the banner in 'connecting' forever.
            if (this._incomingArrived) vc.answer();
            else {
                this._answerPending = true;
                this._acceptTimeout = setTimeout(() => {
                    this._answerPending = false;
                    this._acceptTimeout = null;
                    if (this.state === 'connecting') {
                        this.state = 'connect_failed';
                        this.errorMessage = 'The voice leg never arrived. Try again, or decline.';
                    }
                }, 20000);
            }
        } catch (error) {
            const msg = error?.message ?? String(error);
            console.error(`[BQ incomingAtCall] phase=${phase} failed:`, error);
            if (error && error.name === 'NotAllowedError') {
                this.state = 'mic_denied';
                this.errorMessage = '';
            } else {
                this.state = 'connect_failed';
                this.errorMessage = `[${phase}] ${msg}`;
            }
        }
    },

    /** Retry accept after mic_denied / connect_failed. */
    retryAccept() {
        this.errorMessage = '';
        this._answerPending = false;
        this.state = 'ringing';
        return this.acceptCall();
    },

    async declineCall() {
        window.bqStopRingtone?.();
        await this.safePost(`/calls/${this.callId}/decline`, {});
        window.bqVoiceClient?.hangupCall();
        this.teardown('agent');
    },

    async hangup() {
        await this.safePost(`/calls/${this.callId}/hangup`, {});
        window.bqVoiceClient?.hangupCall();
        this.teardown('agent');
    },

    toggleMute() {
        this.muted = !this.muted;
        this.muted ? window.bqVoiceClient.mute() : window.bqVoiceClient.unmute();
    },

    toggleHold() {
        this.held = !this.held;
        this.held ? window.bqVoiceClient.hold() : window.bqVoiceClient.unhold();
    },

    toggleKeypad() { this.keypadOpen = !this.keypadOpen; },

    sendDtmf(digit) { window.bqVoiceClient.dtmf(digit); },

    teardown(reason) {
        window.bqStopRingtone?.();
        this._clearAcceptTimeout();
        this.stopDurationTimer();
        window.bqCallRecorder?.stop();  // flush + upload the recording, if any
        const aggregate = this._statsHandle?.stop();
        postQuality(this.callId, this.csrf, aggregate);
        this._statsHandle = null;
        window.bqVoiceClient?.detach(this);
        this.state = 'terminated';
    },
});
