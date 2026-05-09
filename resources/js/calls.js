/**
 * Phase 17 — RTCPeerConnection lifecycle for inbound WhatsApp call answer.
 *
 * Flow on Accept click:
 *   1. POST /calls/{id}/claim with session_id (atomic — first wins).
 *   2. getUserMedia({audio:true}) — browser permission prompt.
 *   3. new RTCPeerConnection({iceServers:[stun]}).
 *   4. peer.ontrack → set remote stream as <audio> srcObject (audio plays).
 *   5. peer.addTrack(micTrack, micStream) — outbound audio.
 *   6. peer.setRemoteDescription({type:'offer', sdp: sdpOffer}).
 *   7. answer = peer.createAnswer(); peer.setLocalDescription(answer).
 *   8. POST /calls/{id}/answer { session_id, sdp: answer.sdp }.
 *   9. Server forwards to Meta via acceptCall — audio peer establishes.
 *
 * On Decline / Hangup / claimed_elsewhere / customer disconnect (via
 * CallTerminated Echo event): teardown — peer.close(), stop mic tracks.
 */
import { startStatsCollection, postQuality } from './call-stats-collector';

window.incomingCall = (data) => ({
    ...data,
    state: 'ringing',
    peer: null,
    micStream: null,
    muted: false,
    durationSeconds: 0,
    durationTimer: null,
    echoChannel: null,
    _statsHandle: null,

    init() {
        // Start the looping ringtone for as long as the banner is in 'ringing'.
        // bqStartRingtone is idempotent — safe to call again if the user
        // navigates within the SPA-ish Livewire layer.
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
                if (event.call_id === this.callId) {
                    this.teardown('remote_terminated');
                }
            });
        }
    },

    async acceptCall() {
        // Stop ringing the moment the user commits to answering — even if
        // the claim fails, the customer is no longer waiting on us to ring.
        window.bqStopRingtone?.();
        try {
            // 1. Atomic claim — first POST wins.
            const claimRes = await this.post(`/calls/${this.callId}/claim`, { session_id: this.sessionId });
            if (claimRes.status === 409) {
                this.state = 'claimed_elsewhere';
                return;
            }
            if (!claimRes.ok) {
                throw new Error(`Claim failed: ${claimRes.status}`);
            }

            this.state = 'connecting';

            // 2. Microphone permission (just-in-time per Q3).
            //    NotAllowedError → mic_denied (retryable).
            //    Anything else → connect_failed (also offers retry).
            this.micStream = await navigator.mediaDevices.getUserMedia({ audio: true });

            // 3. Peer connection. STUN is enough for most networks; TURN is Phase 19.
            this.peer = new RTCPeerConnection({
                iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
            });

            // 4. Audio rendering — Meta's stream → <audio> element.
            this.peer.ontrack = (event) => {
                const audioEl = document.getElementById('bq-remote-audio');
                if (audioEl && event.streams[0]) {
                    audioEl.srcObject = event.streams[0];
                }
            };

            // 5. Outbound audio — agent's mic.
            this.micStream.getAudioTracks().forEach(track => {
                this.peer.addTrack(track, this.micStream);
            });

            // 6-7. SDP exchange (offer from Meta, answer from us).
            await this.peer.setRemoteDescription({ type: 'offer', sdp: this.sdpOffer });
            const answer = await this.peer.createAnswer();
            await this.peer.setLocalDescription(answer);

            // 8. Forward answer to server, which calls Meta acceptCall.
            const answerRes = await this.post(`/calls/${this.callId}/answer`, {
                session_id: this.sessionId,
                sdp: answer.sdp,
            });
            if (!answerRes.ok) {
                throw new Error(`Answer failed: ${answerRes.status}`);
            }

            this.state = 'connected';
            this.startDurationTimer();
            this._statsHandle = startStatsCollection(this.peer);
        } catch (error) {
            // Distinguish "user clicked Block in the permission prompt" from
            // any other failure (network, claim 5xx, SDP error). The first is
            // retryable via a fresh getUserMedia call (browsers re-prompt
            // unless the user permanently blocked the origin in site
            // settings). The second usually means the call is gone — surface
            // a different message and let the user retry the whole flow.
            this.state = (error && error.name === 'NotAllowedError')
                ? 'mic_denied'
                : 'connect_failed';
            this.cleanupMedia();
            // IMPORTANT: do NOT auto-decline here — that releases the call on
            // the server and a Retry click would 409. The customer keeps
            // ringing for a few more seconds while the agent retries; if the
            // retry also fails, the user can click Decline explicitly.
        }
    },

    /**
     * Retry the accept flow after a mic_denied / connect_failed error.
     * Safe to call multiple times — acceptCall() is idempotent on the
     * client side (the server's claim endpoint is the atomic guard).
     */
    async retryAccept() {
        this.state = 'ringing';
        await this.acceptCall();
    },

    async declineCall() {
        window.bqStopRingtone?.();
        await this.post(`/calls/${this.callId}/decline`, {});
        this.teardown('agent_declined');
    },

    async hangup() {
        await this.post(`/calls/${this.callId}/hangup`, {});
        this.teardown('agent_hung_up');
    },

    toggleMute() {
        this.muted = !this.muted;
        this.micStream?.getAudioTracks().forEach(t => t.enabled = !this.muted);
    },

    startDurationTimer() {
        this.durationTimer = setInterval(() => this.durationSeconds++, 1000);
    },

    cleanupMedia() {
        try { this.peer?.close(); } catch (_) {}
        this.micStream?.getTracks().forEach(t => t.stop());
        this.peer = null;
        this.micStream = null;
        clearInterval(this.durationTimer);
        this.durationTimer = null;
        const aggregate = this._statsHandle?.stop();
        postQuality(this.callId, this.csrf, aggregate);
        this._statsHandle = null;
    },

    teardown(reason) {
        window.bqStopRingtone?.();
        this.cleanupMedia();
        this.state = 'terminated';
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
        const s = seconds % 60;
        return `${m}:${s.toString().padStart(2, '0')}`;
    },
});
