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
import { createCallStateMixin } from './call-state-mixin';

window.incomingCall = (data) => ({
    ...data,
    ...createCallStateMixin(),  // durationSeconds, durationTimer, errorMessage,
                                // post(), safeReadJson(), formatDuration(),
                                // startDurationTimer(), stopDurationTimer()
    state: 'ringing',
    peer: null,
    micStream: null,
    muted: false,
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
        this.errorMessage = '';
        let phase = 'claim';   // updated as we progress; the catch reports
                               // which phase tripped, so operators see e.g.
                               // "answer (500) failed" not "connect_failed".
        try {
            // 1. Atomic claim — first POST wins.
            const claimRes = await this.post(`/calls/${this.callId}/claim`, { session_id: this.sessionId });
            if (claimRes.status === 409) {
                this.state = 'claimed_elsewhere';
                return;
            }
            if (!claimRes.ok) {
                const body = await this.safeReadJson(claimRes);
                throw new Error(`Claim failed (${claimRes.status}): ${body?.error ?? 'see server log'}`);
            }

            this.state = 'connecting';

            // 2. Microphone permission (just-in-time per Q3).
            //    NotAllowedError → mic_denied (retryable).
            //    Anything else → connect_failed (also offers retry).
            phase = 'mic';
            this.micStream = await navigator.mediaDevices.getUserMedia({ audio: true });

            // 3. Peer connection. STUN is enough for most networks; TURN is Phase 19.
            phase = 'peer';
            // Guard: if the server didn't include the SDP offer in the call
            // payload, setRemoteDescription will throw an opaque DOMException
            // that's hard to diagnose. Check early so the error message
            // points at the real cause (missing webhook field or DB column).
            if (!this.sdpOffer || typeof this.sdpOffer !== 'string') {
                throw new Error('Missing SDP offer on the call. The Meta calling webhook may not have included it — check whatsapp.calls webhook logs.');
            }
            // ICE servers from Vite env (set in .env via VITE_STUN_URLS,
            // comma-separated, mirroring config/voice.php's stun_urls).
            // Default falls back to Google's public STUN so a fresh dev
            // setup works without extra config.
            const stunUrls = (import.meta.env.VITE_STUN_URLS
                || 'stun:stun.l.google.com:19302')
                .split(',')
                .map(u => u.trim())
                .filter(Boolean);
            this.peer = new RTCPeerConnection({
                iceServers: stunUrls.map(urls => ({ urls })),
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
            phase = 'sdp';
            // Defensive normalisation of line endings. RFC 4566 mandates CRLF
            // between SDP lines, but the offer can reach us with bare LF (Meta
            // webhook → JSON serialise → DB → @js() blade emit → browser) and
            // Chrome's parser rejects bare-LF SDP with an error like
            //   "Failed to parse SessionDescription. <last-seen line> Invalid SDP line."
            // The fix is idempotent: replace any LF-not-preceded-by-CR with
            // CRLF, leaves existing CRLF alone. Also strip a leading BOM if
            // any (rare, but cheap to guard against).
            let sdp = this.sdpOffer.replace(/﻿/g, '');
            sdp = sdp.replace(/\r\n/g, '\n').replace(/\n/g, '\r\n');
            // Surface to console so DevTools shows the EXACT bytes Chrome
            // sees on the next failure — invaluable when normalising still
            // isn't enough (e.g. the SDP is structurally missing an m= line).
            console.debug('[BlastIQ incoming-call] setRemoteDescription with SDP:\n' + sdp);
            await this.peer.setRemoteDescription({ type: 'offer', sdp });
            const answer = await this.peer.createAnswer();
            await this.peer.setLocalDescription(answer);

            // 8. Forward answer to server, which calls Meta acceptCall.
            phase = 'answer';
            const answerRes = await this.post(`/calls/${this.callId}/answer`, {
                session_id: this.sessionId,
                sdp: answer.sdp,
            });
            if (!answerRes.ok) {
                const body = await this.safeReadJson(answerRes);
                throw new Error(`Answer endpoint ${answerRes.status}: ${body?.error ?? 'check storage/logs/laravel.log on the server'}`);
            }

            this.state = 'connected';
            this.startDurationTimer();
            this._statsHandle = startStatsCollection(this.peer);
        } catch (error) {
            // Surface to console + state so the operator sees WHY it failed.
            const msg = error?.message ?? String(error);
            console.error(`[BlastIQ incoming-call] phase=${phase} failed:`, error);
            // Distinguish "user clicked Block in the permission prompt" from
            // any other failure (network, claim 5xx, SDP error). The first is
            // retryable via a fresh getUserMedia call (browsers re-prompt
            // unless the user permanently blocked the origin in site
            // settings). The second usually means the call is gone — surface
            // a different message and let the user retry the whole flow.
            if (error && error.name === 'NotAllowedError') {
                this.state = 'mic_denied';
                this.errorMessage = '';
            } else {
                this.state = 'connect_failed';
                this.errorMessage = `[${phase}] ${msg}`;
            }
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
        this.errorMessage = '';
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

    cleanupMedia() {
        try { this.peer?.close(); } catch (_) {}
        this.micStream?.getTracks().forEach(t => t.stop());
        this.peer = null;
        this.micStream = null;
        this.stopDurationTimer();
        const aggregate = this._statsHandle?.stop();
        postQuality(this.callId, this.csrf, aggregate);
        this._statsHandle = null;
    },

    teardown(reason) {
        window.bqStopRingtone?.();
        this.cleanupMedia();
        this.state = 'terminated';
    },
});
