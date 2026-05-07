# Phase 14.3 — Explicit Agent Presence Toggle Design

**Date:** 2026-05-07
**Status:** Approved
**Builds on:** Phase 14.2 (implicit-heartbeat presence + RoundRobinAssigner)

## Summary

Give agents an explicit `available` / `busy` / `away` toggle in the topbar. `away` removes the agent from round-robin rotation; `busy` is a social signal that does not affect routing. Status is purely user-controlled — no auto-transitions in this phase. Phase 14.2's 2-minute `last_seen_at` heartbeat remains the safety floor for stale sessions.

## Goals

1. Agents can opt out of automatic conversation assignment without logging out.
2. Agents can broadcast "I'm here but slammed" to teammates without affecting routing.
3. Existing 2-min heartbeat keeps protecting against closed-laptop scenarios — explicit status stacks on top, doesn't replace it.
4. Tight phase: one Livewire component, one service tweak, two new columns. No new permissions, no new screens, no JS state machine.

## Non-goals (deferred)

- Manager presence dashboard / agent roster page → Phase 15
- Auto-transitions (idle → away, browser-close → away, logout → away) — `last_seen_at` heartbeat already covers the "agent vanished" case at 2-min granularity
- Status history / audit log
- Custom status messages, emoji, "back at 2pm" hints
- Cross-agent status visibility on conversation cards / inbox lists

## Architecture

Two new columns on `users`, three constants on the User model, one new Livewire component (agent-only mount), one new WHERE clause in `RoundRobinAssigner`. No new tables, no new policies/permissions, no scheduled jobs.

```
┌─────────────────────────────┐
│ navigation.blade.php topbar │
│   @if user is agent:        │
│     <livewire:presence-     │
│        toggle />            │
└─────────────────────────────┘
            │ click "Busy"
            ▼
┌─────────────────────────────┐
│ App\Livewire\PresenceToggle │
│   setStatus('busy')         │
│   ↳ users.presence_status   │
│   ↳ users.presence_status_  │
│       set_at = now()        │
└─────────────────────────────┘
            │ next webhook
            ▼
┌─────────────────────────────┐
│ RoundRobinAssigner::next()  │
│   WHERE presence_status     │
│     != 'away'  ← new        │
│   (busy + available equal)  │
└─────────────────────────────┘
```

## Database

### New columns on `users`

| Column | Type | Default | Index | Purpose |
|---|---|---|---|---|
| `presence_status` | string(16) | `'available'` | yes | Round-robin filter key |
| `presence_status_set_at` | timestamp nullable | NULL | no | "Set N min ago" UI hint only |

### Migration

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('presence_status', 16)->default('available')->after('last_assigned_at');
        $table->index('presence_status');
        $table->timestamp('presence_status_set_at')->nullable()->after('presence_status');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropIndex(['presence_status']);
        $table->dropColumn(['presence_status', 'presence_status_set_at']);
    });
}
```

The `presence_status_set_at` column has no index — it is only read for the agent's own UI display, never as a filter or order key.

### User model additions

```php
public const PRESENCE_AVAILABLE = 'available';
public const PRESENCE_BUSY      = 'busy';
public const PRESENCE_AWAY      = 'away';

public const PRESENCE_STATUSES = [
    self::PRESENCE_AVAILABLE,
    self::PRESENCE_BUSY,
    self::PRESENCE_AWAY,
];
```

Add `'presence_status_set_at' => 'datetime'` to the `casts()` array. `presence_status` is a plain string — no cast needed.

## Routing change

`App\Services\RoundRobinAssigner::next()` gains exactly one WHERE clause:

```php
->where('presence_status', '!=', User::PRESENCE_AWAY)
```

Inserted between the existing `is_active` filter and the `last_seen_at` filter. The full query becomes:

```php
$agent = User::query()
    ->where('role', User::ROLE_AGENT)
    ->where('is_active', true)
    ->where('presence_status', '!=', User::PRESENCE_AWAY)
    ->where('last_seen_at', '>=', now()->subMinutes(self::AVAILABILITY_WINDOW_MINUTES))
    ->orderByRaw('last_assigned_at IS NULL DESC, last_assigned_at ASC')
    ->lockForUpdate()
    ->first();
```

`busy` and `available` are not differentiated — both are picked by the round-robin pointer (`last_assigned_at`). `busy` is purely a social signal for teammates and does not bias rotation. This avoids the "available agent flooded while busy agent idle" edge case that two-tier deprioritization would create.

## UI: `App\Livewire\PresenceToggle`

### Mount condition

In `resources/views/layouts/navigation.blade.php`, mounted next to the user-avatar dropdown only for users with `role = agent`:

```blade
@if(auth()->user()?->role === \App\Models\User::ROLE_AGENT)
    <livewire:presence-toggle />
@endif
```

Admins and managers do not see the toggle (their `presence_status` column exists and defaults to `available` but is never user-set).

### Component contract

```php
class PresenceToggle extends Component
{
    public string $status = User::PRESENCE_AVAILABLE;

    public function mount(): void
    {
        $this->status = Auth::user()->presence_status ?? User::PRESENCE_AVAILABLE;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, User::PRESENCE_STATUSES, true)) {
            return; // silent reject — invalid client input
        }

        Auth::user()->forceFill([
            'presence_status' => $status,
            'presence_status_set_at' => now(),
        ])->save();

        $this->status = $status;
    }

    public function render()
    {
        return view('livewire.presence-toggle', [
            'setAt' => Auth::user()->presence_status_set_at,
        ]);
    }
}
```

### View

Tailwind dropdown using Alpine for open/close state:

- Trigger: colored dot + status label + chevron
  - `available` → bg-green-500
  - `busy` → bg-orange-500
  - `away` → bg-gray-400
- Open menu: three options, each clickable, with the same dot+label, calling `wire:click="setStatus('available')"` etc.
- Tooltip on the trigger button: "Set {{ $setAt?->diffForHumans() }}" (e.g., "Set 12 minutes ago"). If `$setAt` is null, no tooltip.

No keyboard shortcut, no animation. Click-outside closes via Alpine `@click.outside`.

## Data flow

1. **Page load** — Agent navigates to any authenticated route. `navigation.blade.php` mounts `<livewire:presence-toggle />`. Component reads `Auth::user()->presence_status` (defaults to `available` from migration).
2. **Status change** — Agent clicks dropdown → "Busy". Livewire fires `setStatus('busy')`.
3. **Persistence** — Component validates the string against `User::PRESENCE_STATUSES` and writes both columns in a single `UPDATE`.
4. **Re-render** — Livewire re-renders the component; dot is now orange.
5. **Next webhook** — Inbound message/call arrives. `RoundRobinAssigner::next()` runs the same race-safe transaction as Phase 14.2, but the new WHERE clause excludes `away` agents. `busy` agents are picked normally based on `last_assigned_at`.
6. **Going away** — Agent clicks "Away". Same path. The next webhook will not pick this agent until they manually return to `available` or `busy`.

## Error handling

- **Invalid status string** — `setStatus()` silently rejects (no exception, no flash message). Client cannot send invalid values through the rendered Blade in normal use; rejection is defense-in-depth against tampering.
- **Concurrent updates** — Two browser tabs both setting status: last write wins. Acceptable; the window is human-speed (rare two-click race) and there is no consistency invariant beyond "current value is one of the three valid strings."
- **Auth lost mid-click** — `Auth::user()` returns null after session expiry. Component would error. Mitigation: standard Livewire auth middleware redirects to login before the request reaches the component. Component itself does not need a null-check (the `@if` guard in navigation.blade.php prevents render for unauthenticated users in the first place).
- **Migration on existing rows** — `default('available')` backfills all existing rows. No data migration script needed.

## Testing

### `RoundRobinAssignerTest` — 3 appended tests

1. **`test_excludes_away_agents`** — Single online agent with `presence_status='away'`; `next()` returns null.
2. **`test_includes_busy_agents`** — Single online agent with `presence_status='busy'`; `next()` returns that agent.
3. **`test_treats_busy_and_available_identically_in_rotation`** — Two online agents, one `available` one `busy`, both with `last_assigned_at = null`; over many `next()` calls in fresh transactions, both get picked (no preference). Concrete assertion: pick the first one (its `last_assigned_at` gets stamped), then `next()` again — second call picks the OTHER agent regardless of which had which status.

### `PresenceToggleTest` — 7 tests in new file

1. **`test_component_mounts_with_users_current_status`** — User has `presence_status='busy'` in DB; mounted component's `$status` is `'busy'`.
2. **`test_set_status_updates_database`** — `setStatus('away')` writes `presence_status='away'` to the user row.
3. **`test_set_status_stamps_set_at_timestamp`** — Before call, `presence_status_set_at` is null; after `setStatus('busy')`, it is within 1 second of `now()`.
4. **`test_set_status_rejects_invalid_string`** — `setStatus('partying')`; DB unchanged, no exception.
5. **`test_component_renders_correct_status_label`** — After `setStatus('away')`, rendered HTML contains the string `"Away"`.
6. **`test_component_requires_authentication`** — Unauthenticated `Livewire::test(PresenceToggle::class)` redirects (or errors per Livewire 4 convention).
7. **`test_non_agent_users_can_also_set_status`** — Admin user calls `setStatus('busy')`; succeeds. (The toggle is only mounted for agents in the view, but the component itself does not enforce role — admins setting status programmatically should not error. This guards against future managers-can-also-be-routed-to scenarios.)

### Webhook integration tests — verify no regression

`tests/Feature/Webhooks/InboundMessageProcessingTest.php` and `InboundCallProcessingTest.php` should continue to pass without change because the migration default is `'available'` — Phase 14.2 tests that create agents via factory will get `presence_status='available'` automatically and remain in the rotation.

If they fail (factory not setting the default), add explicit `'presence_status' => 'available'` to factory overrides — but this should not be necessary.

### Test trajectory

- Phase 14.2 baseline: 195 tests
- After service test additions: 198
- After PresenceToggle component tests: 205
- Final: **205 tests**

## File structure

### Create (4)

| File | Responsibility |
|---|---|
| `database/migrations/<ts>_add_presence_status_to_users.php` | Add `presence_status` + `presence_status_set_at` columns |
| `app/Livewire/PresenceToggle.php` | Topbar dropdown component |
| `resources/views/livewire/presence-toggle.blade.php` | Tailwind+Alpine dropdown view |
| `tests/Feature/Livewire/PresenceToggleTest.php` | 7 component tests |

### Modify (3)

| File | Change |
|---|---|
| `app/Models/User.php` | Add 3 PRESENCE_* constants, PRESENCE_STATUSES array, datetime cast on `presence_status_set_at` |
| `app/Services/RoundRobinAssigner.php` | Add one `where('presence_status', '!=', ...)` clause to `next()` |
| `tests/Feature/Services/RoundRobinAssignerTest.php` | Append 3 tests (away excluded, busy included, busy/available equivalence) |
| `resources/views/layouts/navigation.blade.php` | Add `<livewire:presence-toggle />` mount inside agent-role guard |

(Five files modified counting both view and tests.)

## Operational notes

- Existing seeded users default to `presence_status='available'` automatically via the column default. No data migration needed.
- The 2-min `last_seen_at` heartbeat from Phase 14.2 still applies. An `away` agent with stale `last_seen_at` is excluded twice — once by the new clause, once by the old. Defense in depth is fine.
- No environment variable, feature flag, or rollout gate. Feature is universally on once merged.
- Direct-to-main commits (user-approved). No feature branch.

## Open questions / known limitations

- **No "auto-set away on logout"** — when an agent logs out, `presence_status` retains its last value (typically `available`). Combined with `last_seen_at` going stale, the agent falls out of rotation at the 2-minute mark anyway. On next login, status is whatever they last set — which is desired (agent rejoins at the same status they left at).
- **No multi-tab consistency push** — if Maria has two tabs open and changes status in tab A, tab B does not update until its next Livewire poll cycle (3s via RealtimePulse). Acceptable; the only consequence is the dot in tab B is briefly stale.
- **The `presence_status_set_at` "diffForHumans" tooltip becomes increasingly imprecise over long durations** — Carbon's `diffForHumans` is fine for "12 minutes ago" / "2 hours ago" but degrades for "set 3 days ago." If it bothers anyone, change the tooltip format. Not a Phase 14.3 concern.
