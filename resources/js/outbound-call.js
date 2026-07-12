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
// the SDK is NOT imported via npm — it is loaded from CDN (see the <script>
// tag in layouts/app.blade.php). On reconnect the script is removed and
// re-loaded with a cache-busting query param so the module cache is bypassed.

import { startStatsCollection, postQuality } from './call-stats-collector';
import { createCallStateMixin } from './call-state-mixin';
import { iceServers } from './ice-servers';

const AT_SDK_URL = 'https://unpkg.com/africastalking-client@1.0.7/build/africastalking.js';

/** Load the AT WebRTC SDK from CDN (first load) or force-reload it (reconnect). */
function loadAtSdk(forceReload = false) {
    if (!forceReload && typeof window.Africastalking?.Client !== 'undefined') {
        return Promise.resolve(true);
    }
    document.querySelectorAll('script[data-at-sdk]').forEach(s => s.remove());
    delete window.Africastalking;
    return new Promise((resolve) => {
        const s = document.createElement('script');
        s.src = `${AT_SDK_URL}?t=${Date.now()}`;
        s.async = true;
        s.crossOrigin = 'anonymous';
        s.dataset.atSdk = '';
        const timer = setTimeout(() => { s.remove(); resolve(false); }, 15000);
        s.onload = () => { clearTimeout(timer); setTimeout(() => resolve(true), 100); };
        s.onerror = () => { clearTimeout(timer); s.remove(); resolve(false); };
        document.head.appendChild(s);
    });
}

// ─── Persistent softphone singleton ─────────────────────────────────────
// One registered client per page. The per-call Alpine banners attach()
// themselves so SDK events route to whichever call is currently on screen.
window.bqVoiceClient = {
    client: null,
    ready: false,
    banner: null,
    _booted: false,
    // Buffer the most recent incoming call from AT. The banner that handles
    // it (outbound auto-answer / inbound accept) is mounted by a 3s Livewire
    // poll, so AT's `incomingcall` event can arrive BEFORE the banner has
    // attached to this client. We stash it and replay it on attach() so the
    // call is never silently dropped.
    _lastIncoming: null,

    /** Register the WebRTC client with a real AT capability token. Idempotent. */
    boot(token) {
        if (this._booted || !token) return;
        if (typeof window.Africastalking === 'undefined' || !window.Africastalking.Client) {
            console.error('[BQ Voice] africastalking-client SDK not available (blocked or failed to load).');
            return;
        }
        try {
            // Pass ICE servers (STUN + optional TURN) best-effort. africastalking-
            // client@1.0.7's constructor is token-first; some builds read a second
            // options arg for iceServers, others ignore it. Harmless either way —
            // if honoured, restrictive-NAT callers get a TURN relay. (Verify
            // against a live AT account whether this build actually applies it.)
            this.client = new window.Africastalking.Client(token, { iceServers: iceServers() });
        } catch (e) {
            console.error('[BQ Voice] failed to construct AT client', e);
            return;
        }
        this._booted = true;

        const c = this.client;
        const on = (evt, fn) => {
            try { c.on(evt, fn, false); } catch (e) { console.warn('[BQ Voice] on() failed for', evt, e); }
        };

        on('ready', () => { this.ready = true; });
        on('notready', () => { this.ready = false; });
        on('offline', () => {
            this.ready = false;
            console.warn('[BQ Voice] client offline. Attempting reconnect in 5s...');
            this._scheduleReboot();
        });
        on('calling', () => {});
        on('closed', () => {});
        // Route call lifecycle to the active banner (if any).
        on('incomingcall', (params) => {
            this._lastIncoming = params;
            this.banner?.onIncoming?.(params);
        });
        on('callaccepted', () => { this.banner?.onAccepted?.(); });
        on('hangup', (cause) => { this.banner?.onHangup?.(cause); });
        on('error', (err) => { console.error('[BQ Voice] SDK error', err); this.banner?.onError?.(err); });
    },

    /** Tear down, force-reload the SDK from CDN (bypassing buggy module cache),
     *  wait the recommended 5s, then re-boot with the meta tag token. */
    async _scheduleReboot() {
        // Clean up existing client state.
        this.ready = false;
        this._booted = false;
        try { this.client?.hangup(); } catch { /* ignore */ }
        this.client = null;
        this.banner = null;
        this._lastIncoming = null;

        await new Promise(r => setTimeout(r, 5000));
        const token = document.querySelector('meta[name="at-voice-token"]')?.getAttribute('content');
        if (!token) return;
        const ok = await loadAtSdk(true);
        if (ok) this.boot(token);
    },

    attach(banner) {
        this.banner = banner;
        // Replay a call that reached us before this banner attached (the
        // poll that mounts the banner can lag AT's incomingcall event).
        if (this._lastIncoming) {
            const pending = this._lastIncoming;
            this._lastIncoming = null;
            banner.onIncoming?.(pending);
        }
    },
    detach(banner) { if (this.banner === banner) this.banner = null; },

    isAvailable() { return !!this.client; },
    answer() { try { this.client?.answer(); } catch (e) { console.warn('[BQ Voice] answer failed', e); } },
    hangupCall() { try { this.client?.hangup(); } catch (e) { console.warn('[BQ Voice] hangup failed', e); } },
    mute() { try { this.client?.muteAudio(); } catch (e) { console.warn('[BQ Voice] mute failed', e); } },
    unmute() { try { this.client?.unmuteAudio(); } catch (e) { console.warn('[BQ Voice] unmute failed', e); } },
    hold() { try { this.client?.hold(); } catch (e) { console.warn('[BQ Voice] hold failed', e); } },
    unhold() { try { this.client?.unhold(); } catch (e) { console.warn('[BQ Voice] unhold failed', e); } },
    dtmf(digit) { try { this.client?.dtmf(String(digit)); } catch (e) { console.warn('[BQ Voice] dtmf failed', e); } },

    /** Best-effort access to the underlying RTCPeerConnection for telemetry. */
    peer() {
        const c = this.client;
        if (!c) return null;
        return c.peer ?? c.peerConnection ?? c.getPeerConnection?.() ?? null;
    },
};

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

// ─── Outbound (agent dialing customer) ───────────────────────────────────
window.outgoingCall = (data) => ({
    ...data,
    ...createCallStateMixin(),
    state: 'calling',
    muted: false,
    held: false,
    keypadOpen: false,
    _statsHandle: null,

    init() {
        const vc = window.bqVoiceClient;
        if (!vc || !vc.isAvailable()) {
            this.state = 'failed';
            this.errorMessage = "Voice softphone not registered. Configure Africa's Talking in Settings, then reload.";
            return;
        }
        vc.attach(this);

        // Server-side terminate (manager kill / natural end) clears the banner.
        if (window.userId && window.Echo) {
            window.Echo.private(`user.${window.userId}`).listen('.call.terminated', (e) => {
                if (e.call_id === this.callId) this.teardown('remote');
            });
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
        this.errorMessage = String(err?.message ?? err ?? 'unknown');
        if (this.state !== 'connected') this.state = 'failed';
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
        try {
            await fetch(`/calls/${this.callId}/hangup`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
        } catch (e) { console.warn('hangup post failed', e); }
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

    init() {
        if (this.state === 'ringing') window.bqStartRingtone?.();

        const vc = window.bqVoiceClient;
        if (vc?.isAvailable()) vc.attach(this);

        if (window.userId && window.Echo) {
            const channel = window.Echo.private(`user.${window.userId}`);
            channel.listen('.call.claimed', (event) => {
                if (event.call_id === this.callId
                    && event.claimed_by_session_id !== this.sessionId) {
                    this.state = 'claimed_elsewhere';
                    window.bqStopRingtone?.();
                }
            });
            channel.listen('.call.terminated', (event) => {
                if (event.call_id === this.callId) this.teardown('remote');
            });
        }
    },

    // AT routed the inbound call to our client. If the agent already clicked
    // Accept (answer pending), answer now; otherwise note that it arrived so
    // Accept can answer immediately.
    onIncoming() {
        this._incomingArrived = true;
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
        this.errorMessage = `AT SDK: ${err?.message ?? err ?? 'unknown'}`;
        this.state = 'connect_failed';
    },

    _tryStats() {
        const peer = window.bqVoiceClient.peer();
        if (peer) this._statsHandle = startStatsCollection(peer);
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
            // until onIncoming fires.
            if (this._incomingArrived) vc.answer();
            else this._answerPending = true;
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
        await this.post(`/calls/${this.callId}/decline`, {});
        window.bqVoiceClient?.hangupCall();
        this.teardown('agent');
    },

    async hangup() {
        await this.post(`/calls/${this.callId}/hangup`, {});
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
        this.stopDurationTimer();
        window.bqCallRecorder?.stop();  // flush + upload the recording, if any
        const aggregate = this._statsHandle?.stop();
        postQuality(this.callId, this.csrf, aggregate);
        this._statsHandle = null;
        window.bqVoiceClient?.detach(this);
        this.state = 'terminated';
    },
});
