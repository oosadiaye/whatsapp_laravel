# Phase 15 — Manager Team-Load Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a manager-only `/team` page that polls every 10s and renders the agent roster as a 4-column read-only table (Name, Presence, Active load X/cap, Last seen).

**Architecture:** New `TeamLoadController` (route stub), new `App\Livewire\TeamLoad` component (single `render()` running one `withCount` query), two new Blade views (page wrapper + table fragment), one new sidebar entry, one new `User::assignedConversations()` HasMany relationship. Reuses `RoundRobinAssigner::ACTIVE_WINDOW_HOURS` + `AVAILABILITY_WINDOW_MINUTES` constants and the existing `permission:users.view` middleware.

**Tech Stack:** Laravel 12 · PHP 8.2 (XAMPP local; opcache disabled for artisan) · Livewire 4 · Tailwind · spatie/laravel-permission · PHPUnit 11.

---

## Spec reference

Full design: `docs/superpowers/specs/2026-05-07-team-load-dashboard-design.md` (committed `8f6fac3`).

## File structure

### Files to create (6)

| File | Responsibility |
|---|---|
| `app/Http/Controllers/TeamLoadController.php` | One-method controller (`index()`) returning the wrapper view |
| `app/Livewire/TeamLoad.php` | Stateless `render()` — queries agents with active counts, reads cap from Setting |
| `resources/views/team/index.blade.php` | `<x-app-layout>` wrapper mounting `<livewire:team-load />` |
| `resources/views/livewire/team-load.blade.php` | 4-column `wire:poll.10s` table (Name, Presence, Active load, Last seen) |
| `tests/Feature/Livewire/TeamLoadTest.php` | 5 component tests |
| `tests/Feature/Http/TeamLoadRouteTest.php` | 1 route/permission test |

### Files to modify (3)

| File | Change |
|---|---|
| `app/Models/User.php` | Add `assignedConversations()` HasMany relationship |
| `routes/web.php` | Add `Route::get('/team', ...)` inside the existing `permission:users.view` group |
| `resources/views/layouts/navigation.blade.php` | Add `<x-sidebar-link>` for "Team" inside the existing Administration section, ABOVE the existing Users link |

(Six new files, three modifications.)

### Existing infrastructure reused (verified before planning)

- `App\Services\RoundRobinAssigner` (Phase 14.4, last touched commit `43bfb2e`): public constants `ACTIVE_WINDOW_HOURS = 24` and `AVAILABILITY_WINDOW_MINUTES = 2`. Direct access (`RoundRobinAssigner::ACTIVE_WINDOW_HOURS`) is the right pattern — anti-drift mechanism so dashboard never disagrees with the routing service.
- `App\Models\Setting::get(string $key, $default = null)` returns string-or-default. Cap key `round_robin_cap_per_agent` (Phase 14.4 default `'5'`).
- `App\Models\User`: constants `ROLE_AGENT`, `PRESENCE_AVAILABLE`, `PRESENCE_BUSY`, `PRESENCE_AWAY` (Phase 14.3). Has existing relationships (`campaigns`, `contactGroups`, `contacts`, `whatsappInstances`, `messageTemplates`) — `assignedConversations` is the new addition. Verified: NO existing `Conversation`-related relationship on `User`.
- `App\Models\Conversation` has the `assigned_to_user_id` foreign key + `last_inbound_at` timestamp + `(assigned_to_user_id)` index.
- `routes/web.php` has an existing `Route::middleware('permission:users.view')->group(function () { Route::get('/users', ...); })` block (around line 191-193). The new `/team` route slots inside the same group.
- `resources/views/layouts/navigation.blade.php` has the Administration section at lines 157-170, gated by `@can('users.view')`. The existing Users sidebar link uses `<x-sidebar-link>` with an inline SVG icon.
- `resources/views/team/index.blade.php` — directory `team/` does not yet exist; will be created by the implementer when writing the file.

### Environment notes (apply to every task)

- Always prefix artisan/phpunit commands with `php -d opcache.enable=0 -d opcache.enable_cli=0` (XAMPP-Windows opcache permission bug).
- Tests use SQLite in-memory via `RefreshDatabase`.
- Branch: `main`, committing direct (user-approved).
- Baseline: 210 tests must remain green. Final target: **216 tests** (+5 component + 1 route).

---

# Tasks

## Task 1: User model — `assignedConversations()` relationship

**Files:**
- Modify: `app/Models/User.php`

This task is the unblocker — Task 2's `withCount('assignedConversations as active_count')` won't resolve without it. Tiny, no test of its own (the relationship is exercised by Task 2's component tests).

- [ ] **Step 1: Add the relationship method**

Open `app/Models/User.php`. Find the existing `whatsappInstances()` relationship (around line 95-97):

```php
public function whatsappInstances(): HasMany
{
    return $this->hasMany(WhatsAppInstance::class);
}
```

INSERT this new method directly after it (before the next existing method):

```php
/**
 * Conversations where this user is the assigned agent. Used by the
 * Phase 15 team-load dashboard's withCount query and by future
 * features that need to enumerate an agent's threads.
 */
public function assignedConversations(): HasMany
{
    return $this->hasMany(\App\Models\Conversation::class, 'assigned_to_user_id');
}
```

The `HasMany` import is already present at the top of the file (used by the existing relationships) — no new `use` statement.

The fully-qualified `\App\Models\Conversation::class` is intentional: `Conversation` may not currently be imported in `User.php` (the existing relationships all live in `App\Models\*`, but checking the imports is a single grep). Using the FQN avoids an import-vs-no-import edit decision. If you prefer, add `use App\Models\Conversation;` at the top with the other model imports and shorten to `Conversation::class`.

- [ ] **Step 2: Run full suite to confirm no regression**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (210 tests, ...)`. The new relationship method is dormant — nothing in the codebase calls it yet — so all existing tests pass unchanged.

- [ ] **Step 3: Commit**

```bash
git add app/Models/User.php
git commit -m "feat(team): add User::assignedConversations() HasMany relationship

Wires the inverse of Conversation.assigned_to_user_id, enabling
Phase 15's TeamLoad component to query each agent's active
conversation count via withCount('assignedConversations'). Also
useful for future features that need to enumerate an agent's
threads (per-agent conversation list, reassignment workflows)."
```

---

## Task 2: TeamLoad Livewire component + view + 5 tests (TDD)

**Files:**
- Create: `app/Livewire/TeamLoad.php`
- Create: `resources/views/livewire/team-load.blade.php`
- Create: `tests/Feature/Livewire/TeamLoadTest.php`

This task ships the component, the table view, and 5 tests in one TDD cycle. The component is small (~30 lines) and the view is rendered by `Livewire::test(...)->assertSee(...)` assertions — writing them together is the cleanest order.

- [ ] **Step 1: Create the failing test file**

Create `tests/Feature/Livewire/TeamLoadTest.php` with this EXACT content:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\TeamLoad;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_renders_active_agents_with_their_active_count(): void
    {
        $a = $this->makeAgent('Alice');
        $b = $this->makeAgent('Bob');

        // Alice: 1 active conversation, Bob: 2.
        $this->makeAssignedConversation($a, lastInboundAt: now());
        $this->makeAssignedConversation($b, lastInboundAt: now());
        $this->makeAssignedConversation($b, lastInboundAt: now());

        Livewire::test(TeamLoad::class)
            ->assertSeeInOrder(['Alice', '1 / 5', 'Bob', '2 / 5']);
    }

    public function test_excludes_inactive_agents(): void
    {
        $active = $this->makeAgent('ActiveAgent');
        $inactive = $this->makeAgent('InactiveAgent', isActive: false);

        Livewire::test(TeamLoad::class)
            ->assertSee('ActiveAgent')
            ->assertDontSee('InactiveAgent');
    }

    public function test_excludes_non_agent_roles(): void
    {
        $admin = User::factory()->create([
            'name' => 'AdminPerson',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $admin->assignRole(User::ROLE_ADMIN);

        $agent = $this->makeAgent('AgentPerson');

        Livewire::test(TeamLoad::class)
            ->assertSee('AgentPerson')
            ->assertDontSee('AdminPerson');
    }

    public function test_does_not_count_old_inbound_conversations(): void
    {
        $agent = $this->makeAgent('Charlie');

        // 1 fresh + 2 old (>24h) — only the fresh one should count.
        $this->makeAssignedConversation($agent, lastInboundAt: now());
        $this->makeAssignedConversation($agent, lastInboundAt: now()->subHours(25));
        $this->makeAssignedConversation($agent, lastInboundAt: now()->subHours(48));

        Livewire::test(TeamLoad::class)
            ->assertSee('1 / 5');
    }

    public function test_renders_correct_last_seen_label(): void
    {
        $online = $this->makeAgent('Dana');
        $online->forceFill(['last_seen_at' => now()])->save();

        $stale = $this->makeAgent('Eve');
        $stale->forceFill(['last_seen_at' => now()->subHour()])->save();

        $never = $this->makeAgent('Fred');
        // last_seen_at remains null

        Livewire::test(TeamLoad::class)
            ->assertSee('online')          // Dana
            ->assertSee('1 hour ago')      // Eve (Carbon diffForHumans)
            ->assertSee('never');          // Fred
    }

    private function makeAgent(string $name, bool $isActive = true): User
    {
        $agent = User::factory()->create([
            'name' => $name,
            'email' => strtolower($name).'-'.uniqid().'@example.com',
            'role' => User::ROLE_AGENT,
            'is_active' => $isActive,
        ]);
        $agent->assignRole(User::ROLE_AGENT);

        return $agent;
    }

    private function makeAssignedConversation(
        User $agent,
        \Illuminate\Support\Carbon $lastInboundAt,
    ): Conversation {
        $owner = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $owner->id,
        ]);
        $contact = Contact::factory()->create([
            'user_id' => $owner->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);

        return Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
            'last_inbound_at' => $lastInboundAt,
            'last_message_at' => $lastInboundAt,
            'unread_count' => 0,
        ]);
    }
}
```

The `makeAssignedConversation` helper mirrors the one used in Phase 14.4's `RoundRobinAssignerTest` (each conversation needs a unique `(contact_id, whatsapp_instance_id)` pair, so a fresh owner+instance+contact triple is created per call). If a factory call shape doesn't match the actual factory, read the factory file and adjust.

- [ ] **Step 2: Run, confirm all 5 tests ERROR with "class not found"**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/TeamLoadTest.php --no-coverage
```

Expected: 5 errors, all with `Class "App\Livewire\TeamLoad" not found`.

- [ ] **Step 3: Create the Livewire component**

Create `app/Livewire/TeamLoad.php` with this EXACT content:

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
 *
 * Reuses RoundRobinAssigner::ACTIVE_WINDOW_HOURS and
 * AVAILABILITY_WINDOW_MINUTES so dashboard semantics never drift
 * from routing semantics.
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

- [ ] **Step 4: Create the table view**

Create `resources/views/livewire/team-load.blade.php` with this EXACT content:

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

- [ ] **Step 5: Run the test file, confirm all 5 PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/TeamLoadTest.php --no-coverage
```

Expected: `OK (5 tests, ...)`.

If `test_renders_correct_last_seen_label` fails on the "1 hour ago" string, Carbon may render it as "1 hour ago" or "an hour ago" depending on the version. If you see "an hour ago" in the output, change the test's `assertSee` from `'1 hour ago'` to whatever Carbon actually emits. Don't change the view — match the assertion to reality.

If the test_excludes_non_agent_roles test fails because the `Setting` model is missing from imports, the component imports it correctly so this won't happen — but if for some reason the test setup creates Setting rows that affect the view, double-check seeded data.

- [ ] **Step 6: Run full suite to confirm no regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (215 tests, ...)` — 210 prior + 5 new.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/TeamLoad.php resources/views/livewire/team-load.blade.php tests/Feature/Livewire/TeamLoadTest.php
git commit -m "feat(team): TeamLoad Livewire component + view + 5 tests

Stateless render() queries User where role=agent + is_active=true,
withCount('assignedConversations as active_count' filtered by
last_inbound_at >= now()-RoundRobinAssigner::ACTIVE_WINDOW_HOURS).
Returns alphabetically-sorted agents with their cap, active count,
and last_seen_at fields rendered into a 4-column wire:poll.10s table.

Constants from RoundRobinAssigner (ACTIVE_WINDOW_HOURS,
AVAILABILITY_WINDOW_MINUTES) are read directly — anti-drift mechanism
so dashboard semantics never disagree with the routing service's
actual filter logic.

Tests cover: agent roster + counts, inactive-agent exclusion,
non-agent role exclusion, old-inbound exclusion, last-seen label
rendering (online / diffForHumans / never)."
```

---

## Task 3: Controller + page wrapper view + route + sidebar entry

**Files:**
- Create: `app/Http/Controllers/TeamLoadController.php`
- Create: `resources/views/team/index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/navigation.blade.php`

This task makes the component reachable from the sidebar. Four small surface changes, no test (the route/permission test is Task 4).

- [ ] **Step 1: Create the controller**

Create `app/Http/Controllers/TeamLoadController.php` with this EXACT content:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Hosts the /team route. The actual data work happens in the
 * App\Livewire\TeamLoad component mounted by the wrapper view.
 */
class TeamLoadController extends Controller
{
    public function index(): View
    {
        return view('team.index');
    }
}
```

- [ ] **Step 2: Create the wrapper view**

Create `resources/views/team/index.blade.php` with this EXACT content. (You may need to `mkdir resources/views/team` first if your editor doesn't auto-create the directory.)

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

- [ ] **Step 3: Register the route**

Open `routes/web.php`. Find the existing `permission:users.view` middleware group (around line 191-193):

```php
Route::middleware('permission:users.view')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
});
```

Replace with:

```php
Route::middleware('permission:users.view')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');

    Route::get('/team', [\App\Http\Controllers\TeamLoadController::class, 'index'])
        ->name('team.index');
});
```

The fully-qualified `\App\Http\Controllers\TeamLoadController::class` is intentional — the file's existing imports list may or may not include it, and using FQN avoids the import edit. If you prefer the short form, add `use App\Http\Controllers\TeamLoadController;` at the top of the routes file.

- [ ] **Step 4: Add the sidebar link**

Open `resources/views/layouts/navigation.blade.php`. Find the Administration section (lines 157-170):

```blade
{{-- Section: Administration --}}
@can('users.view')
    <div>
        <h3 class="px-3 mb-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">{{ __('Administration') }}</h3>
        <div class="space-y-1">
            <x-sidebar-link :href="route('users.index')" :active="request()->routeIs('users.*')">
                <svg class="w-5 h-5" ...>
                    ...
                </svg>
                {{ __('Users') }}
            </x-sidebar-link>
        </div>
    </div>
@endcan
```

INSERT a new `<x-sidebar-link>` for "Team" BEFORE the existing Users link (so monitoring appears above CRUD). The fully updated section:

```blade
{{-- Section: Administration --}}
@can('users.view')
    <div>
        <h3 class="px-3 mb-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">{{ __('Administration') }}</h3>
        <div class="space-y-1">
            <x-sidebar-link :href="route('team.index')" :active="request()->routeIs('team.*')">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                {{ __('Team') }}
            </x-sidebar-link>
            <x-sidebar-link :href="route('users.index')" :active="request()->routeIs('users.*')">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                </svg>
                {{ __('Users') }}
            </x-sidebar-link>
        </div>
    </div>
@endcan
```

The new Team icon is the standard Heroicons "users-group" path (different from the single-user icon used by /users).

- [ ] **Step 5: Run full suite to confirm nothing layout-related broke**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (215 tests, ...)`. The view clear is a precaution because Blade caches the navigation include.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/TeamLoadController.php resources/views/team/index.blade.php routes/web.php resources/views/layouts/navigation.blade.php
git commit -m "feat(team): wire /team route + Team sidebar link

Four matched changes that make the Phase 15 dashboard reachable:

1. TeamLoadController: single index() returning the wrapper view.
2. resources/views/team/index.blade.php: x-app-layout wrapper that
   mounts <livewire:team-load /> inside the standard page chrome.
3. routes/web.php: new /team route registered inside the existing
   permission:users.view middleware group (matches /users gating).
4. navigation.blade.php: new <x-sidebar-link> for Team placed ABOVE
   the existing Users link in the Administration section, so
   monitoring (more frequent operation) appears before CRUD."
```

---

## Task 4: Route/permission test

**Files:**
- Create: `tests/Feature/Http/TeamLoadRouteTest.php`

This task adds the gate test that proves the route is properly permission-protected. Three assertions in one test cover the three audiences (guest / non-permission user / manager).

- [ ] **Step 1: Create the test file**

Create `tests/Feature/Http/TeamLoadRouteTest.php` with this EXACT content:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamLoadRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_team_route_requires_users_view_permission(): void
    {
        // Guest → redirect to login
        $this->get(route('team.index'))
            ->assertRedirect(route('login'));

        // Authenticated agent (no users.view permission) → 403
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);
        $agent->assignRole(User::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('team.index'))
            ->assertForbidden();

        // Manager (has users.view) → 200, sees the page heading
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);
        $manager->assignRole(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee('Team');
    }
}
```

The single test method covers all three audiences in sequence. Each `actingAs` re-authenticates, so the prior assertions don't leak.

If the agent role has `users.view` permission unexpectedly, the second assertion (`assertForbidden`) will fail with `Status code [403] does not equal [200]`. Verify by checking `RolesAndPermissionsSeeder`: the agent role should NOT have `users.view`. If it does, that's a Phase 14.x regression — investigate before changing this test.

- [ ] **Step 2: Run the test, confirm PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Http/TeamLoadRouteTest.php --no-coverage
```

Expected: `OK (1 test, 4 assertions)`.

- [ ] **Step 3: Run the full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (216 tests, ...)`.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Http/TeamLoadRouteTest.php
git commit -m "test(team): /team route requires users.view permission

Single test covering all three audiences:
- Guest GETs /team → redirected to login
- Authenticated agent (no users.view) → 403 Forbidden
- Manager (has users.view) → 200 OK, page renders 'Team' heading

Catches any future regression where the route's permission middleware
gets removed or weakened."
```

---

## Task 5: Final verification + push

**Files:** none

- [ ] **Step 1: Confirm clean working tree**

```bash
git status
```

Expected: `nothing to commit, working tree clean`.

- [ ] **Step 2: Run the full suite one last time**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (216 tests, ...)`.

- [ ] **Step 3: Inspect the Phase 15 commit chain**

```bash
git log --oneline -7
```

Expected to see, top to bottom:
- Task 4: `test(team): /team route requires users.view permission`
- Task 3: `feat(team): wire /team route + Team sidebar link`
- Task 2: `feat(team): TeamLoad Livewire component + view + 5 tests`
- Task 1: `feat(team): add User::assignedConversations() HasMany relationship`
- Plan: `docs: add Phase 15 team-load dashboard plan`
- Spec: `docs(spec): phase 15 manager team-load dashboard`
- Phase 14.4 final: `feat(routing): expose round_robin_cap_per_agent in Settings UI`

- [ ] **Step 4: Push to origin**

```bash
git push origin main
```

Expected: `<prior SHA>..<Task 4 SHA>  main -> main`.

- [ ] **Step 5: Manual smoke test (recommended)**

Spin up the dev server:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan serve
```

Visit `http://localhost:8000/team` as the seeded admin (`admin@blastiq.com` / `password`). Confirm:
- "Team" link appears in the sidebar's Administration section, above "Users."
- Page heading shows "Team."
- 4-column table renders. If no agents exist yet, "No agents on this team" message shows.
- Create an agent in tinker if needed:
  ```bash
  php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute="\$u = App\Models\User::factory()->create(['name' => 'Smoke Test', 'email' => 'smoke@blastiq.local', 'role' => 'agent', 'is_active' => true, 'last_seen_at' => now(), 'password' => bcrypt('password')]); \$u->assignRole('agent');"
  ```
- Reload `/team`. The agent should appear with "0 / 5" load and "online" or recent diffForHumans timestamp.
- Log in as that agent in another browser → /team returns 403 (agents don't have `users.view`).

- [ ] **Step 6: Report**

Phase 15 done. Test trajectory:
- Phase 14.4 baseline: 210 tests
- Task 1 (User relationship): 210
- Task 2 (TeamLoad component + 5 tests): 215
- Task 3 (controller/view/route/sidebar): 215
- Task 4 (route/permission test): 216
- Final: **216 tests, all green**

Behavioral changes shipped:
- Managers (anyone with `users.view` permission) see a new "Team" link in the sidebar's Administration section, above "Users."
- Clicking it loads `/team`, which polls every 10 seconds and shows the agent roster as a 4-column table: Name (+ email), Presence (with colored dot), Active load (X/cap with red highlighting at-or-above cap), Last seen ("online" / "5 min ago" / "never").
- Sort: alphabetical by name, ascending. Stable across polls.
- Read-only — no inline buttons, no historical data, no banner.
- Reuses RoundRobinAssigner's constants so dashboard semantics never drift from routing semantics.

Deferred (per spec):
- Sortable headers / status filter / search box → Phase 16+ when team scales past ~20 agents
- Per-row inline actions (jump to conversations, force-status, manual assign) → not in polling tables
- Historical totals (assignments today, response times) → dedicated Phase 16 reports page
- Mini sparklines / charts → Phase 16 analytics
- Saturation banner → empirical signal from rows + existing Unassigned filter is sufficient
- Real-time push (Reverb/Pusher) → polling adequate at current scale
- Per-WhatsApp-instance breakdown → multi-product team feature
- Mobile layout → desktop-first for v1
