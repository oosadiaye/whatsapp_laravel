import './bootstrap';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import Alpine from 'alpinejs';

// Reverb / Echo bootstrap.
//
// Why Pusher.js even though we run Reverb: laravel-echo speaks the Pusher
// wire protocol, and Reverb is wire-compatible with Pusher. The library
// expects 'pusher' as the broadcaster name; the actual server is Reverb
// listening on our own host. Same protocol, different server.
window.Pusher = Pusher;
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

// userId is read from a meta tag in app.blade.php for channel-naming
// convenience. Naturally absent for guests, so guest pages never try
// to subscribe to a private channel.
const userIdMeta = document.querySelector('meta[name="user-id"]');
window.userId = userIdMeta ? parseInt(userIdMeta.getAttribute('content'), 10) : null;

// Idempotent Alpine bootstrap.
//
// Why the guard: Livewire 4 ships its own Alpine bundle internally and starts
// it via @livewireScripts (which runs synchronously at end of <body>). Our
// @vite-loaded module is `<script type="module">`, which is deferred to after
// document parse — so on Livewire pages, window.Alpine is ALREADY defined
// when this module runs. Without the guard, we'd register a second Alpine
// instance and the browser console shows "Detected multiple instances of
// Alpine running" plus subtle x-data scope conflicts.
//
// Why we still bundle Alpine: pages that don't render any Livewire component
// (e.g. /contacts/import, /instances index, /groups index) never load
// Livewire's Alpine, so we MUST start one ourselves or every x-cloak / @click /
// x-data on those pages silently breaks.
if (typeof window.Alpine === 'undefined') {
    window.Alpine = Alpine;
    Alpine.start();
}

import './calls';
import './outbound-call';

/**
 * RealtimePulse Alpine factory.
 *
 * Mounted on the <div> root of resources/views/livewire/realtime-pulse.blade.php.
 * Consumes data attributes (set by the Blade view after each Livewire poll)
 * to detect new in-flight calls and unread-message deltas, then drives:
 *   - HTML5 Audio playback (with the autoplay-unlock pattern)
 *   - Browser Notification API (with permission handling)
 *
 * State is per-tab — multiple tabs each track their own "what was last seen"
 * counter. (BroadcastChannel-based dedup is a future enhancement.)
 */
window.realtimePulse = () => ({
    seenCallIds: [],
    lastUnread: 0,
    audioUnlocked: false,

    init() {
        // Read initial state from data attributes.
        //
        // NB: seenCallIds is intentionally NOT pre-seeded from the initial
        // payload — we want the first handleUpdate() call (line 86) to treat
        // any already-ringing calls as NEW, so the ringtone + notification
        // fire for an agent who loaded the page mid-call. lastUnread IS
        // pre-seeded so the first poll doesn't notify about already-counted
        // unread messages.
        const data = document.getElementById('bq-realtime-data');
        if (data) {
            try {
                this.lastUnread = parseInt(data.dataset.unread || '0', 10);
            } catch (e) {
                // Malformed payload — treat lastUnread as 0
            }
        }

        // Audio autoplay unlock: latch onto the FIRST user gesture.
        // Browsers block .play() until a user gesture; the muted-play-then-pause
        // dance "unlocks" the element so subsequent .play() calls work freely.
        // We unlock BOTH audio elements (ringtone for calls, ping for messages)
        // in one pass — Promise.all so a failure on one doesn't block the other.
        const unlock = () => {
            const elements = ['bq-ringtone', 'bq-message-ping']
                .map(id => document.getElementById(id))
                .filter(el => el !== null);
            if (elements.length === 0) return;
            Promise.all(elements.map(el => {
                el.muted = true;
                return el.play().then(() => {
                    el.pause();
                    el.muted = false;
                    el.currentTime = 0;
                }).catch(() => {});
            })).then(() => {
                this.audioUnlocked = true;
            });
            // No manual removeEventListener — { once: true } auto-removes after
            // first fire. Note: addEventListener({ once: true }) cleanup happens
            // automatically per the EventListenerOptions spec; both listeners
            // below remove themselves once whichever fires first.
        };
        window.addEventListener('click', unlock, { once: true });
        window.addEventListener('keydown', unlock, { once: true });

        // Notification permission ask, once per device
        if ('Notification' in window
            && Notification.permission === 'default'
            && localStorage.getItem('bq:notification-asked') !== '1') {
            setTimeout(() => {
                Notification.requestPermission().finally(() => {
                    // Guarded against private-mode / quota-exceeded throws.
                    try { localStorage.setItem('bq:notification-asked', '1'); } catch (_) {}
                });
            }, 2000);
        }

        // After every Livewire DOM update, re-read data attrs and dispatch.
        // Livewire 4 dispatches morph.updated through its internal pub/sub system,
        // NOT as a DOM CustomEvent. Use Livewire.hook(...) — addEventListener on
        // document silently never fires (verified by reviewer reading livewire.js
        // internals: trigger2('morph.updated') routes through the hooks `listeners`
        // map, not document.dispatchEvent).
        window.Livewire.hook('morph.updated', () => this.handleUpdate());

        // Run once on mount in case the initial payload already has a call
        this.handleUpdate();
    },

    handleUpdate() {
        const data = document.getElementById('bq-realtime-data');
        if (!data) return;

        let calls = [];
        try { calls = JSON.parse(data.dataset.calls || '[]'); } catch (e) {}

        const currentIds = calls.map(c => c.id);
        const newIds = currentIds.filter(id => !this.seenCallIds.includes(id));

        // New incoming call → ring + (optional) notification
        if (newIds.length > 0) {
            const audio = document.getElementById('bq-ringtone');
            if (audio && this.audioUnlocked) {
                audio.play().catch(() => {});
            }

            // Also fire a desktop notification for the new call (always,
            // not just when tab unfocused — calls are more important than chat)
            if ('Notification' in window && Notification.permission === 'granted') {
                const newCall = calls.find(c => newIds.includes(c.id));
                if (newCall) {
                    const note = new Notification('Incoming call', {
                        body: `${newCall.contact_name || 'Unknown'} · ${newCall.phone}`,
                        icon: '/favicon.ico',
                        tag: 'bq-call-' + newCall.id,
                        requireInteraction: true,
                    });
                    note.onclick = () => {
                        window.focus();
                        window.location.href = '/conversations/' + newCall.conversation_id;
                    };
                }
            }
        }

        // No active calls → stop audio
        if (currentIds.length === 0) {
            const audio = document.getElementById('bq-ringtone');
            if (audio) {
                audio.pause();
                audio.currentTime = 0;
            }
        }

        this.seenCallIds = currentIds;

        // Unread message delta → audible ping (always) + desktop notification (only when tab hidden).
        //
        // Why play sound regardless of focus: original Phase 14.1 assumption was
        // "user is looking, badge update is enough." Real usage showed agents miss
        // the badge because they're attending to other panes/windows. A short
        // audible ping pulls attention to the inbox without requiring the tab
        // to be backgrounded.
        //
        // Why notification stays gated on document.hidden: when the tab IS
        // focused, the audible ping is sufficient. Stacking a desktop notification
        // on top creates double-alerting that users find annoying.
        const currentUnread = parseInt(data.dataset.unread || '0', 10);
        if (currentUnread > this.lastUnread) {
            const delta = currentUnread - this.lastUnread;

            const ping = document.getElementById('bq-message-ping');
            if (ping && this.audioUnlocked) {
                ping.currentTime = 0;
                ping.play().catch(() => {});
            }

            if (document.hidden
                && 'Notification' in window
                && Notification.permission === 'granted') {
                new Notification('New message', {
                    body: `${delta} new message${delta === 1 ? '' : 's'} — total ${currentUnread} unread`,
                    icon: '/favicon.ico',
                    tag: 'bq-message-pulse',
                });
            }
        }
        this.lastUnread = currentUnread;
    },
});
