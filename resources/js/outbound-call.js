// Phase 18 — Alpine factories using Africa's Talking JS SDK.
// One factory for outbound (window.outgoingCall), one for inbound AT
// calls (window.incomingAtCall) so IncomingCall can branch by provider.
//
// IMPORTANT: SDK call sites (atClient.attach/accept/mute/disconnect) verified
// against the actual africastalking-client package API at deploy time and
// adjusted as needed. Server-side flow is unaffected by SDK shape.
//
// africastalking-client@1.0.7 is npm-deprecated; the install succeeded but
// production may want to substitute the current vendor SDK. The Alpine code
// below depends on a small surface (constructor + on/attach/accept/mute/
// unmute/disconnect) so swapping is local to this file.

import AfricasTalking from 'africastalking-client';
import { startStatsCollection, postQuality } from './call-stats-collector';
import { createCallStateMixin } from './call-state-mixin';

// ─── Outbound (agent dialing customer) ─────────────────────────────────
window.outgoingCall = (data) => ({
    ...data,
    ...createCallStateMixin(),
    state: 'calling',
    muted: false,
    atClient: null,
    _statsHandle: null,

    async init() {
        try {
            this.atClient = new AfricasTalking.Voice({ token: this.atToken });
            this.atClient.on('connected', () => {
                this.state = 'connected';
                this.startDurationTimer();
                // Phase 19a: try to start stats collection. AT SDK peer access is
                // version-specific; if undefined, telemetry stays null for AT calls.
                const peer = this.atClient?.peer ?? this.atClient?.getPeerConnection?.();
                if (peer) {
                    this._statsHandle = startStatsCollection(peer);
                }
            });
            this.atClient.on('disconnected', () => this.teardown('remote'));
            this.atClient.on('error', (err) => {
                console.error('AT SDK error', err);
                this.teardown('error');
            });

            // Server-initiated call already started; SDK attaches to the existing
            // session by ID. (If AT's SDK requires browser-initiated dial, replace
            // attach() with call(this.customerPhone) and remove server placeCall.)
            await this.atClient.attach(this.sessionId);

            if (window.userId && window.Echo) {
                window.Echo.private(`user.${window.userId}`).listen('.call.terminated', (e) => {
                    if (e.call_id === this.callId) this.teardown('remote');
                });
            }
        } catch (error) {
            console.error('outgoingCall init failed', error);
            this.state = 'failed';
        }
    },

    toggleMute() {
        this.muted = !this.muted;
        try {
            this.atClient?.[this.muted ? 'mute' : 'unmute']();
        } catch (e) { console.warn('mute toggle failed', e); }
    },

    async hangup() {
        try {
            await fetch(`/calls/${this.callId}/hangup`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': this.csrf,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });
        } catch (e) { console.warn('hangup post failed', e); }
        this.teardown('agent');
    },

    teardown(reason) {
        this.stopDurationTimer();
        try { this.atClient?.disconnect(); } catch (_) {}
        this.atClient = null;
        const aggregate = this._statsHandle?.stop();
        postQuality(this.callId, this.csrf, aggregate);
        this._statsHandle = null;
        this.state = reason === 'error' ? 'failed' : 'ended';
    },

    dismiss() { this.state = 'ended'; },
});

// ─── Inbound AT call (customer dialing the virtual number) ─────────────
window.incomingAtCall = (data) => ({
    ...data,
    ...createCallStateMixin(),
    state: 'ringing',
    muted: false,
    atClient: null,
    echoChannel: null,
    _statsHandle: null,

    init() {
        // Mirror calls.js incomingCall — start the looping ringtone while
        // the banner is in 'ringing'. Centralized helper from app.js.
        if (this.state === 'ringing') window.bqStartRingtone?.();

        if (window.userId && window.Echo) {
            this.echoChannel = window.Echo.private(`user.${window.userId}`);
            this.echoChannel.listen('.call.claimed', (event) => {
                if (event.call_id === this.callId
                    && event.claimed_by_session_id !== this.sessionId) {
                    this.state = 'claimed_elsewhere';
                    window.bqStopRingtone?.();
                }
            });
            this.echoChannel.listen('.call.terminated', (event) => {
                if (event.call_id === this.callId) this.teardown('remote');
            });
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
                let body = null;
                try { body = await claimRes.json(); } catch (_) {}
                throw new Error(`Claim ${claimRes.status}: ${body?.error ?? 'see server log'}`);
            }

            this.state = 'connecting';

            phase = 'at-sdk-init';
            if (typeof AfricasTalking === 'undefined' || !AfricasTalking?.Voice) {
                throw new Error('Africa\'s Talking JS SDK not loaded — check that the SDK script tag is present and that ad/script blockers are not blocking voice-sdk.africastalking.com.');
            }
            if (!this.atToken) {
                throw new Error('Missing atToken — server did not generate a client token. Check storage/logs/laravel.log for ConfigurationException.');
            }

            phase = 'at-client-construct';
            this.atClient = new AfricasTalking.Voice({ token: this.atToken });
            this.atClient.on('connected', () => {
                this.state = 'connected';
                this.startDurationTimer();
                // Phase 19a: try to start stats collection. AT SDK peer access is
                // version-specific; if undefined, telemetry stays null for AT calls.
                const peer = this.atClient?.peer ?? this.atClient?.getPeerConnection?.();
                if (peer) {
                    this._statsHandle = startStatsCollection(peer);
                }
            });
            this.atClient.on('disconnected', () => this.teardown('remote'));
            // Surface SDK-emitted errors instead of silently teardowning —
            // previously a network blip from AT would just end the call
            // with no message visible to the operator.
            this.atClient.on('error', (sdkError) => {
                console.error('[BlastIQ AT SDK error event]', sdkError);
                this.errorMessage = `AT SDK: ${sdkError?.message ?? sdkError ?? 'unknown'}`;
                this.state = 'connect_failed';
            });

            phase = 'at-accept';
            await this.atClient.accept(this.sessionId);
        } catch (error) {
            const msg = error?.message ?? String(error);
            console.error(`[BlastIQ incomingAtCall] phase=${phase} failed:`, error);
            if (error && error.name === 'NotAllowedError') {
                this.state = 'mic_denied';
                this.errorMessage = '';
            } else {
                this.state = 'connect_failed';
                this.errorMessage = `[${phase}] ${msg}`;
            }
            // Do NOT auto-decline — see calls.js comment for rationale.
        }
    },

    /** Retry accept after mic_denied / connect_failed. */
    async retryAccept() {
        this.errorMessage = '';
        this.state = 'ringing';
        await this.acceptCall();
    },

    async declineCall() {
        window.bqStopRingtone?.();
        await this.post(`/calls/${this.callId}/decline`, {});
        this.teardown('agent');
    },

    async hangup() {
        await this.post(`/calls/${this.callId}/hangup`, {});
        this.teardown('agent');
    },

    toggleMute() {
        this.muted = !this.muted;
        try { this.atClient?.[this.muted ? 'mute' : 'unmute'](); } catch (_) {}
    },

    teardown(reason) {
        window.bqStopRingtone?.();
        this.stopDurationTimer();
        try { this.atClient?.disconnect(); } catch (_) {}
        const aggregate = this._statsHandle?.stop();
        postQuality(this.callId, this.csrf, aggregate);
        this._statsHandle = null;
        this.state = 'terminated';
    },
});
