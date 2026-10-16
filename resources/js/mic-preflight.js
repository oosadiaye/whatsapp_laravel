/**
 * Microphone pre-flight for calls.
 *
 * Why this exists: the Africa's Talking SDK only asks for the microphone INSIDE
 * answer(), i.e. while AT's call timer is already running. If the browser prompt is
 * still open, was dismissed, or the site is blocked, the SDK quietly declines the
 * call and never raises an error, so the agent saw a ring, then nothing, and the
 * app's "microphone blocked" screen was unreachable. Asking here instead, on the
 * click that starts or accepts a call (a user gesture, before any timer), means:
 *   - the prompt happens while nothing is waiting on it,
 *   - a blocked or missing microphone is reported in plain words, and
 *   - an outbound call is never dialed (and billed) when the agent can't talk.
 *
 * Pure and dependency-free so it can be unit-tested with a fake `navigator`
 * (`npm run test:js`).
 */

const MESSAGES = {
    denied: 'The microphone is blocked for this site, so calls cannot be answered. Click the padlock next to the address, set Microphone to Allow, then try again.',
    no_device: 'No microphone was found. Plug in or enable a microphone (or headset), then try again.',
    busy: 'The microphone is being used by another app or tab. Close it (or end that call), then try again.',
    unavailable: 'This page cannot use the microphone. Calls need a secure https page in a supported browser (Chrome, Edge, Firefox or Safari).',
    failed: 'Could not start the microphone. Check that it is connected and allowed for this site, then try again.',
};

/** Plain-language explanation for a pre-flight error (or anything unexpected). */
export function describeMicError(error) {
    return MESSAGES[error?.kind] ?? MESSAGES.failed;
}

function micError(kind) {
    const error = new Error(MESSAGES[kind]);
    // The call banners already treat a NotAllowedError as "microphone blocked" and
    // show a retry, so denial keeps that exact name. Other failures are labelled.
    error.name = kind === 'denied' ? 'NotAllowedError' : 'MicrophoneError';
    error.kind = kind;
    return error;
}

function kindOf(domError) {
    switch (domError?.name) {
        case 'NotAllowedError':
        case 'SecurityError':
        case 'PermissionDeniedError':
            return 'denied';
        case 'NotFoundError':
        case 'OverconstrainedError':
        case 'DevicesNotFoundError':
            return 'no_device';
        case 'NotReadableError':
        case 'TrackStartError':
        case 'AbortError':
            return 'busy';
        default:
            return 'failed';
    }
}

/**
 * @param {Navigator} nav injectable for tests
 * @returns {() => Promise<true>} resolves when the microphone is usable, otherwise
 *          rejects with an Error carrying `.kind` (see MESSAGES).
 */
export function createMicPreflight(nav) {
    return async function ensureMic() {
        const media = nav?.mediaDevices;
        if (!media || typeof media.getUserMedia !== 'function') {
            throw micError('unavailable');
        }

        // Cheap early exit: a site already blocked can't be prompted again, so say
        // so instead of waiting for a getUserMedia rejection. Firefox and Safari
        // cannot query 'microphone'; for them getUserMedia itself is the check.
        try {
            const status = await nav.permissions?.query?.({ name: 'microphone' });
            if (status?.state === 'denied') {
                throw micError('denied');
            }
        } catch (error) {
            if (error?.kind) throw error;
            // Permissions API missing or unsupported for this name: fall through.
        }

        let stream;
        try {
            stream = await media.getUserMedia({ audio: true });
        } catch (error) {
            throw micError(kindOf(error));
        }

        // Only proving access: release the device immediately so the browser's
        // recording indicator goes out and the SDK can open it itself later.
        stream.getTracks().forEach((track) => track.stop());

        return true;
    };
}

/** The pre-flight bound to the real browser, or a no-op that rejects outside one. */
export const ensureMic = createMicPreflight(typeof navigator === 'undefined' ? undefined : navigator);
