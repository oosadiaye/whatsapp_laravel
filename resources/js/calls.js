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
import { iceServers } from './ice-servers';

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
                // State guard: only a still-ringing banner yields to another
                // window. Once we're connected, a late claim event must not
                // yank a live call to claimed_elsewhere.
                if (event.call_id === this.callId
                    && event.claimed_by_session_id !== this.sessionId
                    && this.state === 'ringing') {
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

    /**
     * Alpine destroy() — called when the banner's DOM is removed (call no
     * longer in-flight, Livewire unmounts it). Frees the Reverb listeners and
     * channel, then runs teardown so no timer/recorder/peer outlives the
     * banner. Without this, every re-mount leaks another set of handlers onto
     * the same private channel.
     */
    destroy() {
        if (this.echoChannel) {
            try { this.echoChannel.stopListening('.call.claimed'); } catch (_) {}
            try { this.echoChannel.stopListening('.call.terminated'); } catch (_) {}
            try { this.echoChannel.leave(); } catch (_) {}
            this.echoChannel = null;
        }
        if (this.state !== 'terminated') {
            this.teardown('component_destroyed');
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
            // ICE servers (STUN + optional TURN) from the server-rendered meta
            // tag, so restrictive-NAT callers get a TURN relay instead of dead
            // air. Falls back to Vite STUN / Google STUN.
            this.peer = new RTCPeerConnection({
                iceServers: iceServers(),
            });
            // Export the live peer for the recorder/call-stats integrations
            // that reach for window.bqMetaPeer (call-recorder.js). Cleared in
            // cleanupMedia() so a finished call never leaks the connection.
            window.bqMetaPeer = this.peer;

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
            await this.peer.setRemoteDescription({ type: 'offer', sdp });
            const answer = await this.peer.createAnswer();
            await this.peer.setLocalDescription(answer);

            // 8. Wait for ICE gathering to complete before sending the answer.
            //    Posting createAnswer()'s description can ship a half-gathered
            //    answer (missing candidates) → one-way or dead audio on slower
            //    networks. Bounded wait so a stuck ICE never hangs the accept.
            phase = 'ice';
            const gatheredSdp = await this._waitForIceComplete(this.peer);

            // 9. Forward answer to server, which calls Meta acceptCall.
            phase = 'answer';
            const answerRes = await this.post(`/calls/${this.callId}/answer`, {
                session_id: this.sessionId,
                sdp: gatheredSdp,
            });
            if (!answerRes.ok) {
                const body = await this.safeReadJson(answerRes);
                throw new Error(`Answer endpoint ${answerRes.status}: ${body?.error ?? 'check storage/logs/laravel.log on the server'}`);
            }

            this.state = 'connected';
            this.startDurationTimer();
            this._statsHandle = startStatsCollection(this.peer);
            window.bqCallRecorder?.start(this.callId);
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
     * Resolve with the fully-gathered local SDP, or time out after 3s and
     * ship whatever we have (better a slightly early answer than a stuck
     * accept). Also resolves immediately if gathering already completed.
     */
    _waitForIceComplete(peer, timeoutMs = 3000) {
        if (!peer || peer.iceGatheringState === 'complete') {
            return Promise.resolve(peer?.localDescription?.sdp ?? '');
        }
        return new Promise((resolve) => {
            const onChange = () => {
                if (peer.iceGatheringState === 'complete') {
                    clearTimeout(timer);
                    peer.removeEventListener('icegatheringstatechange', onChange);
                    resolve(peer.localDescription?.sdp ?? '');
                }
            };
            const timer = setTimeout(() => {
                peer.removeEventListener('icegatheringstatechange', onChange);
                resolve(peer.localDescription?.sdp ?? '');
            }, timeoutMs);
            peer.addEventListener('icegatheringstatechange', onChange);
        });
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
        // Best-effort: never let a transient network failure strand the banner.
        await this.safePost(`/calls/${this.callId}/decline`, {});
        this.teardown('agent_declined');
    },

    async hangup() {
        await this.safePost(`/calls/${this.callId}/hangup`, {});
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
        window.bqMetaPeer = null;
        this.micStream = null;
        // Release the remote audio element's stream reference so a finished
        // call can't leak the MediaStream (or auto-replay it) on the next one.
        const audioEl = document.getElementById('bq-remote-audio');
        if (audioEl) {
            try { audioEl.srcObject = null; } catch (_) {}
        }
        this.stopDurationTimer();
        window.bqCallRecorder?.stop();
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
