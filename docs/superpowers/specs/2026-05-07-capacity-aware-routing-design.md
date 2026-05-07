# Phase 14.4 — Capacity-Aware Routing Design

**Date:** 2026-05-07
**Status:** Approved
**Builds on:** Phase 14.2 (round-robin auto-assignment) + Phase 14.3 (presence toggle)

## Summary

Add a global per-agent concurrency cap to round-robin auto-assignment. An agent currently handling N or more "active" conversations (inbound message in the last 24 hours) is filtered out of the routing pool until their load drops. The cap is a single integer stored in the existing `settings` table (default `5`), editable from the existing `/settings` page. When all eligible agents are at cap, `RoundRobinAssigner::next()` returns null — the conversation stays unassigned and surfaces in the existing Unassigned filter for managers to handle manually.

## Goals

1. Stop the pile-on effect: agent A with 30 open threads should not receive a 31st message just because their `last_assigned_at` is the oldest.
2. Use behavioral signals already on the schema — no new "open/closed" column or close-button UX.
3. Make the cap admin-tunable via the existing Settings UI (no SSH/`tinker`/.env edit required for routine tuning).
4. Surface team saturation as a visible signal (unassigned conversations pile up) rather than silently overloading the lowest-cap agent.

## Non-goals (deferred)

- Per-agent cap override (e.g., "Maria's cap is 2, John's is 8"). Speculative; defer until requested.
- Per-WhatsApp-instance cap (different number per support line). Phase 14.5+ if multi-product teams demand it.
- Manager dashboard showing each agent's current load (X/5). Belongs in the Phase 15 presence + load dashboard.
- "Capacity: 3/5" badge on the agent's own `PresenceToggle`. Defer; agents can count their own threads.
- Routing-saturation notifications / alerts. Far future.
- Soft-fallback ("if everyone's at cap, pick longest-idle anyway"). Rejected during brainstorming Q4 — defeats the cap.
- Explicit conversation close/reopen UX. Rejected during brainstorming Q1 — the 24h activity window is the implicit definition.

## Architecture

`RoundRobinAssigner::next()` gains a correlated-subquery WHERE clause that filters out agents whose count of active conversations is at or above a global cap. "Active" is defined as `assigned_to_user_id = agent.id AND last_inbound_at >= now() - ACTIVE_WINDOW_HOURS`. The cap value is read from `Setting::get('round_robin_cap_per_agent', 5)`.

```
┌─────────────────────────────────────┐
│ webhook → InboundMessageProcessor   │
│   findOrCreateConversation()        │
│   if assigned_to_user_id IS NULL:   │
│     agent = $assigner->next()       │
│     if agent: assign                │
│     else: leave unassigned          │
└─────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────┐
│ RoundRobinAssigner::next()          │
│   cap = Setting::get(...,  5)       │
│   cutoff = now() - 24h              │
│   SELECT user WHERE                 │
│     role = agent                    │
│     is_active = true                │
│     presence_status != 'away'       │
│     last_seen_at >= now() - 2min    │
│     (SELECT COUNT(*) FROM           │
│       conversations WHERE           │
│       assigned_to_user_id=user.id   │
│       AND last_inbound_at>=cutoff   │
│     ) < cap                ← NEW    │
│   ORDER BY last_assigned_at NULLS   │
│            FIRST                    │
│   FOR UPDATE                        │
└─────────────────────────────────────┘
```

The new clause is the only routing change. All Phase 14.2/14.3 filters remain intact; the cap is a fifth filter stacked on top.

## Database

No schema changes. The cap is one new key in the existing `settings` table. The seeded value lives in `database/seeders/DatabaseSeeder.php` alongside the other operational defaults (`default_rate_per_minute` etc.).

```php
$settings = [
    // ... existing defaults
    'round_robin_cap_per_agent' => '5',
];
```

Existing production deployments do not get this row automatically (DatabaseSeeder runs only on fresh installs), but `Setting::get('round_robin_cap_per_agent', 5)` returns `5` when the row is absent, so behavior is identical with or without the seed. The first time an admin saves the Settings form, `Setting::set()` inserts the row via `updateOrCreate`.

## Service change — `App\Services\RoundRobinAssigner`

### New constant

```php
public const ACTIVE_WINDOW_HOURS = 24;
```

Lives at the top of the class, alongside the existing `AVAILABILITY_WINDOW_MINUTES = 2` constant.

### Modified `next()`

```php
public function next(): ?User
{
    $cap = (int) Setting::get('round_robin_cap_per_agent', 5);
    $cutoff = now()->subHours(self::ACTIVE_WINDOW_HOURS);

    return DB::transaction(function () use ($cap, $cutoff): ?User {
        $agent = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('is_active', true)
            ->where('presence_status', '!=', User::PRESENCE_AWAY)
            ->where('last_seen_at', '>=', now()->subMinutes(self::AVAILABILITY_WINDOW_MINUTES))
            ->whereRaw(
                '(SELECT COUNT(*) FROM conversations
                  WHERE conversations.assigned_to_user_id = users.id
                    AND conversations.last_inbound_at >= ?) < ?',
                [$cutoff, $cap]
            )
            ->orderByRaw('last_assigned_at IS NULL DESC, last_assigned_at ASC')
            ->lockForUpdate()
            ->first();

        if ($agent !== null) {
            $agent->forceFill(['last_assigned_at' => now()])->save();
        }

        return $agent;
    });
}
```

### Notes on the correlated subquery

- Portable across SQLite/MySQL/PostgreSQL. No engine-specific syntax.
- Performance: the subquery runs once per candidate row in the outer query. With ~10 agents and `conversations.assigned_to_user_id` already indexed, this is sub-millisecond. If the team scales to 100+ agents, the planner can be helped by a `(assigned_to_user_id, last_inbound_at)` composite index — defer that until measured.
- The `lockForUpdate()` continues to serialize concurrent webhooks against the same agent row. The subquery is read inside the transaction, so the count is consistent with the locked rowset.
- The `(int)` cast on the cap is defensive — `Setting::get()` returns a string from the DB; casting prevents string-comparison bugs in `whereRaw` placeholder binding.

### Cap edge cases

- `cap = 0` → no agent ever picked. Legitimate "manual-only mode" — admins can disable auto-assignment without touching code.
- `cap = 5` and agent has exactly 5 active conversations → filter excludes them (`5 < 5` is false). Boundary is `< cap`, NOT `<= cap`. The cap is "maximum allowed" semantics.
- Setting row missing (fresh deployment, never saved) → default `5` from `Setting::get()`. Behavior identical to having the seed row.
- Setting row contains a non-numeric string (manual DB tampering) → `(int)` cast yields `0` → manual-only mode. Safe failure (errs toward not-routing rather than over-routing).

## Settings UI

### View — `resources/views/settings/index.blade.php`

Add ONE new field group, modeled after the existing `default_rate_per_minute` field's markup:

```blade
<div>
    <label for="round_robin_cap_per_agent" class="block text-sm font-medium text-gray-700">
        Round-robin cap per agent
    </label>
    <input type="number"
           name="round_robin_cap_per_agent"
           id="round_robin_cap_per_agent"
           min="0"
           max="1000"
           value="{{ old('round_robin_cap_per_agent', $settings['round_robin_cap_per_agent'] ?? 5) }}"
           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
    <p class="mt-1 text-xs text-gray-500">
        Maximum active conversations auto-assigned to each agent. "Active" = inbound
        message within the last 24 hours. Set to 0 to disable auto-assignment entirely
        (conversations stay unassigned for managers to assign manually).
    </p>
    @error('round_robin_cap_per_agent')
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
```

The actual surrounding markup (form layout, group labels) follows whatever the existing fields use. The implementer should read the existing file before editing.

### Controller — `app/Http/Controllers/SettingsController.php`

Add one validation rule to the `update()` method's `validate()` call:

```php
'round_robin_cap_per_agent' => ['nullable', 'integer', 'min:0', 'max:1000'],
```

The existing foreach loop already handles new keys generically — no other controller change.

The `max:1000` is a sanity guard, not a real ceiling. Nobody runs a 1000-thread agent; the cap is just preventing typos like `5000` from bypassing the feature.

## Data flow (end-to-end)

1. Inbound message webhook arrives → `InboundMessageProcessor::findOrCreateConversation()` creates the conversation row.
2. Conversation has `assigned_to_user_id = NULL`. Processor calls `$this->roundRobinAssigner->next()`.
3. Service reads cap (`5` default), computes cutoff (`now() - 24h`), runs the filtered query inside `DB::transaction()` with `lockForUpdate()`.
4. **Eligible agent found** → conversation gets `assigned_to_user_id = agent.id`, agent's `last_assigned_at` stamped to `now()`. (Existing Phase 14.2 behavior.)
5. **No eligible agent** (none online, all away, OR all at cap) → `next()` returns `null`. Processor's existing `if ($agent !== null)` guard skips assignment. Conversation persists with `assigned_to_user_id = NULL`.
6. Conversation appears in `/conversations?filter=unassigned` for users with `conversations.view_all` permission. Manager clicks the assignee dropdown on the conversation page (existing Phase 14.x feature) and assigns manually.
7. Once the assigned agent's load drops below cap (one of their conversations crosses the 24h-quiet threshold), they automatically become eligible again on the next webhook.

## Error handling

| Failure mode | Behavior | Why this is correct |
|---|---|---|
| Cap setting row missing | Default `5` from `Setting::get()` | Existing deploys keep working; matches Phase 14.2 default behavior |
| Cap is non-numeric in DB | `(int)` cast → `0` → no auto-assignment | Errs toward not-routing, which surfaces as "unassigned conversations piling up" — a visible signal admin will notice |
| Cap is 0 | No agent ever picked | Legitimate manual-only mode |
| All agents at cap | `next()` returns null | Conversation stays unassigned, surfaces in Unassigned filter |
| Concurrent webhooks for same conversation | `firstOrCreate` deduplicates the conversation row; `lockForUpdate` serializes the agent pick | Phase 14.2 already handles this; no new coordination needed |
| Agent's count is computed mid-transaction while another webhook stamps a different agent | The two webhooks lock different rows, both succeed; counts are point-in-time consistent within each transaction | Acceptable — small race window where two agents could each tick from cap-1 to cap simultaneously, neither over cap. Self-corrects on next webhook |

## Testing

5 new tests appended to `tests/Feature/Services/RoundRobinAssignerTest.php`:

1. **`test_excludes_agent_at_cap`**
   Set cap to 3 (via `Setting::set`). Create one online agent. Create 3 conversations assigned to them, each with `last_inbound_at = now()`. Call `next()`. Assert it returns null.

2. **`test_includes_agent_one_below_cap`**
   Set cap to 3. Create one online agent with 2 active conversations. Assert `next()` returns the agent.

3. **`test_does_not_count_conversations_with_old_inbound`**
   Set cap to 3. Create one online agent with 5 conversations all having `last_inbound_at = now()->subHours(25)`. Assert `next()` returns the agent (because all 5 are outside the 24h window).

4. **`test_uses_settings_value_for_cap`**
   `Setting::set('round_robin_cap_per_agent', '2')`. Create agent A with 2 active conversations and agent B with 1. Assert `next()` returns agent B (A is filtered, B is under cap). Documents that the setting actually drives the filter — not a hardcoded value.

5. **`test_cap_of_zero_returns_null_for_all_online_agents`**
   `Setting::set('round_robin_cap_per_agent', '0')`. Create two online agents with no conversations at all. Assert `next()` returns null. Documents the supported "manual-only mode."

### Existing test impact

Phase 14.2's existing 8 tests don't create conversations, only agents — the new cap filter is a no-op on their setups (cap=5, count=0 < 5). They continue to pass without modification.

Phase 14.2's webhook integration tests (`InboundMessageProcessingTest`, `InboundCallProcessingTest`) create 1 agent and send 1 inbound message each. Cap=5 default, count=0 → agent eligible → existing assertions pass. No modification needed.

### Test trajectory

- Phase 14.3 baseline: 205 tests
- After 5 new service tests: **210 tests**

No new test files. No webhook test changes. No PresenceToggle test changes.

## File structure

### Files to modify (4)

| File | Change |
|---|---|
| `app/Services/RoundRobinAssigner.php` | Add `ACTIVE_WINDOW_HOURS` constant; add `whereRaw` correlated-subquery filter; read cap via `Setting::get`; cast to `(int)` |
| `tests/Feature/Services/RoundRobinAssignerTest.php` | Append 5 new tests |
| `app/Http/Controllers/SettingsController.php` | Add `'round_robin_cap_per_agent'` to the `validate()` array |
| `resources/views/settings/index.blade.php` | Add one new number-input field group with help text |
| `database/seeders/DatabaseSeeder.php` | Add `'round_robin_cap_per_agent' => '5'` to the `$settings` array |

(Five files modified in total, no new files.)

## Operational notes

- **Default behavior post-deploy**: cap is `5` (from the service-level default in `Setting::get(..., 5)`), even if the seed row never runs. Existing production deployments will not have the row until the first time an admin saves the Settings form.
- **Tuning**: admin loads `/settings`, changes the number, saves. Effective immediately on the next webhook (no caching layer between `Setting::get` and the query).
- **Rollback**: revert the commits and redeploy. The DB row, if it exists, is harmless (no other code reads it). Conversations assigned during the capacity-aware period stay assigned.
- **Direct-to-main commits, no feature flag** (consistent with prior phases). Feature is universally on once merged.

## Open questions / known limitations

- **The 24h window is hardcoded as a service constant**, not a setting. Reasoning: 24h is a sensible WhatsApp-cadence default and tuning it requires understanding the whole routing model — admins should not be casually changing it. If a real need emerges, it can become a second settings row in a follow-up phase.
- **No "active conversation" indicator in the UI**. An agent looking at their conversation list cannot tell which threads count toward their cap and which don't. Acceptable for v1 — Phase 15's load dashboard will handle this once design is given proper attention.
- **No real-time recompute when an agent crosses below cap**. The cap is consulted only when a new webhook arrives. If a 24h window expires while no webhooks are arriving, the agent silently re-enters the eligible pool — fine for the next webhook. If managers want a dashboard that shows "Maria just freed up," that's Phase 15.
- **The settings row is not migration-seeded**. Pros: consistent with existing pattern. Cons: production environments have no row until admin saves. Mitigated by the `Setting::get(..., 5)` default.
