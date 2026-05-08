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

// ─── Outbound (agent dialing customer) ─────────────────────────────────
window.outgoingCall = (data) => ({
    ...data,
    state: 'calling',
    durationSeconds: 0,
    durationTimer: null,
    muted: false,
    atClient: null,

    async init() {
        try {
            this.atClient = new AfricasTalking.Voice({ token: this.atToken });
            this.atClient.on('connected', () => {
                this.state = 'connected';
                this.startDurationTimer();
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
        clearInterval(this.durationTimer);
        try { this.atClient?.disconnect(); } catch (_) {}
        this.atClient = null;
        this.state = reason === 'error' ? 'failed' : 'ended';
    },

    startDurationTimer() {
        this.durationTimer = setInterval(() => this.durationSeconds++, 1000);
    },

    formatDuration(seconds) {
        const m = Math.floor(seconds / 60);
        return `${m}:${String(seconds % 60).padStart(2, '0')}`;
    },

    dismiss() { this.state = 'ended'; },
});

// ─── Inbound AT call (customer dialing the virtual number) ─────────────
window.incomingAtCall = (data) => ({
    ...data,
    state: 'ringing',
    durationSeconds: 0,
    durationTimer: null,
    muted: false,
    atClient: null,
    echoChannel: null,

    init() {
        if (window.userId && window.Echo) {
            this.echoChannel = window.Echo.private(`user.${window.userId}`);
            this.echoChannel.listen('.call.claimed', (event) => {
                if (event.call_id === this.callId
                    && event.claimed_by_session_id !== this.sessionId) {
                    this.state = 'claimed_elsewhere';
                }
            });
            this.echoChannel.listen('.call.terminated', (event) => {
                if (event.call_id === this.callId) this.teardown('remote');
            });
        }
    },

    async acceptCall() {
        try {
            const claimRes = await this.post(`/calls/${this.callId}/claim`, { session_id: this.sessionId });
            if (claimRes.status === 409) { this.state = 'claimed_elsewhere'; return; }
            if (!claimRes.ok) throw new Error(`Claim failed: ${claimRes.status}`);

            this.state = 'connecting';

            this.atClient = new AfricasTalking.Voice({ token: this.atToken });
            this.atClient.on('connected', () => {
                this.state = 'connected';
                this.startDurationTimer();
            });
            this.atClient.on('disconnected', () => this.teardown('remote'));
            this.atClient.on('error', () => this.teardown('error'));

            await this.atClient.accept(this.sessionId);
        } catch (error) {
            console.error('incomingAtCall accept failed', error);
            this.state = 'mic_denied';
            await this.post(`/calls/${this.callId}/decline`, {});
            this.teardown('error');
        }
    },

    async declineCall() {
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
        clearInterval(this.durationTimer);
        try { this.atClient?.disconnect(); } catch (_) {}
        this.state = 'terminated';
    },

    startDurationTimer() {
        this.durationTimer = setInterval(() => this.durationSeconds++, 1000);
    },

    async post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
    },

    formatDuration(seconds) {
        const m = Math.floor(seconds / 60);
        return `${m}:${String(seconds % 60).padStart(2, '0')}`;
    },
});
