# Phase 14.3 — Explicit Agent Presence Toggle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give agents an explicit `available` / `busy` / `away` toggle in the sidebar that controls round-robin routing (only `away` is excluded; `busy` is a social signal).

**Architecture:** Two new columns on `users` (`presence_status` enum + `presence_status_set_at` timestamp), three constants on the User model, one new Livewire component (`PresenceToggle`) mounted only for agents in the existing sidebar user block, one extra WHERE clause in `RoundRobinAssigner::next()`. Phase 14.2's `last_seen_at` heartbeat remains the safety floor.

**Tech Stack:** Laravel 12 · PHP 8.2 (XAMPP local; opcache disabled for artisan) · Livewire 4 · Alpine.js · Tailwind · SQLite local DB · PHPUnit 11.

---

## Spec reference

Full design: `docs/superpowers/specs/2026-05-07-presence-toggle-design.md` (committed `30f92eb`).

## File structure

### Files to create (4)

| File | Responsibility |
|---|---|
| `database/migrations/2026_05_07_140000_add_presence_status_to_users.php` | Add `presence_status` (string, default `available`, indexed) + `presence_status_set_at` (timestamp nullable) |
| `app/Livewire/PresenceToggle.php` | Sidebar dropdown component: read current status, write new status |
| `resources/views/livewire/presence-toggle.blade.php` | Tailwind+Alpine dropdown view (colored dot + 3 options + diffForHumans tooltip) |
| `tests/Feature/Livewire/PresenceToggleTest.php` | 7 component tests |

### Files to modify (4)

| File | Change |
|---|---|
| `app/Models/User.php` | Add `PRESENCE_AVAILABLE`/`PRESENCE_BUSY`/`PRESENCE_AWAY` constants + `PRESENCE_STATUSES` array; add `'presence_status_set_at' => 'datetime'` cast |
| `app/Services/RoundRobinAssigner.php` | Add one `->where('presence_status', '!=', User::PRESENCE_AWAY)` clause to `next()` |
| `tests/Feature/Services/RoundRobinAssignerTest.php` | Append 3 tests covering away/busy/identity-of-busy-and-available |
| `resources/views/layouts/navigation.blade.php` | Add `<livewire:presence-toggle />` mount inside agent-role guard, directly above the user-avatar block at line ~174 |

### Existing infrastructure reused (verified before planning)

- `App\Services\RoundRobinAssigner::next()` (Phase 14.2, committed `88aa69e`) — race-safe transaction wraps the SELECT+UPDATE. Adding one WHERE clause requires no other change.
- `App\Models\User::ROLE_AGENT = 'agent'` constant exists.
- `users.last_seen_at` and `users.last_assigned_at` columns exist (Phase 14.2 migration `da32793`).
- `User` factory uses `forceFill` internally (verified in Phase 14.2 — factory overrides bypass `$fillable`).
- `tests/Feature/Services/RoundRobinAssignerTest.php` exists with `setUp()` that seeds `RolesAndPermissionsSeeder` and a `makeAgent()` helper. New tests will reuse the helper.
- `tests/Feature/Livewire/` directory exists with `RealtimePulseTest.php` as the reference pattern for Livewire component tests.
- `resources/views/layouts/navigation.blade.php` line 174 has a sidebar user-avatar block inside `x-data="{ menuOpen: false }"`. The new component mounts ABOVE this block (separate Alpine scope — no nesting needed because the new component carries its own `x-data`).

### Environment notes (apply to every task)

- Always prefix artisan/phpunit commands with `php -d opcache.enable=0 -d opcache.enable_cli=0` (XAMPP-Windows opcache permission bug).
- Tests use SQLite in-memory via `RefreshDatabase`.
- Branch: `main`, committing direct (user-approved).
- Baseline: 195 tests must remain green at every checkpoint. Final target: 205 tests (3 service + 7 component additions).

### Sidebar vs. topbar

The spec uses the word "topbar" but `resources/views/layouts/navigation.blade.php` is structurally a left sidebar with a user-avatar block at the bottom. The plan refers to this as the "sidebar" to match the actual code. Mount placement (above the user-avatar block) and behavior (always-visible status dot, dropdown on click) are unchanged from the spec.

---

# Tasks

## Task 1: Migration + User model — presence columns and constants

**Files:**
- Create: `database/migrations/2026_05_07_140000_add_presence_status_to_users.php`
- Modify: `app/Models/User.php`

This task ships the schema and the model surface. No service/UI changes yet — the columns exist with sensible defaults (`available`) so Phase 14.2 webhook tests remain green without modification.

- [ ] **Step 1: Generate the migration file**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan make:migration add_presence_status_to_users
```

This generates `database/migrations/2026_05_07_HHMMSS_add_presence_status_to_users.php`. Rename it to `2026_05_07_140000_add_presence_status_to_users.php` so the timestamp matches the spec's deterministic ordering after Phase 14.2's `2026_05_07_120000` migration.

- [ ] **Step 2: Replace the migration body**

Open the renamed file and replace the `up()` and `down()` methods with:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // Explicit user-controlled presence status. Defaults to 'available'
        // so seeded/existing users automatically participate in round-robin
        // rotation. Indexed because RoundRobinAssigner adds a WHERE clause
        // on this column in the hot-path next() query.
        $table->string('presence_status', 16)
            ->default('available')
            ->after('last_assigned_at');
        $table->index('presence_status');

        // When the current presence_status was set. NOT indexed — read only
        // by the agent's own UI for "Set N min ago" tooltip via Carbon
        // diffForHumans. Never used as a filter or order key.
        $table->timestamp('presence_status_set_at')
            ->nullable()
            ->after('presence_status');
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

- [ ] **Step 3: Run migration**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan migrate
```

Expected: `2026_05_07_140000_add_presence_status_to_users ........... DONE`.

- [ ] **Step 4: Verify columns exist**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute="print_r(Schema::getColumnListing('users'));"
```

Expected: output array contains both `presence_status` and `presence_status_set_at`.

- [ ] **Step 5: Add constants and cast to User model**

Open `app/Models/User.php`. Find the existing `ROLE_*` constants (around line 25-30). After the last role constant, add:

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

Then find the `casts()` method (around line 58, added in Phase 14.2). The current shape is:

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_assigned_at' => 'datetime',
    ];
}
```

Add one entry — `'presence_status_set_at' => 'datetime'`:

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_assigned_at' => 'datetime',
        'presence_status_set_at' => 'datetime',
    ];
}
```

`presence_status` is a plain string — no cast.

- [ ] **Step 6: Run full suite to confirm no regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (195 tests, ...)`. The new column has a default of `'available'`, so all Phase 14.2 round-robin tests continue to pass — assigned agents are now implicitly `presence_status='available'` from the column default and the existing query (which doesn't yet filter on this column) returns the same results.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_05_07_140000_add_presence_status_to_users.php app/Models/User.php
git commit -m "feat(presence): add users.presence_status + presence_status_set_at columns

Two columns driving Phase 14.3 explicit presence toggle:

- presence_status: enum string, default 'available', indexed (becomes a
  filter key in RoundRobinAssigner::next() in Task 2).
- presence_status_set_at: nullable timestamp, no index (read only by
  the agent's own UI for diffForHumans tooltip).

Adds three PRESENCE_* constants on User plus a PRESENCE_STATUSES array
for validation. Adds datetime cast on presence_status_set_at so Carbon
methods (diffForHumans, etc.) work in the view layer.

Phase 14.2's 195-test baseline remains green — the column default of
'available' keeps existing factory-created agents in the rotation pool."
```

---

## Task 2: RoundRobinAssigner — exclude `away` agents

**Files:**
- Modify: `app/Services/RoundRobinAssigner.php`
- Modify: `tests/Feature/Services/RoundRobinAssignerTest.php`

- [ ] **Step 1: Append the 3 failing tests**

Open `tests/Feature/Services/RoundRobinAssignerTest.php`. APPEND these three test methods just before the final closing `}` of the class (after the existing `test_stamps_picked_agent_with_current_timestamp_for_next_round` test, before `private function makeAgent(...)`):

```php
    public function test_excludes_agents_with_presence_status_away(): void
    {
        $away = $this->makeAgent(lastSeenAt: now());
        $away->forceFill(['presence_status' => User::PRESENCE_AWAY])->save();

        $assigner = new RoundRobinAssigner();

        $this->assertNull(
            $assigner->next(),
            'Away agents must be excluded from the rotation entirely'
        );
    }

    public function test_includes_agents_with_presence_status_busy(): void
    {
        $busy = $this->makeAgent(lastSeenAt: now());
        $busy->forceFill(['presence_status' => User::PRESENCE_BUSY])->save();

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame(
            $busy->id,
            $picked->id,
            'Busy agents stay in rotation — busy is a social signal, not a routing rule'
        );
    }

    public function test_treats_busy_and_available_identically_in_rotation(): void
    {
        // Two online agents with NULL last_assigned_at, one busy and one available.
        // Both must be picked across two consecutive next() calls (not one preferred
        // over the other). The first call stamps last_assigned_at on whichever it
        // picks; the second call must therefore pick the OTHER agent — proving the
        // first-call pick was driven by the round-robin pointer, NOT by status.
        $available = $this->makeAgent(email: 'a@example.com', lastSeenAt: now());
        // available defaults to 'available' from migration default — no override needed.

        $busy = $this->makeAgent(email: 'b@example.com', lastSeenAt: now());
        $busy->forceFill(['presence_status' => User::PRESENCE_BUSY])->save();

        $assigner = new RoundRobinAssigner();

        $first = $assigner->next();
        $second = $assigner->next();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame(
            $first->id,
            $second->id,
            'Two consecutive next() calls must return different agents — '
            .'busy and available are equivalent in routing'
        );
    }
```

- [ ] **Step 2: Run the new tests, confirm all 3 FAIL**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/RoundRobinAssignerTest.php --filter "test_excludes_agents_with_presence_status_away|test_includes_agents_with_presence_status_busy|test_treats_busy_and_available_identically_in_rotation" --no-coverage
```

Expected: `test_excludes_agents_with_presence_status_away` FAILS — current `next()` returns the away agent because nothing filters on `presence_status` yet. The other two tests likely PASS already (busy agents already get picked, busy/available alternate purely on round-robin) — that's fine. The away test is the one that drives implementation.

- [ ] **Step 3: Add the WHERE clause to RoundRobinAssigner**

Open `app/Services/RoundRobinAssigner.php`. Find the query in `next()` (around line 39-45). Insert one new line — `->where('presence_status', '!=', User::PRESENCE_AWAY)` — between the `is_active` and `last_seen_at` clauses:

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

Also update the docblock at the top of the class — append a sentence explaining the new filter. The current docblock comment around line 15 says:

```
"Available" means: role=agent, is_active=true, last_seen_at within
the last AVAILABILITY_WINDOW_MINUTES.
```

Replace that sentence with:

```
"Available" means: role=agent, is_active=true, presence_status != 'away',
and last_seen_at within the last AVAILABILITY_WINDOW_MINUTES. The
'busy' presence_status remains in rotation — busy is a social signal
broadcast to teammates, not a routing rule.
```

- [ ] **Step 4: Run the full RoundRobinAssignerTest, confirm all 11 PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/RoundRobinAssignerTest.php --no-coverage
```

Expected: `OK (11 tests, ...)` — the original 8 plus the new 3.

- [ ] **Step 5: Run the full suite to confirm no regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (198 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/RoundRobinAssigner.php tests/Feature/Services/RoundRobinAssignerTest.php
git commit -m "feat(presence): RoundRobinAssigner excludes away agents

One WHERE clause added to next(): presence_status != 'away'. Busy
agents stay in rotation alongside available — busy is a social
signal for teammates, not a routing rule. This matches the spec's
explicit decision (Q3) to avoid two-tier deprioritization, which
would create the surprising 'available agent flooded while busy
agent idle' edge case.

Three new tests cover the contract:
- away agents are filtered out
- busy agents are picked when only candidate
- busy/available alternate purely on round-robin pointer (no preference)"
```

---

## Task 3: PresenceToggle Livewire component + view + 7 tests

**Files:**
- Create: `app/Livewire/PresenceToggle.php`
- Create: `resources/views/livewire/presence-toggle.blade.php`
- Create: `tests/Feature/Livewire/PresenceToggleTest.php`

This task ships the component, its view, and seven tests in one TDD cycle. The component is small (≈25 lines) and the view is rendered by `Livewire::test(...)->assertSee(...)` assertions in the test, so writing them together is the cleanest order.

- [ ] **Step 1: Create the failing test file**

Create `tests/Feature/Livewire/PresenceToggleTest.php` with this EXACT content:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\PresenceToggle;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PresenceToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_component_mounts_with_users_current_status(): void
    {
        $agent = $this->makeAgent();
        $agent->forceFill(['presence_status' => User::PRESENCE_BUSY])->save();

        Livewire::actingAs($agent)
            ->test(PresenceToggle::class)
            ->assertSet('status', User::PRESENCE_BUSY);
    }

    public function test_set_status_updates_database(): void
    {
        $agent = $this->makeAgent();

        Livewire::actingAs($agent)
            ->test(PresenceToggle::class)
            ->call('setStatus', User::PRESENCE_AWAY)
            ->assertSet('status', User::PRESENCE_AWAY);

        $agent->refresh();
        $this->assertSame(User::PRESENCE_AWAY, $agent->presence_status);
    }

    public function test_set_status_stamps_set_at_timestamp(): void
    {
        $agent = $this->makeAgent();
        $this->assertNull($agent->presence_status_set_at);

        Livewire::actingAs($agent)
            ->test(PresenceToggle::class)
            ->call('setStatus', User::PRESENCE_BUSY);

        $agent->refresh();
        $this->assertNotNull($agent->presence_status_set_at);
        $this->assertTrue(
            $agent->presence_status_set_at->diffInSeconds(now()) < 5,
            'presence_status_set_at must be stamped to ~now() on status change'
        );
    }

    public function test_set_status_rejects_invalid_string(): void
    {
        $agent = $this->makeAgent();
        // baseline: column default is 'available'
        $this->assertSame(User::PRESENCE_AVAILABLE, $agent->presence_status);

        Livewire::actingAs($agent)
            ->test(PresenceToggle::class)
            ->call('setStatus', 'partying');

        $agent->refresh();
        $this->assertSame(
            User::PRESENCE_AVAILABLE,
            $agent->presence_status,
            'Invalid status string must be silently rejected — DB unchanged'
        );
    }

    public function test_component_renders_correct_status_label(): void
    {
        $agent = $this->makeAgent();

        Livewire::actingAs($agent)
            ->test(PresenceToggle::class)
            ->call('setStatus', User::PRESENCE_AWAY)
            ->assertSee('Away');
    }

    public function test_component_requires_authentication(): void
    {
        $this->expectException(\Throwable::class);

        // Without actingAs(), Auth::user() is null inside the component.
        // Mount itself dereferences Auth::user()->presence_status, so this
        // raises an exception. (Production view-level @if guard prevents
        // this code path — this test asserts the component doesn't silently
        // succeed for guests.)
        Livewire::test(PresenceToggle::class);
    }

    public function test_non_agent_users_can_also_set_status(): void
    {
        // The agent-only mount is enforced in navigation.blade.php (next task),
        // not in the component. The component itself accepts any authenticated
        // user — guards against future "managers can also be in rotation"
        // changes that would mount the component for non-agents.
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $admin->assignRole(User::ROLE_ADMIN);

        Livewire::actingAs($admin)
            ->test(PresenceToggle::class)
            ->call('setStatus', User::PRESENCE_BUSY)
            ->assertSet('status', User::PRESENCE_BUSY);

        $admin->refresh();
        $this->assertSame(User::PRESENCE_BUSY, $admin->presence_status);
    }

    private function makeAgent(): User
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);
        $agent->assignRole(User::ROLE_AGENT);

        return $agent;
    }
}
```

- [ ] **Step 2: Run the test file, confirm all 7 ERROR**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/PresenceToggleTest.php --no-coverage
```

Expected: 7 errors with `Class "App\Livewire\PresenceToggle" not found`.

- [ ] **Step 3: Create the component**

Create `app/Livewire/PresenceToggle.php` with this EXACT content:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Sidebar dropdown letting an agent pick their explicit presence status:
 * available / busy / away. Mounted only for users with role=agent in
 * resources/views/layouts/navigation.blade.php — the component itself
 * does NOT enforce role (so admins/managers calling setStatus do not
 * error). The agent-only mount is a UX choice, not a security boundary.
 *
 * Status changes write two columns in a single UPDATE:
 *   presence_status         — read by RoundRobinAssigner::next()
 *   presence_status_set_at  — read by the view's diffForHumans tooltip
 *
 * Invalid status strings are silently rejected (defense in depth — the
 * rendered view only emits valid values, so this only matters under
 * tampered Livewire requests).
 */
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
            return;
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

- [ ] **Step 4: Create the view**

Create `resources/views/livewire/presence-toggle.blade.php` with this EXACT content:

```blade
<div x-data="{ open: false }" class="relative mb-2">
    {{-- Trigger: colored dot + status label + chevron --}}
    <button type="button"
            @click="open = !open"
            @click.outside="open = false"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 transition text-sm"
            @if($setAt)
                title="Set {{ $setAt->diffForHumans() }}"
            @endif>
        <span class="flex-shrink-0 w-2.5 h-2.5 rounded-full
            @if($status === \App\Models\User::PRESENCE_AVAILABLE) bg-green-500
            @elseif($status === \App\Models\User::PRESENCE_BUSY) bg-orange-500
            @else bg-gray-400 @endif"></span>
        <span class="flex-1 text-left text-gray-700 capitalize">
            @if($status === \App\Models\User::PRESENCE_AVAILABLE) Available
            @elseif($status === \App\Models\User::PRESENCE_BUSY) Busy
            @else Away @endif
        </span>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
             :class="open ? 'rotate-180' : ''">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
        </svg>
    </button>

    {{-- Dropdown menu --}}
    <div x-show="open" x-cloak x-transition
         class="absolute bottom-full left-0 right-0 mb-1 bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden z-10">
        <button type="button"
                wire:click="setStatus('{{ \App\Models\User::PRESENCE_AVAILABLE }}')"
                @click="open = false"
                class="w-full flex items-center gap-2 px-3 py-2 hover:bg-gray-50 text-sm text-left">
            <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
            <span class="text-gray-700">Available</span>
        </button>
        <button type="button"
                wire:click="setStatus('{{ \App\Models\User::PRESENCE_BUSY }}')"
                @click="open = false"
                class="w-full flex items-center gap-2 px-3 py-2 hover:bg-gray-50 text-sm text-left">
            <span class="w-2.5 h-2.5 rounded-full bg-orange-500"></span>
            <span class="text-gray-700">Busy</span>
        </button>
        <button type="button"
                wire:click="setStatus('{{ \App\Models\User::PRESENCE_AWAY }}')"
                @click="open = false"
                class="w-full flex items-center gap-2 px-3 py-2 hover:bg-gray-50 text-sm text-left">
            <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
            <span class="text-gray-700">Away</span>
        </button>
    </div>
</div>
```

- [ ] **Step 5: Run the test file, confirm all 7 PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/PresenceToggleTest.php --no-coverage
```

Expected: `OK (7 tests, ...)`.

- [ ] **Step 6: Run full suite to confirm no regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (205 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/PresenceToggle.php resources/views/livewire/presence-toggle.blade.php tests/Feature/Livewire/PresenceToggleTest.php
git commit -m "feat(presence): PresenceToggle Livewire component + view + 7 tests

Sidebar dropdown component letting agents pick presence_status:
- available (green dot)
- busy (orange dot)
- away (gray dot)

setStatus(string) validates against User::PRESENCE_STATUSES and writes
both presence_status and presence_status_set_at in a single UPDATE.
Invalid strings are silently rejected (defense in depth).

Component itself is role-agnostic — the agent-only mount is enforced
in navigation.blade.php (next task), not here. Admins/managers calling
setStatus succeed without error, guarding against future routing-eligibility
expansions.

Tests cover: mount-reads-current-status, DB write, set_at stamp,
invalid-string rejection, status label rendering, auth requirement,
non-agent acceptance."
```

---

## Task 4: Mount PresenceToggle in sidebar (agent-only)

**Files:**
- Modify: `resources/views/layouts/navigation.blade.php`

This task wires the component into the sidebar's user-avatar block. The mount is gated by `auth()->user()?->role === User::ROLE_AGENT` so admins and managers don't see it.

There is no automated test for this view-level mount — Livewire 4 doesn't make `<livewire:component-name />` mounts in raw Blade easily testable without rendering full layout in a browser test. The component itself is fully tested in Task 3; this task only places it. Manual verification in Step 4.

- [ ] **Step 1: Read the current sidebar user-block context**

Open `resources/views/layouts/navigation.blade.php` and find line 174 (the `{{-- User block at bottom --}}` comment). The current shape from line 173-175 is:

```blade
        </nav>

        {{-- User block at bottom --}}
        <div class="border-t border-gray-200 p-3 flex-shrink-0" x-data="{ menuOpen: false }">
```

- [ ] **Step 2: Insert the agent-gated PresenceToggle mount above the user-block**

Replace lines 173-175 (the `</nav>` line, the comment, and the user-block opening div) with this — adding the mount block ABOVE the existing user-block, inside the same parent flex column but as a sibling to the user-block:

```blade
        </nav>

        {{-- Presence toggle: agent-only --}}
        @auth
            @if(auth()->user()->role === \App\Models\User::ROLE_AGENT)
                <div class="border-t border-gray-200 px-3 pt-3 flex-shrink-0">
                    <livewire:presence-toggle />
                </div>
            @endif
        @endauth

        {{-- User block at bottom --}}
        <div class="border-t border-gray-200 p-3 flex-shrink-0" x-data="{ menuOpen: false }">
```

The mount is wrapped in `@auth` to avoid `auth()->user()->role` dereferencing null on guest pages that include this layout (rare, but defensive). The agent-role check uses `User::ROLE_AGENT` to match the existing codebase convention.

- [ ] **Step 3: Run the full suite to confirm nothing layout-related broke**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (205 tests, ...)`. The view change touches no test fixtures.

- [ ] **Step 4: Manual smoke test (optional but recommended)**

Start the dev server and verify the toggle appears for agents only:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan serve
```

In another terminal, in tinker, ensure there's an agent user:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute="echo App\Models\User::where('role', 'agent')->first()?->email ?? 'no agent';"
```

If output says `no agent`, create one:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute="\$u = App\Models\User::factory()->create(['email' => 'agent@blastiq.local', 'role' => 'agent', 'is_active' => true, 'password' => bcrypt('password')]); \$u->assignRole('agent'); echo \$u->email;"
```

Log in as that agent at `http://localhost:8000/login` (email: `agent@blastiq.local`, password: `password`). Confirm:
- The Available/Busy/Away dropdown appears at the bottom of the sidebar above the user-avatar block.
- Clicking opens the menu, clicking "Busy" closes it and shows orange dot + "Busy" label.
- Hovering the trigger shows "Set N seconds ago" tooltip.

Then log in as an admin user (`admin@blastiq.com` from `DatabaseSeeder`) and confirm the toggle does NOT appear.

If skipping the manual test, proceed to commit.

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/navigation.blade.php
git commit -m "feat(presence): mount PresenceToggle in sidebar for agent role only

Agent-gated <livewire:presence-toggle /> mount placed directly above
the existing user-avatar block at the bottom of the sidebar.

Wrapped in @auth + role check on User::ROLE_AGENT — admins, managers,
and super-admins do not see the toggle. The component itself is
role-agnostic (admins can still call setStatus programmatically), so
this mount is a UX choice, not a security boundary."
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

Expected: `OK (205 tests, ...)`.

- [ ] **Step 3: Inspect the Phase 14.3 commit chain**

```bash
git log --oneline -8
```

Expected to see, top to bottom:
- Task 4: `feat(presence): mount PresenceToggle in sidebar for agent role only`
- Task 3: `feat(presence): PresenceToggle Livewire component + view + 7 tests`
- Task 2: `feat(presence): RoundRobinAssigner excludes away agents`
- Task 1: `feat(presence): add users.presence_status + presence_status_set_at columns`
- The spec: `docs(spec): phase 14.3 explicit agent presence toggle`
- The plan: `docs: add Phase 14.3 explicit agent presence toggle plan`
- Phase 14.2 final: `feat(presence): InboundCallProcessor auto-assigns via round-robin`

- [ ] **Step 4: Push to origin**

```bash
git push origin main
```

Expected: `<Phase 14.2 SHA>..<Task 4 SHA>  main -> main`.

- [ ] **Step 5: Report**

Phase 14.3 done. Test trajectory:
- Phase 14.2 baseline: 195 tests
- Task 1 (migration + casts): 195 (no tests added)
- Task 2 (RoundRobinAssigner WHERE clause + 3 tests): 198
- Task 3 (PresenceToggle component + 7 tests): 205
- Task 4 (mount in sidebar): 205
- Final: **205 tests, all green**

Behavioral changes shipped:
- Agents see a colored-dot dropdown in the sidebar with three options (Available / Busy / Away).
- Clicking a status writes both `presence_status` and `presence_status_set_at` to the user row.
- `RoundRobinAssigner::next()` excludes `away` agents; `busy` agents stay in rotation alongside `available`.
- Phase 14.2's 2-min `last_seen_at` heartbeat continues to act as the safety floor for closed-laptop scenarios.

Deferred to future phases (out of scope, explicit):
- Manager presence dashboard / agent roster view (Phase 15)
- Auto-status transitions: idle → away, browser-close → away, logout → away
- Status history / audit log
- Custom status messages, emoji, "back at 2pm" hints
- Cross-agent status visibility on conversation cards / inbox lists
