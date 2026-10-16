/**
 * Lets the voice check ask "how many OTHER BlastIQ tabs are open in this browser?".
 *
 * Why it matters: every open tab registers the same softphone identity
 * (quo_call.agent_<id>). Africa's Talking delivers an incoming call to only one of
 * them, so a forgotten tab can take the call while the one the agent is looking at
 * never rings, which looks exactly like "the call rang nowhere".
 *
 * Each page answers a ping with its own random id. Only same-browser tabs are
 * visible this way (another browser or device cannot be seen from here).
 *
 * Pure and dependency-free so it can be unit-tested with a fake BroadcastChannel.
 */

export const TAB_CHANNEL = 'bq-voice-tabs';

/**
 * Start answering pings from the voice check.
 *
 * @returns {() => void} stop function (closes the channel)
 */
export function startTabPresence({ BroadcastChannel: Channel, tabId }) {
    if (typeof Channel !== 'function') {
        return () => {};
    }

    const channel = new Channel(TAB_CHANNEL);
    channel.onmessage = (event) => {
        if (event?.data?.type === 'ping') {
            channel.postMessage({ type: 'pong', nonce: event.data.nonce, from: tabId });
        }
    };

    return () => {
        channel.onmessage = null;
        channel.close();
    };
}
