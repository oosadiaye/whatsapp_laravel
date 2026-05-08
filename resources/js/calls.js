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
        if (window.userId && window.Echo) {
            this.echoChannel = window.Echo.private(`user.${window.userId}`);
            this.echoChannel.listen('.call.claimed', (event) => {
                if (event.call_id === this.callId
                    && event.claimed_by_session_id !== this.sessionId) {
                    this.state = 'claimed_elsewhere';
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
            if (error && error.name === 'NotAllowedError') {
                this.state = 'mic_denied';
            } else {
                this.state = 'mic_denied'; // generic failure surface
            }
            // Tell server to release the call so customer doesn't hear silence.
            await this.post(`/calls/${this.callId}/decline`, {});
            this.cleanupMedia();
        }
    },

    async declineCall() {
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
