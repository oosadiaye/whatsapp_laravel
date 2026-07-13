/**
 * Shared state + helpers for the IncomingCall / IncomingAtCall /
 * OutgoingCall Alpine factories.
 *
 * Both `incomingCall` (raw WebRTC for Meta WhatsApp) and `incomingAtCall`
 * (Africa's Talking SDK) carried ~150 lines of identical state plumbing —
 * fetch wrapper, MM:SS formatter, duration timer, JSON-safe response
 * reader. Extracting them here means a behaviour fix (e.g. adding
 * Cache-Control: no-store on the fetch) lands in one place and both
 * factories pick it up automatically.
 *
 * Usage in an Alpine factory:
 *
 *     window.incomingCall = (data) => ({
 *         ...data,
 *         ...createCallStateMixin(),
 *         // factory-specific fields below
 *         state: 'ringing',
 *         peer: null,
 *         ...
 *         init() {
 *             // factory-specific
 *         },
 *     });
 *
 * Mixin fields never override fields the factory sets explicitly —
 * the spread order ensures factory wins. The mixin is intentionally
 * stateless of provider-specifics (no atClient, no peer) so it can
 * be reused for any future call adapter.
 */
export function createCallStateMixin() {
    return {
        // ─── Shared state ─────────────────────────────────────────
        durationSeconds: 0,
        durationTimer: null,
        // Tagged failure message surfaced into the UI banner so operators
        // see WHY a connect failed (mic vs claim vs server vs SDP) without
        // opening DevTools. Reset on retry.
        errorMessage: '',

        // Blind-transfer UI state.
        transferOpen: false,
        transferNumber: '',
        transferBusy: false,

        // ─── Shared methods ───────────────────────────────────────

        toggleTransfer() { this.transferOpen = !this.transferOpen; },

        transferToNumber() {
            const n = (this.transferNumber || '').trim();
            if (n) this._doTransfer({ target_type: 'number', target_number: n });
        },

        transferToAgent(userId) {
            this._doTransfer({ target_type: 'agent', target_user_id: userId });
        },

        /**
         * Blind transfer: tell the server the destination, then drop our own leg
         * so AT re-requests call-control and bridges the customer to the target.
         */
        async _doTransfer(payload) {
            if (this.transferBusy) return;
            this.transferBusy = true;
            try {
                const res = await this.post(`/calls/${this.callId}/transfer`, payload);
                if (!res.ok) {
                    const body = await this.safeReadJson(res);
                    this.errorMessage = body?.error || `Transfer failed (${res.status})`;
                    this.transferBusy = false;
                    return;
                }
                try { window.bqVoiceClient?.hangupCall?.(); } catch (_) { /* SDK gone */ }
                this.transferOpen = false;
                this.teardown?.('transferred');
            } catch (_) {
                this.errorMessage = 'Transfer failed (network).';
                this.transferBusy = false;
            }
        },

        /**
         * POST helper. Sends JSON with the CSRF token and same-origin
         * credentials. Returns the raw Response so callers can inspect
         * status + body. Throws only on network failure (offline /
         * DNS) — HTTP-level errors are status codes on the Response.
         */
        post(url, body) {
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

        /**
         * Best-effort JSON read on a non-OK response. Laravel's debug
         * error pages are HTML, not JSON — naive `await res.json()`
         * throws SyntaxError, masking the real status. Returns null
         * on parse failure so the caller can fall back to a generic
         * message.
         */
        async safeReadJson(res) {
            try { return await res.json(); } catch (_) { return null; }
        },

        /**
         * Render seconds as MM:SS for the in-call duration display.
         * Single-digit seconds left-pad with zero so the timer
         * doesn't visually shift on the 9→10 transition.
         */
        formatDuration(seconds) {
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            return `${m}:${String(s).padStart(2, '0')}`;
        },

        /**
         * Start the duration counter. Idempotent — clears any previous
         * timer first so re-entering 'connected' state (e.g. after a
         * brief network blip) doesn't double-count seconds.
         */
        startDurationTimer() {
            if (this.durationTimer) clearInterval(this.durationTimer);
            this.durationTimer = setInterval(() => this.durationSeconds++, 1000);
        },

        /**
         * Stop the duration counter. Safe to call multiple times.
         */
        stopDurationTimer() {
            if (this.durationTimer) {
                clearInterval(this.durationTimer);
                this.durationTimer = null;
            }
        },
    };
}
