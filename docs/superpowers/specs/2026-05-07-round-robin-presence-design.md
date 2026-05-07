# Phase 14.2 — Round-robin Auto-Assignment + Agent Presence

**Date:** 2026-05-07
**Phase:** 14.2 (small, ship-this-week feature bundle)
**Builds on:** Phase 14.1 (Real-time UX Bundle, `RealtimePulse` Livewire component) + Voice Phase A (`InboundCallProcessor`) + Phase 12 (`InboundMessageProcessor`)
**Defers to:** Phase 14.3 (explicit Available/Away/Busy toggle, capacity-aware routing, skill/language matching)

## What we're building

When a customer messages or calls one of the business numbers and there's no existing conversation thread, BlastIQ now automatically assigns the conversation to the next available agent — instead of leaving it in the unassigned pool until an admin handles it manually. The "next available" logic is round-robin: agents are rotated fairly so workload distributes evenly across the team.

Required foundation: an "agent presence" model that knows who is online right now. This phase ships a minimal implicit-heartbeat presence (an agent is online if their browser polled the server in the last 2 minutes), enough for round-robin to work. Explicit Available/Away/Busy toggles are a Phase 14.3 enhancement.

**In scope (Phase 14.2):**
1. Two new columns on `users` table: `last_seen_at` (heartbeat timestamp) and `last_assigned_at` (round-robin pointer)
2. Heartbeat touch in `RealtimePulse::render()` with 30-second dedup to keep write rate sensible
3. New `App\Services\RoundRobinAssigner` service with race-safe `next()` method using `lockForUpdate()`
4. Integration into `InboundMessageProcessor` and `InboundCallProcessor` — auto-assign on conversation creation if currently unassigned
5. 10 new tests (8 service unit + 2 integration via existing webhook tests)

**Out of scope (deferred):**
- 🟢 Explicit Available/Away/Busy toggle — Phase 14.3
- 🎯 Capacity-aware routing — agent currently on a call shouldn't get the next call (Phase 14.3)
- 🌐 Skill/language/tag matching for routing — future
- 📊 Routing analytics dashboards — future
- 🔁 Re-route stale unassigned conversations via scheduled job — future
- 🤝 Fallback to admin/manager when zero agents online — current behavior is "leave unassigned" (the Phase 14.1 banner surfaces it to all eligible viewers anyway)

## Why now (architectural context)

Phase 14.1 documented an "intentional asymmetry" between `RealtimePulse` (broader scope: agents see assigned + unassigned) and `ConversationController::index` (assigned only). The asymmetry made sense as a real-time alerting choice — agents need to know about unassigned calls so someone can grab them. But it leaves a UX hole: if no admin or agent manually assigns, the conversation can sit ringing in everyone's banner, with no clear ownership.

Round-robin closes that hole. With auto-assignment running on every inbound webhook, the unassigned pool shrinks to "only when zero agents are online" — a rare and easily-recovered case. The asymmetry becomes effectively invisible at runtime.

This phase also lays groundwork for Phase 14.3+ features: presence is a primitive other features can use ("show online dot in inbox", "block sending if agent goes offline mid-conversation", "warn admin when no agents online"), and `RoundRobinAssigner` is the natural extension point for richer routing logic later.

## Architectural decisions made during brainstorming

Recorded here in order of importance:

1. **Implicit heartbeat, not explicit toggle.** A `users.last_seen_at` column touched on every `RealtimePulse` poll cycle. Zero new UI, no mode-toggle micromanagement. The Slack-style "lunch problem" (agent forgot to flip Away) is rare on a small team and recoverable (re-route on next webhook). Phase 14.3 can add explicit toggles on top.

2. **Pool is `role=agent` only.** Admins, managers, and super_admins are management roles; the existing `agent` role is specifically the customer-facing one. Round-robin means "distribute customer-facing work to customer-facing roles". An agent-less team gets no auto-assignment (conversation stays unassigned, current behavior).

3. **Trigger on every webhook, but skip if already assigned.** A single rule handles four cases: new conversation (assign), unassigned conversation getting more activity (re-attempt — agents may now be online), already-assigned conversation (sticky — preserves customer-agent continuity), returning customer with prior agent (sticky to prior).

4. **State stored as `last_assigned_at` timestamp on User.** NULL-first ordering means new agents and returning-from-break agents naturally get prioritized. Single column add, no separate pointer table, no scheduled-reset cron required.

5. **Race-safe via `DB::transaction()` + `lockForUpdate()`.** Two webhooks arriving in the same millisecond both serialize through the lock; the second sees the first's `last_assigned_at` update and picks a different agent. This is the most important correctness detail.

## Schema changes

One migration: `database/migrations/2026_05_07_120000_add_presence_columns_to_users.php`

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // Implicit-heartbeat presence: touched on every RealtimePulse poll.
        // 'available' for round-robin = last_seen_at >= now()->subMinutes(2).
        $table->timestamp('last_seen_at')->nullable()->after('is_active');
        $table->index('last_seen_at');

        // Round-robin pointer: stamped when an agent is assigned a new
        // conversation. Pick query orders by last_assigned_at ASC NULLS FIRST,
        // so newer agents (NULL) go to top of queue, older-stamped agents
        // are deprioritized until they "rotate forward" again.
        $table->timestamp('last_assigned_at')->nullable()->after('last_seen_at');
        $table->index('last_assigned_at');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropIndex(['last_assigned_at']);
        $table->dropIndex(['last_seen_at']);
        $table->dropColumn(['last_assigned_at', 'last_seen_at']);
    });
}
```

Both columns nullable, both indexed (the queries that use them filter/order on these columns).

## New service: `App\Services\RoundRobinAssigner`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Picks the next available agent for round-robin auto-assignment of
 * incoming conversations. Used by InboundMessageProcessor and
 * InboundCallProcessor on the firstOrCreate-of-Conversation branch.
 *
 * "Available" means: role=agent, is_active=true, last_seen_at within
 * the last AVAILABILITY_WINDOW_MINUTES. The poll-driven heartbeat in
 * App\Livewire\RealtimePulse keeps last_seen_at fresh while the agent
 * has the app open.
 *
 * Fairness: rotation orders by last_assigned_at ASC NULLS FIRST. New
 * agents and returning-from-break agents naturally get priority.
 *
 * Race safety: the SELECT-then-UPDATE pair runs inside DB::transaction()
 * with lockForUpdate(), so two simultaneous webhooks can't both pick
 * the same agent (which would skew the rotation and assign two
 * conversations to one person while another agent gets nothing).
 */
class RoundRobinAssigner
{
    public const AVAILABILITY_WINDOW_MINUTES = 2;

    public function next(): ?User
    {
        return DB::transaction(function () {
            $agent = User::query()
                ->where('role', User::ROLE_AGENT)
                ->where('is_active', true)
                ->where('last_seen_at', '>=', now()->subMinutes(self::AVAILABILITY_WINDOW_MINUTES))
                ->orderByRaw('last_assigned_at IS NULL DESC, last_assigned_at ASC')
                ->lockForUpdate()
                ->first();

            if ($agent !== null) {
                $agent->forceFill(['last_assigned_at' => now()])->save();
            }

            return $agent;
        });
    }
}
```

Single public method. ~30 lines of actual logic. Constructor-less, instantiable in tests via `new RoundRobinAssigner()` or via the container in production.

## RealtimePulse heartbeat integration

`app/Livewire/RealtimePulse.php` gains a small block at the top of `render()`, before the existing payload-build logic:

```php
public function render()
{
    $user = Auth::user();

    if ($user === null) {
        return view('livewire.realtime-pulse', [
            'inflightCalls' => [],
            'unreadMessages' => 0,
        ]);
    }

    // Heartbeat — touch last_seen_at every 30s. The 3s poll cycle would
    // otherwise produce ~20 writes/min/user, most of them redundant.
    // The 30s dedup window is well below the 2-min availability threshold,
    // so freshness is preserved while write load drops 90%.
    if ($user->last_seen_at === null
        || $user->last_seen_at->lt(now()->subSeconds(30))) {
        $user->forceFill(['last_seen_at' => now()])->save();
    }

    // ... existing call payload + unread count logic unchanged ...
}
```

The dedup conditional means a typical agent triggers ~2 UPDATEs per minute on `users.last_seen_at`, regardless of how often `RealtimePulse` polls.

## Processor integration

Both processors get the same 5-line addition right after `Conversation::firstOrCreate(...)`. Constructor injection adds the assigner.

`app/Services/InboundMessageProcessor.php`:

```php
public function __construct(
    private readonly WhatsAppCloudApiService $cloudApi,
    private readonly RoundRobinAssigner $roundRobinAssigner,
) {}

private function findOrCreateConversation(WhatsAppInstance $instance, Contact $contact): Conversation
{
    $conversation = Conversation::firstOrCreate(
        ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
        ['user_id' => $instance->user_id, 'unread_count' => 0],
    );

    // Auto-assign to next available agent IF currently unassigned.
    // Sticky-to-existing-assignment is implicit: already-assigned conversations
    // skip this branch entirely. New conversations and ones unassigned because
    // no agents were online earlier both go through the same rotation.
    if ($conversation->assigned_to_user_id === null) {
        $agent = $this->roundRobinAssigner->next();
        if ($agent !== null) {
            $conversation->update(['assigned_to_user_id' => $agent->id]);
        }
    }

    return $conversation;
}
```

`app/Services/InboundCallProcessor.php` gets the same constructor injection and the same 5-line block in its `findOrCreateConversation` (or equivalent) helper.

## Permission scoping update

Phase 14.1 documented an intentional asymmetry between `RealtimePulse` view_assigned (assigned + unassigned pool) and `ConversationController::index` view_assigned (assigned only). With auto-assignment in place, the unassigned pool shrinks to near-zero — only conversations created during agent-offline windows remain unassigned. The asymmetry's runtime impact disappears almost entirely.

**No code change** to either side's scope rule. The asymmetry comment in `RealtimePulse.php` already references "Phase 14.2 will reduce the unassigned pool to near-zero" — that's now true.

## Testing strategy

`Tests/Feature/Services/RoundRobinAssignerTest.php` — 8 unit tests:

1. `test_returns_null_when_no_agents_exist` — empty users table → null
2. `test_returns_null_when_no_agents_are_online` — agent exists but `last_seen_at` is 3 min ago → null
3. `test_picks_only_user_with_agent_role` — admin + agent both online; only agent eligible
4. `test_excludes_inactive_agents` — `is_active=false` agent is online → not picked
5. `test_excludes_agents_offline_more_than_2_minutes` — last_seen_at = 121 seconds ago → excluded; 119 seconds ago → included (boundary)
6. `test_picks_agent_with_null_last_assigned_at_first` — new agent (NULL) and old-stamped agent both online → new wins
7. `test_picks_agent_with_oldest_last_assigned_at_when_none_null` — three agents, all stamped, oldest wins
8. `test_stamps_picked_agent_with_current_timestamp_for_next_round` — call `next()` twice, second call returns DIFFERENT agent (because first was just stamped)

Plus integration tests in existing webhook test files:

`Tests/Feature/Webhooks/InboundMessageProcessingTest.php` — append:

9. `test_inbound_message_auto_assigns_to_available_agent` — agent is online; webhook arrives; new conversation has `assigned_to_user_id === $agent->id`

`Tests/Feature/Webhooks/InboundCallProcessingTest.php` — append:

10. `test_inbound_call_auto_assigns_to_available_agent` — same shape, for calls

Total: 10 new tests. Test count goes from 185 (Phase 14.1 baseline) to 195.

## Edge cases

- **Two concurrent webhooks** for different conversations → `lockForUpdate()` serializes the rotation pick; both get different agents. Test 8 above approximates this by calling `next()` twice in sequence; full concurrency simulation is out of scope for PHPUnit but the lock semantic is enforced by the SQL.
- **Agent goes offline mid-conversation** → no reassignment (sticky behavior is intentional). Customer continues messaging into the original conversation; agent will see the chat-thread notification when they return. If they never return, an admin can manually reassign via the existing inbox.
- **Customer messages an unassigned conversation when no agents are online** → conversation stays unassigned; the Phase 14.1 banner pings every eligible viewer. On the customer's NEXT message (if agents are now online), the rule re-fires and assigns.
- **Agent disabled mid-rotation** (`is_active=false`) → naturally drops out of the pool query. Existing assignments stick to them but they can't be picked again until reactivated. (`is_active` is the existing soft-suspend primitive.)
- **Heartbeat write failure** (DB unavailable) → request continues; user is treated as offline by the next round-robin cycle. No user-visible failure mode.
- **Agent with NULL `last_seen_at`** (new user, never logged in) → excluded from rotation by `WHERE last_seen_at >= ...` (NULL fails the comparison). Good — they're not actually online.
- **All agents have identical `last_assigned_at`** (e.g., everyone just stamped at the same millisecond) → SQL ORDER BY ties broken by primary-key order. Deterministic but slightly biased toward lower IDs. Self-corrects on next rotation.

## Acceptance criteria

- [ ] Migration adds `users.last_seen_at` + `users.last_assigned_at` columns with indexes; `down()` reverses cleanly
- [ ] `RealtimePulse::render()` updates `last_seen_at` only when stale (>30s); doesn't refetch the User on every poll
- [ ] `RoundRobinAssigner::next()` returns the correct agent under all 8 unit-test scenarios
- [ ] `InboundMessageProcessor` constructor takes `RoundRobinAssigner`; integration test passes
- [ ] `InboundCallProcessor` constructor takes `RoundRobinAssigner`; integration test passes
- [ ] Already-assigned conversations are NOT reassigned on new inbound activity (verified by test using a pre-existing assigned conversation)
- [ ] Test suite green: 185 baseline + 10 new = **195 tests passing**, no regressions

## Open questions / verifications during implementation

- **`RoundRobinAssigner::next()` test 8 (concurrency simulation)** — calling `next()` twice in sequence and asserting different agents is a proxy for race safety, not a full concurrency test. PHPUnit can't easily simulate two simultaneous transactions; the `lockForUpdate()` correctness is guaranteed by SQL semantics. Document this in the test comment.
- **Touch-rate dedup tradeoff** — 30 seconds chosen because it cuts writes by 90% but keeps detection within 1.5 min of true offline. If write load is later observed as a problem, raise to 60s. If detection feels sluggish, drop to 15s.
- **Database support for `last_assigned_at IS NULL DESC` syntax** — verified to work on SQLite (used in tests), MySQL, PostgreSQL. Standard SQL; not a workaround.

## Future phases (related, deferred)

- **Phase 14.3** — explicit Available/Away/Busy toggle. Adds `users.presence_status` enum column with three values and a UI toggle in the topbar. The implicit heartbeat continues to drive `last_seen_at`; the explicit status overrides "available" when set to "away" or "busy". Round-robin pool query gets one more clause: `AND (presence_status = 'available' OR presence_status IS NULL)`.
- **Phase 14.4** — capacity-aware routing. Track active in-flight calls per agent (`call_logs.placed_by_user_id` exists; needs a similar concept for assigned-but-unanswered conversations). Round-robin tiebreaker: prefer agents with fewer active conversations. Useful when team grows past ~5 agents.
- **Phase 14.5** — skill/language matching. Customer-side fields (`contacts.preferred_language`, `contacts.tags`) match against agent-side fields. Routing becomes: filter by skill match first, then round-robin within the matched pool.
