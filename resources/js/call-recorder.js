/**
 * Client-side call recorder for the Call Workspace.
 *
 * The softphone (Africa's Talking SDK, and the Meta RTCPeerConnection path)
 * carries the call audio in the browser. We tap the peer connection's audio
 * tracks — the remote leg (receivers) + the agent's mic (senders) — mix them
 * with the Web Audio API, and capture the mix with MediaRecorder. On hangup the
 * blob is POSTed to /calls/{id}/recording, which stores it privately and queues
 * Gemini transcription.
 *
 * This is strictly best-effort and OFF unless the server flag says otherwise:
 *   - meta[name=bq-recording-enabled] must be "1"
 *   - the SDK must expose a live RTCPeerConnection (bqVoiceClient.peer())
 *   - the browser must support MediaRecorder + AudioContext
 * If any precondition is missing we silently no-op so calls are never affected.
 * The call's ai_status simply stays "none" (nothing to summarise).
 */

// Prefer containers Gemini accepts directly (ogg / mp4) so no server-side
// transcode is needed; fall back to webm (Chrome's only option), which the
// TranscribeCallRecording job remuxes to ogg via ffmpeg when available.
const PREFERRED_MIME_TYPES = [
    'audio/ogg;codecs=opus', // Firefox — Gemini-native
    'audio/mp4',             // Safari — Gemini accepts mp4/aac
    'audio/webm;codecs=opus', // Chrome — needs the server-side remux
    'audio/webm',
];

function recordingEnabled() {
    return document.querySelector('meta[name=bq-recording-enabled]')?.getAttribute('content') === '1';
}

function csrfToken() {
    return document.querySelector('meta[name=csrf-token]')?.getAttribute('content') ?? '';
}

function pickMimeType() {
    if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) return '';
    for (const type of PREFERRED_MIME_TYPES) {
        if (MediaRecorder.isTypeSupported(type)) return type;
    }
    return ''; // let the browser choose its default container
}

/**
 * Build one mixed audio MediaStream from a peer connection's audio tracks.
 * Returns { stream, context } or null when there's no audio to capture.
 */
function buildMixedStream(peer) {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx || !peer) return null;

    const remoteTracks = (peer.getReceivers?.() ?? [])
        .map((r) => r.track)
        .filter((t) => t && t.kind === 'audio');
    const micTracks = (peer.getSenders?.() ?? [])
        .map((s) => s.track)
        .filter((t) => t && t.kind === 'audio');

    if (remoteTracks.length === 0 && micTracks.length === 0) return null;

    const context = new AudioCtx();
    const destination = context.createMediaStreamDestination();

    if (remoteTracks.length) {
        context.createMediaStreamSource(new MediaStream(remoteTracks)).connect(destination);
    }
    if (micTracks.length) {
        context.createMediaStreamSource(new MediaStream(micTracks)).connect(destination);
    }

    return { stream: destination.stream, context };
}

const recorder = {
    _recorder: null,
    _chunks: [],
    _callId: null,
    _mime: '',
    _context: null,

    /**
     * Begin recording the live call. Safe to call unconditionally from the call
     * factories — it self-guards on every precondition.
     */
    start(callId) {
        if (this._recorder) return;             // already recording
        if (!recordingEnabled()) return;        // server flag off
        if (!callId) return;

        let peer = null;
        try {
            peer = window.bqVoiceClient?.peer?.() ?? window.bqMetaPeer ?? null;
        } catch { /* SDK not ready */ }

        const mixed = buildMixedStream(peer);
        if (!mixed) {
            console.debug('[BQ recorder] no capturable audio tracks — skipping recording');
            return;
        }

        const mime = pickMimeType();
        let mr;
        try {
            mr = mime ? new MediaRecorder(mixed.stream, { mimeType: mime }) : new MediaRecorder(mixed.stream);
        } catch (e) {
            console.debug('[BQ recorder] MediaRecorder unavailable — skipping', e);
            mixed.context.close?.();
            return;
        }

        this._chunks = [];
        this._callId = callId;
        this._mime = mr.mimeType || mime || 'audio/webm';
        this._context = mixed.context;
        this._recorder = mr;

        mr.ondataavailable = (e) => { if (e.data && e.data.size > 0) this._chunks.push(e.data); };
        mr.onstop = () => this._upload();

        try {
            mr.start();
        } catch (e) {
            console.debug('[BQ recorder] start failed — skipping', e);
            this._cleanup();
        }
    },

    /** Stop recording; onstop triggers the upload. Idempotent. */
    stop() {
        if (!this._recorder) return;
        try {
            if (this._recorder.state !== 'inactive') this._recorder.stop();
            else this._upload();
        } catch (e) {
            console.debug('[BQ recorder] stop failed', e);
            this._cleanup();
        }
    },

    async _upload() {
        const chunks = this._chunks;
        const callId = this._callId;
        const mime = this._mime;
        this._cleanup();

        if (!callId || chunks.length === 0) return;

        const blob = new Blob(chunks, { type: mime });
        // Skip empty/near-empty captures (e.g. instant hangups).
        if (blob.size < 1024) return;

        const ext = mime.includes('ogg') ? 'ogg' : mime.includes('mp4') ? 'mp4' : 'webm';
        const form = new FormData();
        form.append('audio', blob, `call-${callId}.${ext}`);

        try {
            await fetch(`/calls/${callId}/recording`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
                body: form,
                credentials: 'same-origin',
            });
        } catch (e) {
            console.warn('[BQ recorder] recording upload failed', e);
        }
    },

    _cleanup() {
        try { this._context?.close?.(); } catch { /* noop */ }
        this._recorder = null;
        this._chunks = [];
        this._callId = null;
        this._context = null;
    },
};

window.bqCallRecorder = recorder;

export default recorder;
