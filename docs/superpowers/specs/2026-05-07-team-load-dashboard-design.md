# Phase 15 — Manager Team-Load Dashboard Design

**Date:** 2026-05-07
**Status:** Approved
**Builds on:** Phase 14.1-14.4 (presence, round-robin, capacity cap)

## Summary

Add a new manager-only page at `/team` showing the agent roster as a 4-column polling table: Name, Presence, Active load (X/cap), Last seen. Refreshes every 10 seconds via Livewire poll. Sorted alphabetically by name. Read-only — no inline actions, no historical data, no saturation banner.

This is the consolidation of two visibility deferrals from prior phases: the agent roster dashboard (deferred from Phase 14.3 Q6) and the per-agent capacity badge (deferred from Phase 14.4 Q5). Both fit under one focused dashboard page rather than scattering visibility across the app.

## Goals

1. Give managers an at-a-glance view of "who's online, who's loaded, who's idle" without clicking through individual conversation pages.
2. Reuse the same active-conversation count semantics that `RoundRobinAssigner` uses for capacity gating — single source of truth.
3. Tight scope: one page, four columns, ~30 lines of new server code, ~6 tests. No new schema, no new permissions, no new infrastructure.
4. Keep agents OUT of this view — the dashboard is a supervision tool, and peer-visibility creates social-dynamic side effects (gaming the metric, peer pressure to look busy) that aren't part of the v1 use case.

## Non-goals (deferred)

- Sortable column headers / status filter / search box → defer to Phase 16+ when team size justifies the UI cost
- Per-row inline actions (jump to conversations, force-status, manual reassign) → polling tables + click targets are an antipattern; existing `/users` and individual conversation pages already host the actions managers need
- Historical totals ("assigned 12 today", "avg response 4m") → performance management is its own product with its own time-zone handling and HR sensitivity; gets a dedicated Phase 16 reports page
- Mini sparklines / charts per row → Phase 16 analytics
- Saturation banner ("⚠ all agents at capacity") → empirical signal from the table itself + the existing Unassigned filter is sufficient; add a banner only if real reports surface that managers are missing the signal
- Real-time push via broadcasting (Reverb/Pusher) → 10-second polling is sufficient for the current scale
- Per-WhatsApp-instance breakdown → multi-product team feature for the future
- Mobile-optimized layout → desktop-first for v1; managers do this work on a laptop

## Architecture

```
┌──────────────────────────────────────────┐
│ /team route (middleware: users.view)     │
│   TeamLoadController::index()            │
│     return view('team.index')            │
└──────────────────────────────────────────┘
                    │
                    ▼
┌──────────────────────────────────────────┐
│ resources/views/team/index.blade.php     │
│   <x-app-layout>                         │
│     <livewire:team-load />               │
│   </x-app-layout>                        │
└──────────────────────────────────────────┘
                    │ wire:poll.10s
                    ▼
┌──────────────────────────────────────────┐
│ App\Livewire\TeamLoad::render()          │
│   $cap = Setting::get(...,5)             │
│   $cutoff = now()->subHours(             │
│       RoundRobinAssigner::               │
│       ACTIVE_WINDOW_HOURS)               │
│   $agents = User::query()                │
│       ->where role=agent + is_active     │
│       ->withCount(assignedConversations  │
│            where last_inbound_at >=      │
│            cutoff)                       │
│       ->orderBy('name')                  │
│       ->get()                            │
│   return view('livewire.team-load',      │
│       compact('agents','cap',            │
│                'availabilityCutoff'))    │
└──────────────────────────────────────────┘
                    │
                    ▼
┌──────────────────────────────────────────┐
│ resources/views/livewire/                │
│   team-load.blade.php                    │
│   4-column table (Name, Presence,        │
│   Active load, Last seen)                │
└──────────────────────────────────────────┘
```

The `withCount` produces ONE round trip — a correlated count subquery, no N+1. `orderBy('name')` runs in SQL, not PHP.

## Database

No schema changes. Existing columns:
- `users.name`, `users.email`, `users.role`, `users.is_active`
- `users.presence_status`, `users.presence_status_set_at`, `users.last_seen_at` (Phase 14.3)
- `conversations.assigned_to_user_id`, `conversations.last_inbound_at` (Phase 14.x — already indexed)

One new Eloquent relationship is required on `User`:

```php
public function assignedConversations(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(\App\Models\Conversation::class, 'assigned_to_user_id');
}
```

Verified during planning: `User` does not currently declare this relationship. The `assigned_to_user_id` foreign key exists on `conversations` (Phase 14.x), but no relationship is wired on `User`. Adding it is necessary for `withCount('assignedConversations as active_count')` to work.

## Service / model changes

### `App\Models\User`

Add the new relationship. Place after the existing `whatsappInstances()` relationship (around line 97) to keep relationship declarations grouped:

```php
/**
 * Conversations where this user is the assigned agent. Used by the
 * Phase 15 team-load dashboard's withCount query and by future
 * features that need to enumerate an agent's threads.
 */
public function assignedConversations(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(\App\Models\Conversation::class, 'assigned_to_user_id');
}
```

No other model changes.

### `App\Services\RoundRobinAssigner`

No changes to the routing service itself, BUT `TeamLoad` reads two of its constants:
- `RoundRobinAssigner::ACTIVE_WINDOW_HOURS` (24)
- `RoundRobinAssigner::AVAILABILITY_WINDOW_MINUTES` (2)

These constants are already `public const`, so direct access is the right move. Centralizes the "active" and "online" definitions so the dashboard never drifts from the routing service's semantics.

## Routes

`routes/web.php` — add ONE route inside the existing authenticated route group:

```php
Route::middleware('permission:users.view')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    // ... existing user routes ...

    // Phase 15 team-load dashboard
    Route::get('/team', [\App\Http\Controllers\TeamLoadController::class, 'index'])
        ->name('team.index');
});
```

Reuses the existing `permission:users.view` middleware that already gates `/users`. Same audience, no new permission to define.

## Controller

`app/Http/Controllers/TeamLoadController.php` — three lines of meaningful code:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

class TeamLoadController extends Controller
{
    public function index(): View
    {
        return view('team.index');
    }
}
```

The Blade view mounts the Livewire component; all data work happens there. The controller exists only to host the route.

## Livewire component — `App\Livewire\TeamLoad`

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Setting;
use App\Models\User;
use App\Services\RoundRobinAssigner;
use Livewire\Component;

/**
 * Manager-only team-load dashboard. Renders the agent roster as a
 * 4-column table polling every 10 seconds. Shows presence, active
 * conversation load (with the same definition RoundRobinAssigner
 * uses for capacity gating), and last-seen timestamp.
 *
 * Read-only. No inline actions — managers reassign conversations
 * via the assignee dropdown on the conversation page itself, and
 * manage user CRUD via /users.
 *
 * Mounted at /team via TeamLoadController, gated by the existing
 * permission:users.view middleware.
 */
class TeamLoad extends Component
{
    public function render()
    {
        $cap = (int) Setting::get('round_robin_cap_per_agent', 5);
        $cutoff = now()->subHours(RoundRobinAssigner::ACTIVE_WINDOW_HOURS);
        $availabilityCutoff = now()->subMinutes(
            RoundRobinAssigner::AVAILABILITY_WINDOW_MINUTES
        );

        $agents = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('is_active', true)
            ->withCount(['assignedConversations as active_count' => function ($q) use ($cutoff) {
                $q->where('last_inbound_at', '>=', $cutoff);
            }])
            ->orderBy('name')
            ->get();

        return view('livewire.team-load', [
            'agents' => $agents,
            'cap' => $cap,
            'availabilityCutoff' => $availabilityCutoff,
        ]);
    }
}
```

Why no `mount()` method: the component carries no state across polls. Each `render()` is a stateless query. The cap and cutoffs are recalculated on every poll — they're cheap (one settings row read, two `now()` calls), and recomputing means an admin who changes the cap mid-poll sees it apply on the next 10-second tick without needing a refresh.

Why `(int)` cast: defense-in-depth — same logic as in `RoundRobinAssigner::next()`. A non-numeric value coerces to 0, which displays as "0 / 0" in the table — visually obvious and triggers a manager investigation rather than rendering bogus data.

## View — `resources/views/team/index.blade.php`

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Team') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <livewire:team-load />
        </div>
    </div>
</x-app-layout>
```

## View — `resources/views/livewire/team-load.blade.php`

```blade
<div wire:poll.10s class="bg-white shadow-sm rounded-lg overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Name') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Presence') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Active load') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Last seen') }}</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-100">
            @forelse($agents as $agent)
                <tr>
                    <td class="px-6 py-4">
                        <div class="text-sm font-medium text-gray-900">{{ $agent->name }}</div>
                        <div class="text-xs text-gray-500">{{ $agent->email }}</div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="inline-flex items-center gap-2 text-sm">
                            <span class="w-2.5 h-2.5 rounded-full
                                @if($agent->presence_status === \App\Models\User::PRESENCE_AVAILABLE) bg-green-500
                                @elseif($agent->presence_status === \App\Models\User::PRESENCE_BUSY) bg-orange-500
                                @else bg-gray-400 @endif"></span>
                            <span class="text-gray-700">{{ ucfirst($agent->presence_status) }}</span>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <span class="{{ $agent->active_count >= $cap ? 'text-red-600 font-semibold' : 'text-gray-700' }}">
                            {{ $agent->active_count }} / {{ $cap }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        @if($agent->last_seen_at && $agent->last_seen_at >= $availabilityCutoff)
                            <span class="text-green-700 font-medium">online</span>
                        @elseif($agent->last_seen_at)
                            <span class="text-gray-600">{{ $agent->last_seen_at->diffForHumans() }}</span>
                        @else
                            <span class="text-gray-400 italic">never</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-12 text-center text-gray-400">
                        {{ __('No agents on this team') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
```

The presence-dot color mapping matches `PresenceToggle` exactly (green/orange/gray for available/busy/away).

The "active load" cell uses `text-red-600` when at-or-above cap — a small visual cue that this agent is currently filtered out of round-robin rotation.

## Sidebar entry — `resources/views/layouts/navigation.blade.php`

Add inside the existing ADMINISTRATION section. The current `Users` link sits there gated by `@can('users.view')`. The new "Team" link goes ABOVE Users so monitoring (more frequent operation) appears before CRUD (rarer operation):

```blade
@can('users.view')
    <x-sidebar-link :href="route('team.index')" :active="request()->routeIs('team.*')">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
        </svg>
        {{ __('Team') }}
    </x-sidebar-link>
@endcan
```

The icon is the standard "people" Heroicons set, consistent with the Users icon already in the sidebar.

## Data flow

1. Manager clicks "Team" in sidebar → `GET /team`.
2. `permission:users.view` middleware checks. If denied → 403. If allowed → controller runs.
3. `TeamLoadController::index()` returns `view('team.index')`.
4. Blade page renders within `<x-app-layout>`, mounts `<livewire:team-load />`.
5. Livewire `render()` runs immediately: queries all active agents with `withCount('active_count')`, reads cap from `settings`, computes the 10-min availability cutoff.
6. Table renders. Agents whose load equals or exceeds cap are visually marked red. Agents with `last_seen_at` fresh show "online" in green.
7. After 10s, `wire:poll.10s` fires `render()` again. New counts/timestamps flow in. Rows re-render in place — no row reorder because sort is by name.
8. Manager closes the tab → polling stops automatically (Livewire teardown).

## Error handling

| Failure mode | Behavior | Why correct |
|---|---|---|
| No agents in the system | `@empty` branch shows "No agents on this team" | Cleaner than empty table; signals "this is intentional empty state" |
| Agent has `last_seen_at = NULL` | Last-seen cell shows "never" in italic gray | Distinct from "5 hr ago" — surfaces "this user has presence-fresh-data missing" |
| Cap settings row missing | `Setting::get(...,5)` falls back to 5 | Same defaulting as `RoundRobinAssigner` — single behavior across both |
| Cap is non-numeric in DB | `(int)` cast → 0; table shows "0 / 0" | Visually broken — manager investigates and fixes the row |
| Permission missing | Middleware 403s before component mount | Standard Laravel; no special handling |
| User loses session mid-poll | Livewire's CSRF / session-expiry handling redirects to login on next poll | Standard Livewire behavior |
| Agent table row deleted between polls | Disappears from the next render naturally | `withCount` queries live data each poll |

## Testing

Six new tests across two files.

### `tests/Feature/Livewire/TeamLoadTest.php` (5 tests)

1. **`test_renders_active_agents_with_their_active_count`**
   Two online agents A and B alphabetically. A has 1 active conversation, B has 2. Component renders. `assertSeeInOrder(['A', '1 / 5', 'B', '2 / 5'])` — verifies count semantics AND alphabetical sort in one assertion.

2. **`test_excludes_inactive_agents`**
   One active agent + one with `is_active = false`. Component renders. Active agent's name appears in output, inactive agent's name does not.

3. **`test_excludes_non_agent_roles`**
   Admin user + agent user. Component renders. Only agent appears (admin name does not).

4. **`test_does_not_count_old_inbound_conversations`**
   Agent with 3 conversations: one with `last_inbound_at = now()`, two with `last_inbound_at = now()->subHours(25)`. Active count renders as `1 / 5`. Same semantic as `RoundRobinAssigner` — single source of truth.

5. **`test_renders_correct_last_seen_label`**
   Three agents:
   - `last_seen_at = now()` → cell contains "online"
   - `last_seen_at = now()->subHours(1)` → cell contains "1 hour ago"
   - `last_seen_at = null` → cell contains "never"

### `tests/Feature/Http/TeamLoadRouteTest.php` (1 test)

6. **`test_team_route_requires_users_view_permission`**
   - Guest GET /team → assert redirect to login.
   - Authenticated user without permission → assert 403.
   - Manager with `users.view` permission → assert 200 + sees the page heading "Team".

### Test trajectory

- Phase 14.4 baseline: 210 tests
- After Phase 15: **216 tests** (+5 component tests, +1 route test)

No changes to existing tests.

## File structure

### Files to create (5)

| File | Responsibility |
|---|---|
| `app/Http/Controllers/TeamLoadController.php` | Single `index()` returning the Blade page |
| `app/Livewire/TeamLoad.php` | `render()` queries agents with active counts |
| `resources/views/team/index.blade.php` | App layout wrapper that mounts the Livewire component |
| `resources/views/livewire/team-load.blade.php` | 4-column polling table |
| `tests/Feature/Livewire/TeamLoadTest.php` | 5 component tests |
| `tests/Feature/Http/TeamLoadRouteTest.php` | 1 route/permission test |

### Files to modify (3)

| File | Change |
|---|---|
| `app/Models/User.php` | Add `assignedConversations()` HasMany relationship |
| `routes/web.php` | Add `Route::get('/team', ...)` inside the `permission:users.view` group |
| `resources/views/layouts/navigation.blade.php` | Add `<x-sidebar-link>` for Team above the existing Users link, gated by `@can('users.view')` |

(Six new files + three modifications.)

## Operational notes

- Default behavior post-deploy: managers see a new "Team" sidebar link. Click it. See the roster. Zero configuration.
- The new `assignedConversations` relationship may also serve future features (per-agent conversation list, agent reassignment workflows). Adding it now is a one-line investment that pays back any time we need to enumerate an agent's threads.
- Polling cost: each manager-tab open generates ~6 queries/min (one settings row + one users-with-count query per 10s tick). Comparable to RealtimePulse's overhead. Acceptable at any realistic team scale.
- Direct-to-main commits, no feature flag.

## Open questions / known limitations

- **No "include inactive agents" toggle.** Deactivated agents are silently filtered out. If managers need to see who's deactivated, they go to `/users`. Acceptable separation of concerns.
- **No timezone handling for "last seen" rendering.** Carbon's `diffForHumans` is locale/timezone-aware but the underlying timestamps are stored as UTC. For the "5 hr ago" labels, this is invisible (relative durations don't depend on timezone). For a future "Last seen at 3:45 PM" absolute display, we'd add the user's timezone preference — Phase 16+.
- **No "Refresh now" button.** The 10-second poll is the only refresh mechanism. If a manager wants instant freshness, they reload the page. Acceptable — adding a manual button alongside auto-polling is redundant.
- **No accessibility audit yet.** The table uses semantic `<th>` headers and the polling div doesn't trap focus, but a formal screen-reader pass is not in this phase. If accessibility becomes a project priority, audit all dashboards together as a dedicated phase.
