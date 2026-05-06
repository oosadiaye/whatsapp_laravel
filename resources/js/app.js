import './bootstrap';

import Alpine from 'alpinejs';

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
        // Read initial state from data attributes
        const data = document.getElementById('bq-realtime-data');
        if (data) {
            try {
                const calls = JSON.parse(data.dataset.calls || '[]');
                this.seenCallIds = calls.map(c => c.id);
                this.lastUnread = parseInt(data.dataset.unread || '0', 10);
            } catch (e) {
                // Malformed payload — treat as empty
            }
        }

        // Audio autoplay unlock: latch onto the FIRST user gesture
        const unlock = () => {
            const audio = document.getElementById('bq-ringtone');
            if (!audio) return;
            audio.muted = true;
            audio.play().then(() => {
                audio.pause();
                audio.muted = false;
                audio.currentTime = 0;
                this.audioUnlocked = true;
            }).catch(() => {});
            window.removeEventListener('click', unlock);
            window.removeEventListener('keydown', unlock);
        };
        window.addEventListener('click', unlock, { once: true });
        window.addEventListener('keydown', unlock, { once: true });

        // Notification permission ask, once per device
        if ('Notification' in window
            && Notification.permission === 'default'
            && localStorage.getItem('bq:notification-asked') !== '1') {
            setTimeout(() => {
                Notification.requestPermission().finally(() => {
                    localStorage.setItem('bq:notification-asked', '1');
                });
            }, 2000);
        }

        // After every Livewire DOM update, re-read data attrs and dispatch
        document.addEventListener('livewire:morph.updated', () => this.handleUpdate());

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

        // Unread message delta → notification IF tab unfocused
        const currentUnread = parseInt(data.dataset.unread || '0', 10);
        if (currentUnread > this.lastUnread
            && document.hidden
            && 'Notification' in window
            && Notification.permission === 'granted') {
            const delta = currentUnread - this.lastUnread;
            new Notification('New message', {
                body: `${delta} new message${delta === 1 ? '' : 's'} — total ${currentUnread} unread`,
                icon: '/favicon.ico',
                tag: 'bq-message-pulse',
            });
        }
        this.lastUnread = currentUnread;
    },
});
