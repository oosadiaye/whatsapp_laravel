# Inbox Phase 13.1 — Contact-List Initiation Design

**Date:** 2026-05-01
**Phase:** 13.1 (small follow-on to Voice Phase A)
**Owner:** retrofitting an omitted requirement from the Voice brainstorm Q3 ("like normal standard WhatsApp")

## What we're building

Inline **chat** and **call** action buttons on every row of the contact list (`/contacts`), so a user can start a conversation or place a call directly from the contact directory — exactly like WhatsApp Web's contact panel.

Closes the gap noted after Voice Phase A shipped: conversations could only be created by inbound webhooks, never initiated from the UI. The Voice Phase A click-to-call button required an existing conversation, so brand-new contacts couldn't be called or messaged at all.

**In scope (Phase 13.1):**
1. Two new endpoints on `ContactController`:
   - `POST /contacts/{contact}/chat` — find-or-create conversation, redirect to chat thread
   - `POST /contacts/{contact}/call` — find-or-create conversation, then invoke the existing Voice Phase A `initiateCall` flow
2. Inline icon buttons on each contact row, permission-gated and engagement-gated
3. Instance-picker modal for users with 2+ active WhatsApp instances
4. Test coverage for find-or-create idempotency, permission gates, engagement gate, multi-instance flow

**Out of scope (deferred):**
- Explicit `opted_in_at` column on contacts → Phase 14 (CRM core)
- Contact detail / profile page → Phase 14
- Bulk-action variants ("call selected contacts", "message selected contacts") → not planned
- Adding a "Compose New" button on the conversations index — single-contact entry point only

## Why now (and what changed)

The original Voice Phase A spec narrowed Q3's "like normal standard WhatsApp" to *just* the chat-header call button. Standard WhatsApp Web also lets you start chats and calls from the contacts list, which the spec missed. The voice infrastructure is already in place (`InboundCallProcessor`, `OutboundCallService`, `ConversationController::initiateCall`, the call-confirmation modal), so this phase only builds the *entry points*.

## Meta WhatsApp policy compliance

This is the design's most important constraint. Three policy gates apply:

### Gate 1 — 24-hour customer service window

Inside the window, freeform messages allowed. Outside, only approved templates.

**Already enforced** by `Conversation::isWindowOpen()` and the existing chat-thread view, which switches between a freeform input and a template picker based on window state. The Chat button on the contact list does not change this — it just navigates to the thread, which already does the right thing.

**No new code.** The Chat button is a pure navigation action; clicking it does not send anything to Meta.

### Gate 2 — Opt-in requirement for first outbound contact

Meta requires explicit user consent before a business sends the first outbound message OR places a Cloud Calling API call. We don't have explicit opt-in tracking on contacts today (no `opted_in_at` column).

**Engagement-based proxy** for Phase 13.1:
- A contact is considered **engaged** if they have at least one of:
  - Inbound `ConversationMessage` (`direction = 'inbound'`) in the last 30 days, OR
  - Inbound `CallLog` (`direction = 'inbound'`) in the last 30 days

This proxy is consistent with how Meta itself defines the 24h window — engagement = recent inbound activity. The 30-day cushion is wider to accommodate longer business cycles.

The **Call button** is hidden (or rendered disabled with a tooltip) for contacts who fail the engagement test. The **Chat button** is always available because navigating to a conversation is not itself an outbound act — the user must still pick a template and click Send, which goes through the existing 24h-window gate.

Phase 14 will add an explicit `opted_in_at` column with explicit consent capture, replacing the engagement proxy.

### Gate 3 — Quality rating preservation

Meta tracks per-phone-number quality ratings (Green / Yellow / Red). Excessive unsolicited messages drop the rating, which throttles daily send quota.

**Mitigations baked into this design:**
- No bulk variant ("call all selected") — single-contact entry only, forces deliberate intent
- No "fire on click" — Chat just navigates; Call goes through the existing confirmation modal from Voice Phase A
- Engagement gate (Gate 2) prevents the obvious quality-rating-killer scenario of cold-calling a list of imported contacts who never opted in

## Architecture

### Routing

Two new POST routes inside the existing authenticated middleware group:

```php
Route::middleware('permission:conversations.reply')->group(function () {
    Route::post('/contacts/{contact}/chat', [ContactController::class, 'startChat'])
        ->name('contacts.startChat');
});

Route::middleware('permission:conversations.call')->group(function () {
    Route::post('/contacts/{contact}/call', [ContactController::class, 'startCall'])
        ->name('contacts.startCall');
});
```

POST (not GET) because both actions create state (`Conversation` row via `firstOrCreate`).

### Controller actions on `ContactController`

```php
public function startChat(Request $request, Contact $contact): RedirectResponse
{
    $this->authorizeContactAccess($request, $contact);

    $instance = $this->resolveInstance($request, $contact);  // see below

    $conversation = Conversation::firstOrCreate(
        ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
        ['user_id' => $contact->user_id, 'unread_count' => 0],
    );

    return redirect()->route('conversations.show', $conversation);
}

public function startCall(
    Request $request,
    Contact $contact,
    OutboundCallService $outboundCallService,
): RedirectResponse {
    $this->authorizeContactAccess($request, $contact);

    if (! $this->isContactEngaged($contact)) {
        return back()->with(
            'error',
            'Cannot call this contact — they must message you first (Meta opt-in policy).',
        );
    }

    $instance = $this->resolveInstance($request, $contact);

    $conversation = Conversation::firstOrCreate(
        ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
        ['user_id' => $contact->user_id, 'unread_count' => 0],
    );

    // Inline the Voice Phase A initiation here — same service, same call_log row,
    // same Meta API call. We can't simply redirect to ConversationController::initiateCall
    // because that route is POST-only (intentionally — calls cost money) and a redirect
    // demotes to GET. Instead the contact-list Call button opens an Alpine confirmation
    // modal client-side, and on confirm POSTs here, which fires the call atomically.
    try {
        $callLog = $outboundCallService->initiate($conversation, $request->user());
    } catch (WhatsAppApiException $e) {
        return redirect()
            ->route('conversations.show', $conversation)
            ->with('error', "Could not place call: {$e->getMessage()}");
    }

    return redirect()
        ->route('conversations.show', $conversation)
        ->with('success', "Calling {$contact->name}...");
}
```

`authorizeContactAccess` enforces same-account ownership: `abort_unless($contact->user_id === $request->user()->id, 403)`.

`isContactEngaged` runs the engagement query (Gate 2 above): an inbound message OR inbound call in the last 30 days.

### Instance resolution (`resolveInstance`)

A new private helper on `ContactController`:

1. Get all active `WhatsAppInstance` rows for the current user
2. If exactly 1 → use it
3. If 0 → throw a redirect-back exception with flash error: "Set up a WhatsApp instance before starting conversations"
4. If 2+ → look for `instance_id` in the request payload (sent from the picker modal). If present and belongs to the user, use it. If absent, redirect back with flash error: "Pick which WhatsApp number to use" (the picker modal will be re-opened by the contact-list view)

### Engagement query (`isContactEngaged`)

```php
private function isContactEngaged(Contact $contact): bool
{
    $threshold = now()->subDays(30);

    $hasInboundMessage = ConversationMessage::query()
        ->whereHas('conversation', fn ($q) => $q->where('contact_id', $contact->id))
        ->where('direction', 'inbound')
        ->where('received_at', '>=', $threshold)
        ->exists();

    if ($hasInboundMessage) {
        return true;
    }

    return CallLog::query()
        ->where('contact_id', $contact->id)
        ->where('direction', 'inbound')
        ->where('created_at', '>=', $threshold)
        ->exists();
}
```

Two queries instead of a UNION because each is a fast indexed lookup and short-circuiting on the first true is cheaper than building a combined query.

### View changes (`resources/views/contacts/index.blade.php`)

Replace the "Actions" cell (currently Edit + Delete) with a stacked layout:

```
┌─────────────────────────────────────────────┐
│ [💬 Chat] [📞 Call]   Edit  ·  Delete       │
└─────────────────────────────────────────────┘
```

- **Chat icon button**: green pill, always visible if user has `conversations.reply` permission. Wraps a `<form>` POST to `contacts.startChat`.
- **Call icon button**: emerald pill, visible if user has `conversations.call` permission AND `$contact->is_engaged` is true. If permission is held but `is_engaged` is false, render a disabled `<button>` with a tooltip explaining the policy reason.
- The eager-loaded `is_engaged` flag is computed once per contact in `ContactController::index()` so we don't N+1 the engagement query during render.

### Two client-side modals

**1. Multi-instance picker modal** — renders only when `auth()->user()->whatsappInstances()->where('is_active', true)->count() > 1`. Single-instance users skip it and POST directly. Single Alpine `x-data="{ instancePickerFor: null, action: null }"` at the page root; clicking either button on a row sets state and reveals the modal with a `<select>` of instances. On submit, the modal POSTs to either `contacts.startChat` or `contacts.startCall` with `instance_id` in the body.

**2. Call confirmation modal** — exact same pattern as Voice Phase A's chat-header call button. Before POSTing to `contacts.startCall`, the user sees a modal listing the contact name, phone, and which instance the call will originate from, plus the standard Meta-quota warning. Confirm → POST → server fires Meta API. This must run BEFORE `startCall` POST, not after, because every call costs money — we never want a misclick to fire one.

If both modals apply (multi-instance user calling), they sequence: instance picker first, then call confirmation. Implementation can share a single `x-data` scope to keep state coherent.

### Performance

The contact-list query in `ContactController::index()` currently loads contacts paginated 25 per page. We add:

```php
$contacts = $contacts->withExists([
    'conversationMessages as has_recent_inbound' => fn ($q) =>
        $q->where('direction', 'inbound')->where('received_at', '>=', now()->subDays(30)),
    'callLogs as has_recent_inbound_call' => fn ($q) =>
        $q->where('direction', 'inbound')->where('created_at', '>=', now()->subDays(30)),
]);
```

This adds two correlated subqueries to the contact list query — fast on the indexes that already exist (`(conversation_id, created_at)` for messages, `(conversation_id, created_at)` for call_logs). Then `$contact->is_engaged = $contact->has_recent_inbound || $contact->has_recent_inbound_call`.

The `Contact` model needs `conversationMessages()` and `callLogs()` HasManyThrough relations (via `Conversation`).

## Permissions

Reuses the existing permission set from earlier phases:
- **`conversations.reply`** → gates the Chat button. Already granted to admin / manager / agent.
- **`conversations.call`** → gates the Call button. Already granted to super_admin / admin / manager (NOT agent), per Voice Phase A's spec.

No new permissions required.

## Testing strategy

`Tests\Feature\Controllers\ContactInitiationTest`:

1. **`test_chat_button_creates_conversation_for_new_contact`** — POST to `startChat` for a contact with no existing conversation → redirected to `conversations.show($newConv)` → assert exactly 1 `Conversation` row exists.
2. **`test_chat_button_navigates_to_existing_conversation`** — pre-create a conversation, POST to `startChat` → same conversation reused, no duplicate row.
3. **`test_call_button_blocked_when_contact_has_no_recent_engagement`** — fresh contact, no inbound messages or calls → POST to `startCall` → redirected back with error session flash; no `CallLog` row created.
4. **`test_call_button_allowed_when_contact_messaged_within_30_days`** — pre-create an inbound `ConversationMessage` from 5 days ago + Http::fake the Meta `/calls` response → POST to `startCall` → exactly 1 outbound `CallLog` created with `placed_by_user_id = $user->id` and `meta_call_id` from fake response.
5. **`test_call_button_allowed_when_contact_called_within_30_days`** — pre-create an inbound `CallLog` from 5 days ago → POST to `startCall` → allowed (engagement via call, not message).
6. **`test_chat_route_requires_conversations_reply_permission`** — agent without `conversations.reply` → 403.
7. **`test_call_route_requires_conversations_call_permission`** — agent without `conversations.call` → 403.
8. **`test_cross_account_contact_is_forbidden`** — user A tries to chat with user B's contact → 403.
9. **`test_multi_instance_user_must_provide_instance_id`** — user with 2 active instances POSTs without `instance_id` → redirected back with flash error.
10. **`test_engagement_threshold_is_30_days`** — inbound message from 31 days ago → call button blocked. From 29 days ago → allowed.

Plus a view test that the `is_engaged` flag is computed correctly via the eager-loaded subqueries (no N+1).

## Acceptance criteria

- [ ] Chat icon button appears on every contact row for users with `conversations.reply`
- [ ] Call icon button appears on every contact row for users with `conversations.call` AND who can engage the contact
- [ ] Disabled-state Call button shows tooltip: *"Recipient must message you first (Meta opt-in policy)."*
- [ ] Clicking Chat for a brand-new contact creates one `Conversation` row + redirects to chat thread
- [ ] Clicking Chat for a contact with existing conversation redirects to that same conversation (no duplicate)
- [ ] Clicking Call for an engaged contact runs the Voice Phase A confirmation modal flow exactly as before
- [ ] Multi-instance users get a picker modal; single-instance users skip it
- [ ] All ~10 new tests pass; existing 148 tests remain green
- [ ] Documented in `app/Http/Controllers/ContactController.php` why engagement is the proxy for opt-in (with a TODO marker pointing to Phase 14)

## Open questions / verifications

- **Should the Call button be hidden entirely vs disabled?** Recommendation: render disabled with a tooltip so users learn the policy rather than wondering why it's missing. Confirm during implementation.
- **30-day engagement window — is that the right threshold?** Meta's own 24-hour rule applies to freeform messages; 30 days is our heuristic for opt-in proxy. Adjustable in code if too strict / loose.
- **Where does the `is_engaged` definition live?** Currently spec proposes computing in the controller via `withExists`. If the same logic gets needed elsewhere (e.g., dashboard widgets), promote to a method on the `Contact` model.

## Future phases (replaces this)

- **Phase 14 (CRM core)**: explicit `opted_in_at` column on contacts, opt-in-capture UI, replaces the engagement proxy from Gate 2. Also adds the contact detail / profile page that hosts a richer chat/call/timeline view.
- **Phase 15+**: bulk operations (call queue, broadcast template send) gated by per-contact opt-in.
