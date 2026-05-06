# Phase 14.1 — Real-time UX Bundle Design

**Date:** 2026-05-06
**Phase:** 14.1 (small, ship-this-week feature bundle)
**Builds on:** Voice Calls Phase A (`call_logs` + `InboundCallProcessor` + `InFlightCall` Livewire component) + Inbox Phase 13.1 (contact-list call/chat buttons)
**Defers to:** Phase 14.2 (round-robin auto-assignment + agent presence) and Phase 14.3 (WebSocket push if polling proves insufficient)

## What we're building

Three real-time UX features that together make BlastIQ feel like a live messaging tool instead of a refresh-the-page workflow:

1. **Global inbound-call banner** — when a customer calls one of your numbers, every eligible agent's browser shows a sticky banner at the top of the layout with caller phone, name (if known), and instance the call is ringing on. Banner persists until the call transitions to a terminal state.
2. **In-browser ringing tone** — a bundled WhatsApp-style ring plays while the call is ringing. Stops when the call ends or the user dismisses the banner.
3. **Browser notifications for new chat messages** — when an agent's tab isn't focused and a new inbound message arrives on a conversation they can see, the OS-level notification fires (Notification API). Click → focuses the tab + opens the conversation.

All three share a single underlying mechanism: a new Livewire component (`RealtimePulse`) mounted on the layout that polls every 3 seconds and dispatches Alpine/JS events for the audio + notification side effects.

**In scope (Phase 14.1):**
- New `App\Livewire\RealtimePulse` component on `<x-app-layout>`
- Sticky top banner with caller info + open/dismiss actions
- Bundled ringtone audio (`public/audio/incoming-call.mp3`) with autoplay-unlock pattern
- Browser Notification API integration with permission flow
- Permission-aware payload (respects `conversations.view_all` vs `conversations.view_assigned`)
- 6-8 PHPUnit tests for the component's payload

**Out of scope (deferred):**
- 🎯 Round-robin auto-assignment of inbound calls (Phase 14.2)
- 🟢 Agent presence (online/away/busy) (Phase 14.2)
- 🔌 WebSocket push (Phase 14.3 — only if polling proves insufficient)
- 📞 In-browser actual phone audio (Meta limitation — requires SIP trunk, much later)
- 📱 Mobile push via Service Workers / Push API (separate later phase)
- 🔔 Per-user customizable ringtone (just ship the default)
- 🎨 Visual customization of the banner (no per-tenant theming)

## Why now (architectural context)

The codebase already has the polling pattern in production (`InFlightCall` polls every 3s on the conversation thread for the user's own outbound calls). What's missing is a GLOBAL surface — visible from any page — for INBOUND calls to ANY agent. The current per-page InFlightCall is good for outbound; this phase elevates the same pattern to layout level for inbound.

Architectural decisions made during brainstorming, recorded here:

- **Polling not WebSockets.** WebSocket push would need Laravel Reverb (or paid Pusher), running as a separate daemon. The production server doesn't have supervisord, so process management is already manual; adding a Reverb daemon doubles the ops surface. Polling reuses the proven `wire:poll` pattern with zero new infrastructure. Latency at 3s is fine for calls (which ring 30+ seconds) and chat (where users tab back anyway).
- **Sticky top banner not modal/toast.** Modal blocks work; toast is too easy to miss. Sticky banner is the Slack/Gmail "you have new messages" pattern: visible from any page, doesn't trap interaction, well-validated UX.
- **Single combined poll, not separate polls per feature.** One `RealtimePulse` component returns both in-flight call data AND unread message counts in one payload. Lower server load + simpler client state.
- **Visual + audible alerts share the same component.** The banner element owns both the markup AND the `<audio>` tag, so they can't get out of sync. One source of truth for "is there an inbound call right now?"

## Architecture

### High-level flow

```
INBOUND CALL
                                  ┌──────────────────┐
   Customer dials                 │ Meta Cloud       │
   business number ─────────────▶│ Calling Infra    │ ──▶ POST /webhooks/whatsapp/{instance}
                                  └──────────────────┘                            │
                                                                                  ▼
                                                                     CloudWebhookController
                                                                                  │
                                                                                  ▼
                                                                     InboundCallProcessor
                                                                                  │
                                                                                  ▼
                                                              CallLog row (status='ringing')
                                                                                  │
                       ┌──────────────────────────────────────────────────────────┘
                       │  [3s after creation, ALL agents' browsers poll]
                       ▼
            RealtimePulse.render() — returns:
              { inflightCalls: [{call_id, contact_name, phone, instance_name, conversation_id}],
                unreadMessages: 7 }
                       │
                       ▼
            Livewire diffs the payload, updates the DOM
                       │
                       ├──▶ Banner becomes visible (was hidden)
                       │      → Alpine fires `bq:incoming-call` event
                       │      → JS handler plays audio.play()
                       │
                       └──▶ unreadMessages went from 6 → 7
                              → Alpine fires `bq:new-message` event
                              → JS handler does new Notification(...) if document.hidden

CHAT MESSAGE
                                  ┌──────────────────┐
   Customer sends                 │ Meta Cloud       │
   message  ─────────────────────▶│ Messages Infra   │ ──▶ POST /webhooks/whatsapp/{instance}
                                  └──────────────────┘                            │
                                                                                  ▼
                                                                     InboundMessageProcessor
                                                                                  │
                                                                                  ▼
                                                                ConversationMessage row created
                                                                  + Conversation.unread_count++
                       │  [3s later, agent's poll picks it up via RealtimePulse]
                       ▼
            unreadMessages count delta → bq:new-message event → Notification API
```

### New Livewire component: `App\Livewire\RealtimePulse`

```php
<?php

namespace App\Livewire;

use App\Models\CallLog;
use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class RealtimePulse extends Component
{
    /**
     * Returns a unified payload of "things the agent should know about right now."
     *
     * Wrapped in a single Livewire component so the browser fires ONE poll per
     * 3-second cycle, not three separate ones (calls + messages + presence).
     * The render() output is a Blade view; Alpine handlers in that view dispatch
     * native CustomEvents that JS listeners (in resources/js/app.js) consume.
     */
    public function render()
    {
        $user = Auth::user();
        if ($user === null) {
            return view('livewire.realtime-pulse', ['inflightCalls' => [], 'unreadMessages' => 0]);
        }

        // In-flight inbound calls visible to this user, scoped by permission.
        $callQuery = CallLog::query()
            ->where('direction', 'inbound')
            ->whereIn('status', ['ringing', 'connected'])
            ->where('created_at', '>=', now()->subMinutes(30))
            ->with(['contact', 'conversation', 'whatsappInstance']);

        if ($user->can('conversations.view_all')) {
            $callQuery->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id));
        } else {
            // view_assigned only: must be assigned to me OR unassigned (anyone-takes-it).
            $callQuery->whereHas('conversation', fn ($q) =>
                $q->where(fn ($qq) =>
                    $qq->where('assigned_to_user_id', $user->id)
                       ->orWhereNull('assigned_to_user_id')
                )
            );
        }

        $inflightCalls = $callQuery->latest()->limit(3)->get()->map(fn ($call) => [
            'id' => $call->id,
            'conversation_id' => $call->conversation_id,
            'contact_name' => $call->contact->name ?? null,
            'phone' => $call->from_phone,
            'instance_name' => $call->whatsappInstance->display_name ?? $call->whatsappInstance->instance_name,
            'status' => $call->status,
            'started_at' => $call->started_at?->toIso8601String(),
        ])->values();

        // Unread message count across visible conversations.
        $messageQuery = Conversation::query();
        if ($user->can('conversations.view_all')) {
            $messageQuery->where('user_id', $user->id);
        } else {
            $messageQuery->where(fn ($q) =>
                $q->where('assigned_to_user_id', $user->id)
                  ->orWhereNull('assigned_to_user_id')
            );
        }
        $unreadMessages = (int) $messageQuery->sum('unread_count');

        return view('livewire.realtime-pulse', [
            'inflightCalls' => $inflightCalls,
            'unreadMessages' => $unreadMessages,
        ]);
    }
}
```

### View template

`resources/views/livewire/realtime-pulse.blade.php`:

```blade
<div wire:poll.3s
     x-data="realtimePulse(@js($inflightCalls), {{ $unreadMessages }})">

    {{-- Sticky top banner — slides down when there's an in-flight call --}}
    @forelse($inflightCalls as $call)
        <div class="sticky top-0 z-40 bg-emerald-600 text-white px-4 py-3 shadow-md flex items-center justify-between"
             x-show="active.includes({{ $call['id'] }})" x-transition>
            <div class="flex items-center gap-3">
                <span class="text-xl animate-pulse">📞</span>
                <div>
                    <div class="font-semibold">
                        Incoming call from {{ $call['contact_name'] ?? 'Unknown' }}
                    </div>
                    <div class="text-xs text-emerald-100 font-mono">
                        {{ $call['phone'] }} · ringing on {{ $call['instance_name'] }}
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('conversations.show', $call['conversation_id']) }}"
                   class="bg-white text-emerald-700 px-3 py-1.5 rounded-md text-sm font-medium hover:bg-emerald-50">
                    Open conversation →
                </a>
                <button @click="dismiss({{ $call['id'] }})"
                        class="text-emerald-100 hover:text-white px-2 text-xl"
                        aria-label="Dismiss">✕</button>
            </div>
        </div>
    @empty
        {{-- No banner when nothing's ringing --}}
    @endforelse

    {{-- Hidden audio element. Loaded once, played by Alpine on incoming-call event. --}}
    <audio x-ref="ringtone" preload="auto" loop>
        <source src="{{ asset('audio/incoming-call.mp3') }}" type="audio/mpeg">
    </audio>
</div>
```

### Alpine + JS handlers (in `resources/js/app.js`)

```js
window.realtimePulse = (initialCalls, initialUnread) => ({
    active: initialCalls.map(c => c.id),
    lastUnread: initialUnread,
    audioUnlocked: false,
    permissionAsked: localStorage.getItem('bq:notification-asked') === '1',

    init() {
        // Audio autoplay unlock: latch onto the FIRST user gesture after page load.
        // Browsers require a user interaction before audio.play() will work.
        const unlock = () => {
            const audio = this.$refs.ringtone;
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

        // Permission ask on first visit (one-shot)
        if (!this.permissionAsked && 'Notification' in window && Notification.permission === 'default') {
            // Defer to after layout settles so we don't compete with banners
            setTimeout(() => this.askNotificationPermission(), 2000);
        }

        // Watch for inflightCalls changes (Livewire updates the prop reactively)
        Livewire.on('realtimePulseUpdated', () => this.handlePoll());
    },

    handlePoll() {
        // Compare the server's call list to what we already showed
        const serverCalls = JSON.parse(this.$root.dataset.calls || '[]');
        const newCalls = serverCalls.filter(c => !this.active.includes(c.id));

        if (newCalls.length > 0 && this.audioUnlocked) {
            this.$refs.ringtone.play().catch(() => {});
        }

        // Sync active set
        this.active = serverCalls.map(c => c.id);

        // Stop ringtone if no calls left
        if (this.active.length === 0) {
            this.$refs.ringtone.pause();
            this.$refs.ringtone.currentTime = 0;
        }

        // New unread message? Fire desktop notification if window unfocused.
        const serverUnread = parseInt(this.$root.dataset.unread || '0', 10);
        if (serverUnread > this.lastUnread && document.hidden && Notification.permission === 'granted') {
            new Notification('New message', {
                body: `You have ${serverUnread} unread message${serverUnread === 1 ? '' : 's'}.`,
                icon: '/favicon.ico',
            });
        }
        this.lastUnread = serverUnread;
    },

    dismiss(callId) {
        this.active = this.active.filter(id => id !== callId);
        this.$refs.ringtone.pause();
        this.$refs.ringtone.currentTime = 0;
    },

    askNotificationPermission() {
        if (!('Notification' in window)) return;
        Notification.requestPermission().finally(() => {
            localStorage.setItem('bq:notification-asked', '1');
        });
    },
});
```

### Layout integration

`resources/views/layouts/app.blade.php`, immediately inside `<body>`, before the existing sidebar/topbar:

```blade
@auth
    <livewire:realtime-pulse />
@endauth
```

The `@auth` guard means anonymous visitors (e.g., the login page) don't fire the poll.

### Permission scoping

The component already references `$user->can('conversations.view_all')` directly. Both feature surfaces respect:

- **`conversations.view_all`** (super_admin / admin / manager): sees in-flight calls + unread counts across the entire account
- **`conversations.view_assigned`** (agent role): sees only conversations assigned to them OR unassigned (the inbox shared-pool pattern)
- **Neither permission**: empty payload — the component renders nothing, no banner, no notifications

This matches the existing inbox visibility semantics — no new permission needed.

## Audio asset

A 4-second CC0-licensed phone-ring loop will be saved to `public/audio/incoming-call.mp3`. Approximate size: 12-20 KB. The file is committed to the repo (small enough not to bloat) and not referenced by `@vite()` (it's served as a static asset directly).

Sourcing: a public-domain phone-ring sample (e.g., from Freesound.org under CC0). Implementation note: when grabbing the file, verify the loop point doesn't have audible click/pop.

## Edge cases

- **Multiple simultaneous in-flight calls** → server returns up to 3 (`->limit(3)`). View renders all three banners stacked. Beyond 3, payload omits — extremely rare for a small team.
- **Stale ringing call** (status never transitioned past `ringing`) → server filter `created_at >= now()->subMinutes(30)` auto-clears. Matches Voice Phase A's banner-hide rule.
- **Multiple browser tabs from the same agent** → all tabs poll independently and ring. User can dismiss any one tab; ringtone resumes on next tab unless dismissed everywhere. (Future: `BroadcastChannel` could sync, but YAGNI for now.)
- **Backgrounded tab (Chrome throttles polling)** → wire:poll continues but slows down to once-per-minute when tab is hidden. Notification API still fires; this is fine since the agent isn't watching the tab anyway.
- **Audio unlock not granted** (user navigates without ever clicking) → audio.play() throws silently; banner still visible. Degrades gracefully.
- **Permission denied** → notifications never fire; in-app unread badge still shows. Don't re-ask.
- **No conversations to scope to** (new account) → empty payload, no banner, no notifications. No errors.
- **Polling failure** (server 500, network blip) → Livewire's wire:poll auto-retries on next interval. Brief gap in detection, but state corrects within 3-6s.

## Testing strategy

`Tests/Feature/Livewire/RealtimePulseTest.php` — 6-8 PHPUnit + Livewire tests:

1. **`test_returns_empty_payload_for_unauthenticated_user`** — render without auth → empty arrays, no error
2. **`test_admin_sees_inflight_call_for_their_account`** — pre-create CallLog with status='ringing' belonging to admin's user_id → assert payload contains the call with correct fields
3. **`test_admin_does_not_see_calls_from_other_accounts`** — create CallLog for userB → render as userA → empty
4. **`test_agent_sees_calls_only_on_assigned_or_unassigned_conversations`** — agent role + 3 conversations (one assigned to them, one to someone else, one unassigned) → payload includes the assigned + unassigned, not the third
5. **`test_excludes_calls_older_than_30_minutes`** — pre-create call with `created_at = 31min ago, status='ringing'` → not in payload
6. **`test_includes_only_inbound_calls`** — outbound CallLog with status='ringing' → not in payload
7. **`test_excludes_terminal_status_calls`** — status='ended'/'missed'/'declined'/'failed' → not in payload
8. **`test_unread_message_count_sums_visible_conversations`** — three conversations with unread_count=3, 5, 2 → total=10 for admin

JS-level tests (audio playback, notification firing) require browser automation; documented as a manual smoke-test checklist in the spec instead of automated.

## Manual smoke-test checklist

After deploy:
1. Login as admin in two browser windows (or browser+incognito)
2. Trigger a fake inbound call: `php artisan tinker` → `App\Models\CallLog::factory()->create(['direction' => 'inbound', 'status' => 'ringing', ...])`
3. Within 3-6 seconds, both windows show the banner with caller info
4. Click anywhere on page → audio plays (autoplay-unlocked)
5. Update CallLog status to 'ended' → banner disappears in both windows; audio stops
6. Send a fake inbound message via tinker → unread count goes up
7. Tab away, send another message → desktop notification appears
8. Click notification → tab focuses + opens conversation

## Acceptance criteria

- [ ] When a customer calls, all eligible agents see the banner within 3-6 seconds
- [ ] Caller phone number always visible; contact name shown when present in DB
- [ ] Banner shows which WhatsApp number the call is ringing on (instance display name)
- [ ] Ringtone plays after first user gesture; loops while ringing; stops on terminal status or dismiss
- [ ] Browser notification fires for new inbound messages when tab is unfocused
- [ ] Permission prompt appears once on first visit; declined state persists across sessions
- [ ] Banner respects `view_all` vs `view_assigned` permissions
- [ ] No regression on existing 174 tests; +6-8 new tests pass
- [ ] Spec self-review clean, plan written, push to origin/main

## Open questions / verifications during implementation

- **Audio asset license** — pick a CC0 source confirmed at implementation time; record the URL in the file's commit message for provenance.
- **Notification permission persistence** — `localStorage.getItem('bq:notification-asked')` survives logout/login on the same device but doesn't sync across devices. That's fine — Browser Notification API is per-device anyway.
- **Tab-throttle behavior on Firefox vs Chrome** — both throttle hidden-tab polling but to different intervals. Notifications still fire correctly on both; document expected timing in the smoke-test checklist.
- **High-frequency call scenario** (rare for a single business): banner stacking limited to 3. If a busy team needs more, Phase 14.2's auto-assignment handles distribution.

## Future phases (deferred, related)

- **Phase 14.2** — round-robin auto-assignment + agent presence model. The `RealtimePulse` poll already returns the user's "did the agent see this call?" state; that becomes the basis for "this call has been visible for >30s, no one took it, route to next agent" logic.
- **Phase 14.3** — WebSocket push if the polling becomes a measurable problem (latency complaints from agents OR server CPU climbing). Swap is mostly transparent: same payload, different transport. UI components don't change.
- **Phase 15** — per-user notification preferences (mute hours, sound choice, vibration patterns).
