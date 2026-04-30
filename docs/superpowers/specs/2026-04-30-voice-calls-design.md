# Voice Calls — Phase A Design

**Status:** Draft, pending user review
**Author:** brainstorming session 2026-04-30
**Scope:** Phase A only — foundation + click-to-call. Scheduling and calendar deferred to a future phase.

---

## What we're building

A first-class voice-call surface for BlastIQ, integrated directly with Meta's WhatsApp Cloud Calling API (no third-party telephony provider). Phase A delivers three things:

1. **Inbound + outbound call logging** — every call event Meta sends us via webhook is captured in a new `call_logs` table, linked to the existing conversation thread.
2. **Click-to-call from the chat header** — a green call button next to the contact name in the conversation thread; click → confirmation modal → outbound API request to Meta → in-flight status banner.
3. **Cross-conversation `/calls` page** — sidebar nav item showing every call across all conversations, filterable by status/direction/date, useful for managers reviewing call activity.

What's intentionally **out of scope** for Phase A (and why):

- **Call scheduling + calendar UI** — deferred. Adds 1-2 weeks. Phase A ships in a week.
- **SIP trunk for multi-agent audio routing** — deferred. Meta's audio terminates on the registered Business app device, which is fine for solo / small-team businesses. Multi-agent voice routing is a future phase.
- **Call recording playback** — Meta's API has recording capability but Phase A treats calls as ephemeral. Recordings can be added later without schema changes.
- **IVR / interactive voice menus** — Phase B+.
- **Voice broadcasts (bulk dialing)** — requires verifying Meta's outbound rate limits. Phase B+ once a customer requests it.
- **Call buttons inside marketing templates** — separate template editor surface; Phase B.

## Why now (and what changed since the original brainstorm)

The original conversation in this session assumed we'd need a third-party voice provider (Twilio, Vonage, Telnyx, or Africa's Talking). On 2026-04-30 the user confirmed they'd successfully activated **Meta's WhatsApp Cloud Calling API** on their Business account, with the `calls` webhook field subscribed and "Allow voice calls" enabled. This collapses the architecture: voice becomes another verb on the existing `WhatsAppCloudApiService` rather than a parallel integration with a separate billing relationship.

## Architecture

### High-level flow

```
INBOUND
                        ┌─────────────────┐
   Customer taps        │ Meta Cloud      │
   call button on  ───▶ │ Calling Infra   │ ──▶  POST /webhooks/whatsapp/{instance}
   business profile     │                 │       (field=calls, value.calls[]={id,event,from,to,timestamp})
                        └─────────────────┘
                                │
                                ▼
                        CloudWebhookController::handle()
                                │
                                ├── messages → InboundMessageProcessor (existing)
                                └── calls    → InboundCallProcessor (NEW)
                                                  │
                                                  ▼
                              find/create Contact + Conversation + call_log row
                                                  │
                                                  ▼
                              UI updates via Livewire wire:poll.3s


OUTBOUND
   Agent clicks 📞 button in chat header
                        │
                        ▼
   ConversationController::initiateCall (NEW)
                        │ permission gate: conversations.call
                        ▼
   OutboundCallService::initiate (NEW)
                        │
                        ▼
   POST graph.facebook.com/v20.0/{phone_number_id}/calls (Meta Cloud API)
                        │
                        ▼
   Create call_log row, status='initiated', meta_call_id from response
                        │
                        ▼
   Subsequent webhook events update the same row (ringing → connected → ended)
```

### Why a separate `call_logs` table (not extending `conversation_messages`)

Calls are state machines, not messages. A single call produces 3-5 webhook events (connect → accept → disconnect, possibly with intermediate state changes). Forcing them into `conversation_messages` either creates noisy threads (multiple rows per call) or loses event-by-event audit trail (overwriting one row destroys history). The mismatch grows worse when adding recordings, IVR transcripts, or call analytics.

Trade-off accepted: chat thread view needs a UNION query to merge calls + messages chronologically. We pay this complexity once; the schema benefit pays back forever. Same pattern Phase 12 used for `message_logs` (analytics) being separate from `conversation_messages` (chat threads).

### Why every call links to a Conversation (NOT NULL FK)

Calls are conversation events. A call from a previously-unknown number IS the start of a conversation, even with zero text messages preceding. We reuse `find-or-create Conversation` logic from Phase 12's `InboundMessageProcessor`. Existing Conversation features (assigned_to_user_id, last_message_at, unread_count) automatically apply to calls — no duplicate logic needed. The "first-ever-call from unknown" case looks fine in the inbox (a thread that starts with a call card instead of a message bubble).

## Data model

### New table: `call_logs`

```sql
CREATE TABLE call_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Always present, NOT NULL by Q4's decision
    conversation_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    whatsapp_instance_id BIGINT UNSIGNED NOT NULL,

    direction ENUM('inbound', 'outbound') NOT NULL,

    -- Meta's call ID. Unique across all our calls. Nullable for outbound calls
    -- between API request and Meta's response coming back.
    meta_call_id VARCHAR(255) NULL UNIQUE,

    status ENUM(
        'initiated',  -- outbound: API request fired, awaiting Meta confirmation
        'ringing',    -- ring received on the customer side
        'connected',  -- accepted by either party
        'ended',      -- normal hang-up after connection
        'missed',     -- ringing timeout, no answer
        'declined',   -- explicit reject by recipient
        'failed'      -- API/network error before ringing
    ) NOT NULL DEFAULT 'initiated',

    from_phone VARCHAR(20) NOT NULL,  -- E.164 (digits only)
    to_phone VARCHAR(20) NOT NULL,

    started_at TIMESTAMP NULL,        -- first event time
    connected_at TIMESTAMP NULL,      -- when audio connected
    ended_at TIMESTAMP NULL,          -- hang-up time
    duration_seconds INT UNSIGNED NULL,  -- ended_at - connected_at

    failure_reason TEXT NULL,         -- populated for declined/failed/missed

    -- Outbound only: who clicked the call button. NULL for inbound.
    placed_by_user_id BIGINT UNSIGNED NULL,

    -- Append-only event log: every webhook payload Meta sent for this call,
    -- in chronological order. Used for debugging and the "expand call card"
    -- timeline view. JSON shape: [{event, timestamp, raw_payload}, ...]
    raw_event_log JSON NULL,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (whatsapp_instance_id) REFERENCES whatsapp_instances(id) ON DELETE CASCADE,
    FOREIGN KEY (placed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_conversation_created (conversation_id, created_at),
    INDEX idx_instance_status (whatsapp_instance_id, status, created_at),
    INDEX idx_meta_call_id (meta_call_id)
);
```

**Index rationale:**
- `(conversation_id, created_at)` — drives chat thread view's call query
- `(whatsapp_instance_id, status, created_at)` — drives /calls page filters
- `meta_call_id` — webhook idempotency (lookup by Meta ID to dedupe retries)

### Status state machine

```
              ┌─────────┐
   OUTBOUND ──┤initiated├──┐
              └─────────┘  │
                           ▼
                       ┌───────┐ ──── ringing-timeout ───▶ ┌──────┐
                       │ringing├──── customer rejects ───▶ │missed│
                       └───────┘ ──── customer accepts ──┐ └──────┘
                                                          │ ┌────────┐
                                                          └▶│declined│
                                                            └────────┘
                                                            OR
                                                          ┌──────────┐
                                            connected ───▶│connected │──── hang-up ───▶ ┌─────┐
                                                          └──────────┘                  │ended│
                                                                                        └─────┘

   INBOUND  ──┐
              ▼
              (skip 'initiated', start at 'ringing' on first webhook event)


   ANY → 'failed' if Meta's API returns an error before connection
```

## Webhook handling

Existing `CloudWebhookController::handle()` (Phase 3) walks `entry[].changes[].value` and dispatches by `field`. Currently handles `field === 'messages'`. We extend it:

```php
// in handle()
foreach ((array) ($entry['changes'] ?? []) as $change) {
    $value = (array) ($change['value'] ?? []);

    match ($change['field'] ?? null) {
        'messages' => [
            $this->processStatuses($value['statuses'] ?? []),
            $this->inboundProcessor->processMessages($instance, $value['messages'] ?? [], $value['contacts'] ?? []),
        ],
        'calls' => $this->inboundCallProcessor->processCalls($instance, $value['calls'] ?? [], $value['contacts'] ?? []),
        default => null,
    };
}
```

### New service: `InboundCallProcessor`

Mirrors `InboundMessageProcessor`'s structure. Key responsibilities:

- For each call event in `value.calls[]`:
  - **Find or create Contact** by phone (matching `from`)
  - **Find or create Conversation** for `(contact, instance)` — reuse Phase 12 logic
  - **Find or create call_log** by `meta_call_id` (idempotent — Meta retries are no-ops)
  - **Update state machine** based on the event's `event` field (`connect`, `accept`, `disconnect`, etc.)
  - **Append** the raw event payload to `raw_event_log` JSON column

Idempotency: every event lookup uses `meta_call_id` as the key. A duplicate webhook (Meta retry on slow ack) updates the same row — no double-counting.

### Webhook event → state mapping

```
Meta webhook event           call_logs.status              other side effects
─────────────────────        ──────────────────            ──────────────────
'connect' (inbound)          ringing                       set started_at
'connect' (outbound)         ringing                       (already exists in 'initiated' state)
'accept' / 'connect_complete' connected                     set connected_at
'disconnect'                 ended                          set ended_at, calculate duration_seconds
'missed' / 'no_answer'       missed                         set ended_at, no connected_at
'reject' / 'declined'        declined                       set failure_reason
'fail' / 'error'             failed                         set failure_reason from event payload
```

Exact event names depend on Meta's actual webhook payload (the user's screenshot showed `"event": "connect"` as a sample but the full enum is in their docs). The processor will handle unknown events by appending to `raw_event_log` and warning in Laravel log without changing status.

## Outbound call flow

### New service: `OutboundCallService`

```php
class OutboundCallService
{
    public function __construct(
        private readonly WhatsAppCloudApiService $cloudApi,
    ) {}

    public function initiate(
        WhatsAppInstance $instance,
        Contact $contact,
        User $placedBy,
    ): CallLog {
        // Validate instance is Cloud-Ready (existing check from Phase 8)
        // POST /v20.0/{phone_number_id}/calls
        //   { "messaging_product": "whatsapp", "to": "<contact_phone>" }
        // On success: response includes call_id (Meta's wamid-equivalent)
        // Create call_log row with status='initiated', meta_call_id, placed_by_user_id

        // On failure: throw WhatsAppApiException with the error from Meta
    }
}
```

The actual Meta endpoint shape may need verification during implementation (the screenshot showed the webhook side, not the outbound API). The service abstracts this so the controller doesn't change if Meta's URL pattern is `/calls/initiate` or similar. **TODO during implementation**: verify exact endpoint from Meta's Calling API documentation.

### Controller extension: `ConversationController::initiateCall`

```php
// route: POST /conversations/{conversation}/call
// gated by: middleware('permission:conversations.call')
public function initiateCall(Conversation $conversation): RedirectResponse
{
    // Existing authorizeConversationAccess() guard from Phase 13
    $callLog = $this->outboundCallService->initiate(
        $conversation->whatsappInstance,
        $conversation->contact,
        Auth::user(),
    );

    return redirect()
        ->route('conversations.show', $conversation)
        ->with('success', "Calling {$conversation->contact->name}...");
}
```

The redirect (instead of JSON response) means the page reloads with the in-flight banner already showing — simpler than a JS-driven update for the initial state.

## UI components

### Chat thread header — call button

Visible only when `@can('conversations.call')`. Replaces the existing "back arrow + name" header with a three-section header:

```
┌──────────────────────────────────────────────────┐
│  ← Back  │  Jane Doe                  📞  ⋮       │
│          │  +234 800 555 0123                    │
└──────────────────────────────────────────────────┘
        title section            actions section
```

The 📞 button opens the **confirmation modal**.

### Confirmation modal

Standard Alpine modal pattern (matches existing groups create modal):

```
┌─────────────────────────────────────────┐
│ Call Jane Doe?                          │
│                                         │
│ Number: +234 800 555 0123               │
│ From: Customer Support (your business)  │
│                                         │
│ This will count toward your daily       │
│ Meta call quota.                        │
│                                         │
│              [Cancel]  [📞 Call now]    │
└─────────────────────────────────────────┘
```

Submitting "Call now" POSTs to `/conversations/{c}/call`. Server-side handles the API request and creates the call_log row before redirecting.

### In-flight banner

Sticky at top of conversation thread when there's a call_log with status in `['initiated', 'ringing', 'connected']` AND `placed_by_user_id === auth()->id()` AND `created_at` within last 30 minutes (so old hung calls don't show indefinitely).

Banner content based on status:
- **initiated**: "Connecting to Meta..." with spinner
- **ringing**: "Calling Jane Doe... [End call]" with phone-ringing animation
- **connected**: "Call in progress · 0:42 [End call]" with live timer
- **ended/missed/declined/failed**: banner fades within 5 seconds, replaced by inline call card in thread

Updates via Livewire `wire:poll.3s` — same pattern as `CampaignStatus` from earlier phases. No broadcasting infrastructure needed.

The "End call" button POSTs to `/conversations/{c}/calls/{call}/end`. Server-side handler:

1. Validates the call_log row exists, belongs to the user's account, and is currently in `ringing` or `connected` state
2. Calls Meta's hang-up API (likely `POST /v20.0/{phone_number_id}/calls/{call_id}` with action='hangup', or DELETE on the call resource — exact shape TBD during implementation)
3. Optimistically updates call_log status to `ended` and sets `ended_at = now()`. Subsequent webhook event from Meta confirming hang-up is a no-op (idempotent — same status applied twice).
4. Failure (Meta rejects the hang-up): leave call_log status unchanged, surface error to user. The call may still actually be connected on Meta's side; user can retry or wait for natural disconnect webhook.

### Inline call cards (in conversation thread)

Between message bubbles, sorted chronologically with messages by `created_at`. Card shows:

```
─────────────────────────────────────
   📞 Outbound call · 4m 32s · 14:23
   Connected → Ended
─────────────────────────────────────
```

Card colors:
- Green: connected calls (ended normally)
- Amber: missed
- Red: failed/declined
- Blue: in-flight (when status is ringing/connected on a call still happening)

Click expands to reveal `raw_event_log` timeline (debugging aid for support).

### `/calls` page — cross-conversation feed

New sidebar item under Overview, between Inbox and Templates. Visible to users with `conversations.view_all` OR `conversations.view_assigned` (mirrors inbox visibility).

Layout:

```
┌─────────────────────────────────────────────────────────┐
│  Calls                                  [Filter ▼]      │
├─────────────────────────────────────────────────────────┤
│ All / Today / This week / Inbound / Outbound / Missed  │
├─────────────────────────────────────────────────────────┤
│ 14:23  📞→ Jane Doe (+234...) · 4m 32s · CS Line  →    │
│ 14:18  📞← Bob Smith (+234...) · MISSED · CS Line  →   │
│ 13:55  📞→ Alice (+234...) · 1m 04s · Sales Line  →    │
│ ...                                                     │
└─────────────────────────────────────────────────────────┘
```

Each row links to the originating conversation. Filter chips trigger query param updates (e.g. `?direction=inbound&status=missed`).

Visibility scope (Phase 13 pattern):
- `conversations.view_all` users see all calls in their account
- `conversations.view_assigned` users see only calls in conversations assigned to them

### Sidebar nav update

Add to `Overview` section in `resources/views/layouts/navigation.blade.php`:

```blade
@canany(['conversations.view_all', 'conversations.view_assigned'])
    <x-sidebar-link :href="route('calls.index')" :active="request()->routeIs('calls.*')">
        <svg>📞 icon</svg>
        {{ __('Calls') }}
    </x-sidebar-link>
@endcanany
```

## Permissions

Add one new permission to `RolesAndPermissionsSeeder`:

```php
'conversations.call'  // initiate outbound calls
```

Default role grants:

```
super_admin   ✓
admin         ✓
manager       ✓
agent         ✗  (must be added explicitly per user via Users → Edit)
```

Reasoning (from Q5): every outbound call has real Meta API cost. Defaulting agent to NOT have call ability protects against accidental cost escalation by junior staff. Easy to grant per-user via existing UserController role-edit flow.

## Routes

```php
// routes/web.php — under existing auth+verified middleware group

// Per-conversation call action (gated by conversations.call permission)
Route::middleware('permission:conversations.call')->group(function () {
    Route::post('/conversations/{conversation}/call',
        [ConversationController::class, 'initiateCall'])
        ->name('conversations.initiateCall');

    Route::post('/conversations/{conversation}/calls/{call}/end',
        [ConversationController::class, 'endCall'])
        ->name('conversations.endCall');
});

// Cross-conversation calls feed
Route::middleware('role_or_permission:conversations.view_all|conversations.view_assigned')
    ->group(function () {
        Route::get('/calls', [CallController::class, 'index'])->name('calls.index');
    });
```

## Testing strategy

Three new test files, ~15-20 tests total:

### `Tests\Feature\Webhooks\InboundCallProcessingTest`
- First inbound call creates contact + conversation + call_log
- Subsequent webhook events update the same call_log (idempotency by meta_call_id)
- Multiple events for one call append to raw_event_log
- Inbound from existing contact reuses conversation
- 'connect' → 'accept' → 'disconnect' transitions update status correctly
- 'missed' webhook sets status without connected_at
- Malformed payload (missing call ID) silently dropped, no crash

### `Tests\Feature\Controllers\OutboundCallTest`
- Admin can initiate call (POST /conversations/{c}/call)
- Agent without `conversations.call` permission gets 403
- Outbound call creates call_log with `placed_by_user_id` set, `direction='outbound'`, `status='initiated'`
- Cross-account call attempt is forbidden
- Meta API failure returns user-facing error, no call_log row created

### `Tests\Feature\Controllers\CallsPageTest`
- /calls index renders for users with view_all permission
- Agent without view_all sees only calls in their assigned conversations
- Filter by direction/status/date works
- User with no chat permissions gets 403

## Migration order

1. Add `conversations.call` permission to `RolesAndPermissionsSeeder`
2. Create `call_logs` table migration
3. Add `CallLog` model with relations
4. Add `InboundCallProcessor` service
5. Extend `CloudWebhookController` to dispatch 'calls' field
6. Add `OutboundCallService` for outbound calls
7. Add `ConversationController::initiateCall` and `endCall` actions
8. Add `CallController::index` for /calls page
9. Update `conversations/show.blade.php` with call button + modal + in-flight banner + inline call cards
10. Create `resources/views/calls/index.blade.php`
11. Add sidebar nav link
12. Write all tests
13. Manual smoke test: place a real test call from production to verify webhook flow

## Open questions / verifications during implementation

These need verification when implementing — flagged so they're not buried:

1. **Exact Meta outbound call endpoint URL** — the spec assumes `POST /v20.0/{phone_number_id}/calls`. If Meta uses a different path or query shape, `OutboundCallService` adapts accordingly without changing the spec's contract.

2. **Webhook event names** — sample payload showed `"event": "connect"`. The full enum (accept, disconnect, missed, declined, fail) is assumed by analogy to common voice APIs. First implementation will log unknown events and surface them.

3. **Outbound call rate limits** — Meta likely caps outbound calls per minute. Phase A doesn't bulk-dial, so this is unlikely to bite. Phase C (broadcasts) will need to verify.

4. **Audio device routing** — confirmed in Q3 discussion: audio terminates wherever the WhatsApp Business app is registered. Solo-friendly. UI surfaces this implicitly (no audio controls in the browser; just status updates).

## Acceptance criteria (Phase A complete when)

- [ ] Migration adds `call_logs` table with all columns + indexes
- [ ] `RolesAndPermissionsSeeder` includes `conversations.call`, granted to super_admin/admin/manager
- [ ] Inbound call webhook from Meta creates a call_log row, updates as events arrive
- [ ] User with `conversations.call` can place an outbound call from chat header
- [ ] User without `conversations.call` does NOT see the call button
- [ ] Confirmation modal shows before call is placed
- [ ] In-flight banner appears immediately after call is placed and updates as status changes
- [ ] Inline call cards appear in conversation thread, mixed chronologically with messages
- [ ] /calls page lists all calls with filter chips, respects view_all vs view_assigned visibility
- [ ] "End call" button hangs up an in-progress call; status updates to `ended`
- [ ] Meta API failure during outbound call shows user-facing error; no orphan call_log row created
- [ ] Manual test: a real call from a real Nigerian phone successfully shows in inbox + on /calls page
- [ ] All tests pass; full suite remains green (≥119 + the new ~15-20)

## Future phases (deferred)

- **Phase B — Scheduling + calendar UI** — adds the dropped scope (schedule-for-later, calendar grid, reschedule, cancel)
- **Phase C — Voice broadcasts** — bulk dialing as a campaign type, parallel to text campaigns (requires verifying Meta's outbound rate limits)
- **Phase D — Call buttons in marketing templates** — when creating a template, add option for "Call us" tappable button
- **Phase E — IVR builder** — interactive voice response menus for inbound calls
- **Phase F — SIP trunk integration** — multi-agent audio routing via SignalWire/Twilio SIP. Audio per-agent in their browser via WebRTC.

Each becomes its own brainstorm + spec + plan when prioritized.
