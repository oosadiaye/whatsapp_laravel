/**
 * Turn the failure reason the server stores for a call into words an agent can act on.
 *
 * The server records Africa's Talking's own explanation on the call
 * (call_logs.failure_reason) in two shapes:
 *   "AT bridge: <lastBridgeHangupCause>"   the customer answered but AT could not
 *                                          connect them to the agent's browser
 *   "AT failure: <errorMessage|hangupCause>" AT refused or aborted the call
 *
 * Until now the banner simply vanished when a call died this way and the agent was
 * left guessing. The raw provider code is kept in the text so it can be quoted to
 * Africa's Talking support.
 *
 * Pure and dependency-free so it can be unit-tested (`npm run test:js`).
 */

const BRIDGE_CAUSES = {
    NO_USER_RESPONSE:
        'The call never reached your browser, so it could not be connected. Make sure BlastIQ is open in only one tab or window, then try again.',
    NO_ANSWER:
        'Your browser rang but the call was not answered in time. Keep this tab open, allow the microphone when asked, and answer straight away.',
    RECOVERY_ON_TIMER_EXPIRE:
        'The call connected but the audio link could not be established. Check that the microphone is allowed and that your network (a firewall or phone hotspot can block call audio) is fine. Run "Check my voice setup" to find out which.',
    USER_BUSY: 'Your browser was busy on another call, so this one could not be connected.',
    CALL_REJECTED: 'The call was rejected before it could be connected.',
};

/** @param {string|null|undefined} reason the call's stored failure_reason */
export function describeCallFailure(reason) {
    const raw = String(reason ?? '').trim();
    if (raw === '') {
        return '';
    }

    const bridge = raw.match(/^AT bridge:\s*(\S+)/i);
    if (bridge) {
        const code = bridge[1];
        const friendly = BRIDGE_CAUSES[code];

        return friendly ? `${friendly} (${code})` : `The call could not be connected to your browser (${code}).`;
    }

    // "AT failure: …" carries AT's own sentence; it reads fine as it is, so only the
    // internal prefix is dropped.
    return raw.replace(/^AT failure:\s*/i, '');
}
