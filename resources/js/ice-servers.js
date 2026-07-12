/**
 * WebRTC ICE servers (STUN + optional TURN), read from the server-rendered
 * meta[name=bq-ice-servers] tag (App\Support\VoiceIce). One source of truth for
 * both the Meta RTCPeerConnection (calls.js) and the Africa's Talking softphone
 * (outbound-call.js). Falls back to the Vite STUN env / Google STUN so a page
 * without the meta tag still works.
 *
 * @returns {RTCIceServer[]}
 */
export function iceServers() {
    try {
        const raw = document.querySelector('meta[name=bq-ice-servers]')?.getAttribute('content');
        if (raw) {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed) && parsed.length > 0) return parsed;
        }
    } catch {
        /* malformed meta → fall through to STUN default */
    }

    const stun = (import.meta.env.VITE_STUN_URLS || 'stun:stun.l.google.com:19302')
        .split(',')
        .map((u) => u.trim())
        .filter(Boolean);

    return stun.map((urls) => ({ urls }));
}
