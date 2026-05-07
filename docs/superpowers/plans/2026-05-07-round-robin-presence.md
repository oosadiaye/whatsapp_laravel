# Phase 14.2 — Round-robin Auto-Assignment + Agent Presence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auto-assign incoming conversations (calls + messages) to the next available agent via round-robin, backed by an implicit-heartbeat presence model that piggybacks on Phase 14.1's `RealtimePulse` poll.

**Architecture:** Two new columns on `users` (`last_seen_at` heartbeat + `last_assigned_at` round-robin pointer). A new `App\Services\RoundRobinAssigner` service with race-safe `next()` method using `DB::transaction()` + `lockForUpdate()`. The two webhook processors (`InboundMessageProcessor`, `InboundCallProcessor`) inject the assigner and call `next()` on every webhook IF the conversation is currently unassigned (skip-if-assigned for stickiness).

**Tech Stack:** Laravel 12 · PHP 8.2 (XAMPP local; opcache disabled for artisan) · Livewire 4 (existing `RealtimePulse` from Phase 14.1) · SQLite local DB · spatie/laravel-permission · PHPUnit 11.

---

## Spec reference

Full design: `docs/superpowers/specs/2026-05-07-round-robin-presence-design.md` (committed `d403ea2`).

## File structure

### Files to create (3)

| File | Responsibility |
|---|---|
| `database/migrations/2026_05_07_120000_add_presence_columns_to_users.php` | Add `last_seen_at` + `last_assigned_at` indexed columns to users |
| `app/Services/RoundRobinAssigner.php` | Race-safe `next()` method picking the longest-idle online agent |
| `tests/Feature/Services/RoundRobinAssignerTest.php` | 8 unit tests for service contract |

### Files to modify (4)

| File | Change |
|---|---|
| `app/Livewire/RealtimePulse.php` | Add 4-line heartbeat block at top of `render()` with 30s dedup |
| `app/Services/InboundMessageProcessor.php` | Inject `RoundRobinAssigner`; add 5-line auto-assign block in `findOrCreateConversation()` |
| `app/Services/InboundCallProcessor.php` | Same change as above |
| `tests/Feature/Webhooks/InboundMessageProcessingTest.php` | Append 1 integration test |
| `tests/Feature/Webhooks/InboundCallProcessingTest.php` | Append 1 integration test |

(Two test files are technically modified, plus three application files — five files total modified.)

### Existing infrastructure reused (verified before planning)

- `App\Livewire\RealtimePulse` — Phase 14.1 Livewire component polling every 3s on `<x-app-layout>` while user is authenticated. Adding a heartbeat touch at the top of `render()` requires no other change.
- `App\Models\User::ROLE_AGENT` constant = `'agent'`. Defined alongside `ROLE_ADMIN`/`ROLE_MANAGER`/`ROLE_SUPER_ADMIN`.
- `App\Services\InboundMessageProcessor::findOrCreateConversation` (line 143) and `App\Services\InboundCallProcessor::findOrCreateConversation` (line 202) — both private helpers calling `Conversation::firstOrCreate`. Both processors' constructors currently inject `WhatsAppCloudApiService` only.
- `Conversation.assigned_to_user_id` column already exists (nullable). No schema work.
- `User::factory()` exists and accepts overrides for `role`, `is_active`, `last_seen_at`, etc.
- `Database\Seeders\RolesAndPermissionsSeeder` seeds the four roles. Tests using `assignRole('agent')` work today.
- `RefreshDatabase` trait is the standard testing pattern in this codebase.

### Environment notes (apply to every task)

- Always prefix artisan commands with `php -d opcache.enable=0 -d opcache.enable_cli=0` (XAMPP-Windows opcache permission bug)
- Tests use SQLite in-memory via `RefreshDatabase` trait
- Branch: `main`, committing direct (user-approved)
- Baseline: 185 tests must remain green after each task

---

# Tasks

## Task 1: Migration — add `last_seen_at` + `last_assigned_at` to users

**Files:**
- Create: `database/migrations/2026_05_07_120000_add_presence_columns_to_users.php`

This task has no PHPUnit test of its own — schema migrations are exercised by every subsequent test that uses `RefreshDatabase`. Verification is "the columns exist after migrate, the rollback works."

- [ ] **Step 1: Generate the migration file**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan make:migration add_presence_columns_to_users
```

This generates a file like `database/migrations/2026_05_07_HHMMSS_add_presence_columns_to_users.php`. Rename it to `2026_05_07_120000_add_presence_columns_to_users.php` so the timestamp matches the spec.

- [ ] **Step 2: Replace the generated migration body**

Open the new migration file and replace its `up()` and `down()` methods with:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // Implicit-heartbeat presence: touched on every RealtimePulse poll
        // (with 30s dedup). 'available' for round-robin = last_seen_at >=
        // now()->subMinutes(2). NULL means user has never logged in since
        // this column was added — naturally excluded from rotation.
        $table->timestamp('last_seen_at')->nullable()->after('is_active');
        $table->index('last_seen_at');

        // Round-robin pointer: stamped to now() when an agent is assigned
        // a new conversation. Pick query orders by last_assigned_at ASC
        // with NULLS FIRST, so newer agents (NULL) get prioritized; older
        // stamps are deprioritized until they "rotate forward" again.
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

- [ ] **Step 3: Run migration**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan migrate
```

Expected: `2026_05_07_120000_add_presence_columns_to_users ........... DONE`

- [ ] **Step 4: Verify columns exist on users table**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute="print_r(Schema::getColumnListing('users'));"
```

Expected output includes `last_seen_at` and `last_assigned_at` in the printed array.

- [ ] **Step 5: Confirm full suite still green (no regressions from schema change)**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (185 tests, ...)`. The new columns don't change any test expectations because nothing reads or writes them yet.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_07_120000_add_presence_columns_to_users.php
git commit -m "feat(presence): add users.last_seen_at + last_assigned_at columns

Two indexed nullable timestamps that drive Phase 14.2 round-robin
auto-assignment:

- last_seen_at: implicit heartbeat from RealtimePulse poll, used as
  the agent-online check (last_seen_at >= now()-2min)
- last_assigned_at: round-robin pointer, used to ORDER BY ASC NULLS
  FIRST in the next-agent pick query

Migration adds indexes on both — they're filter/order keys in the
hot-path round-robin SELECT."
```

---

## Task 2: `App\Services\RoundRobinAssigner` service + 8 unit tests

**Files:**
- Create: `app/Services/RoundRobinAssigner.php`
- Create: `tests/Feature/Services/RoundRobinAssignerTest.php`

- [ ] **Step 1: Create the failing test file**

Create `tests/Feature/Services/RoundRobinAssignerTest.php` with this EXACT content:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\RoundRobinAssigner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoundRobinAssignerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_returns_null_when_no_agents_exist(): void
    {
        $assigner = new RoundRobinAssigner();

        $this->assertNull($assigner->next());
    }

    public function test_returns_null_when_no_agents_are_online(): void
    {
        $offline = $this->makeAgent(lastSeenAt: now()->subMinutes(5));

        $assigner = new RoundRobinAssigner();

        $this->assertNull($assigner->next());
    }

    public function test_picks_only_user_with_agent_role(): void
    {
        // An online admin (NOT in the pool) and an online agent.
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $admin->assignRole(User::ROLE_ADMIN);

        $agent = $this->makeAgent(lastSeenAt: now());

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame($agent->id, $picked->id);
    }

    public function test_excludes_inactive_agents(): void
    {
        $inactive = $this->makeAgent(
            lastSeenAt: now(),
            isActive: false,
        );

        $assigner = new RoundRobinAssigner();

        $this->assertNull($assigner->next());
    }

    public function test_excludes_agents_offline_more_than_2_minutes(): void
    {
        // Boundary: 121 seconds ago is OFFLINE (window is 2 min = 120s)
        $offline = $this->makeAgent(lastSeenAt: now()->subSeconds(121));
        // 119 seconds ago is ONLINE
        $online = $this->makeAgent(
            email: 'b@example.com',
            lastSeenAt: now()->subSeconds(119),
        );

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame($online->id, $picked->id);
    }

    public function test_picks_agent_with_null_last_assigned_at_first(): void
    {
        // One agent has been assigned recently; another is brand new (NULL)
        $oldStamped = $this->makeAgent(
            lastSeenAt: now(),
            lastAssignedAt: now()->subMinutes(1),
        );
        $brandNew = $this->makeAgent(
            email: 'b@example.com',
            lastSeenAt: now(),
            lastAssignedAt: null,
        );

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame(
            $brandNew->id,
            $picked->id,
            'Agent with NULL last_assigned_at must be picked first (NULLS FIRST ordering)'
        );
    }

    public function test_picks_agent_with_oldest_last_assigned_at_when_none_null(): void
    {
        $stale10 = $this->makeAgent(
            email: 'a@example.com',
            lastSeenAt: now(),
            lastAssignedAt: now()->subMinutes(10),
        );
        $stale1 = $this->makeAgent(
            email: 'b@example.com',
            lastSeenAt: now(),
            lastAssignedAt: now()->subMinutes(1),
        );
        $stale5 = $this->makeAgent(
            email: 'c@example.com',
            lastSeenAt: now(),
            lastAssignedAt: now()->subMinutes(5),
        );

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame(
            $stale10->id,
            $picked->id,
            'Agent with oldest last_assigned_at (10 min ago) must be picked'
        );
    }

    public function test_stamps_picked_agent_with_current_timestamp_for_next_round(): void
    {
        // Two agents, both with NULL last_assigned_at. First call picks one,
        // stamps them; second call should pick the OTHER (because the first
        // is now stamped to "now", later than the still-NULL second).
        $a = $this->makeAgent(email: 'a@example.com', lastSeenAt: now());
        $b = $this->makeAgent(email: 'b@example.com', lastSeenAt: now());

        $assigner = new RoundRobinAssigner();

        $first = $assigner->next();
        $second = $assigner->next();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame(
            $first->id,
            $second->id,
            'Two consecutive next() calls must return different agents — '
            .'the first call stamps last_assigned_at, deprioritizing the picked agent.'
        );

        // And the first agent now has a non-null last_assigned_at
        $first->refresh();
        $this->assertNotNull($first->last_assigned_at);
    }

    private function makeAgent(
        ?string $email = null,
        ?\Illuminate\Support\Carbon $lastSeenAt = null,
        ?\Illuminate\Support\Carbon $lastAssignedAt = null,
        bool $isActive = true,
    ): User {
        $user = User::factory()->create([
            'email' => $email ?? 'agent-'.uniqid().'@example.com',
            'role' => User::ROLE_AGENT,
            'is_active' => $isActive,
            'last_seen_at' => $lastSeenAt,
            'last_assigned_at' => $lastAssignedAt,
        ]);
        $user->assignRole(User::ROLE_AGENT);

        return $user;
    }
}
```

(The `tests/Feature/Services/` directory already exists from earlier phases.)

- [ ] **Step 2: Run tests, confirm all 8 FAIL**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/RoundRobinAssignerTest.php --no-coverage
```

Expected: 8 errors with `Class "App\Services\RoundRobinAssigner" not found`.

- [ ] **Step 3: Create the service**

Create `app/Services/RoundRobinAssigner.php` with this EXACT content:

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

    /**
     * Atomically pick the next available agent and stamp them as the
     * most-recently-assigned. Returns null if no agent is online.
     */
    public function next(): ?User
    {
        return DB::transaction(function (): ?User {
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

- [ ] **Step 4: Run tests, confirm all 8 PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/RoundRobinAssignerTest.php --no-coverage
```

Expected: `OK (8 tests, ...)`.

If a test fails for an unexpected reason (e.g., `last_assigned_at` not in the User model's fillable/cast list), the User model needs the new columns added. The User factory's `create([..., 'last_seen_at' => $x])` would fail silently if the column isn't fillable or cast. If you see this:
- Open `app/Models/User.php`
- Add `'last_seen_at'` and `'last_assigned_at'` to the `protected $fillable` array (or to `$guarded = []` if that's the pattern)
- Add `'last_seen_at' => 'datetime', 'last_assigned_at' => 'datetime'` to the `casts()` method (or the equivalent property)
- Re-run tests

- [ ] **Step 5: Run the full suite to confirm no regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (193 tests, ...)` — 185 baseline + 8 new.

- [ ] **Step 6: Commit**

```bash
git add app/Services/RoundRobinAssigner.php tests/Feature/Services/RoundRobinAssignerTest.php
# If you also had to update User.php for fillable/casts:
# git add app/Models/User.php
git commit -m "feat(presence): RoundRobinAssigner service with 8 unit tests

Picks the longest-idle online agent in a race-safe way:
- DB::transaction() + lockForUpdate() serialize concurrent webhooks
- ORDER BY last_assigned_at IS NULL DESC, ASC implements NULLS-FIRST
  fairness across SQLite/MySQL/PostgreSQL
- Stamps the picked agent's last_assigned_at to now() so the next
  call rotates to a different agent

Tests cover: empty pool, all-offline pool, role filter, is_active
filter, online-window boundary (2 min), NULL-first priority,
stamp-stickiness across consecutive calls."
```

---

## Task 3: Heartbeat in `RealtimePulse::render()`

**Files:**
- Modify: `app/Livewire/RealtimePulse.php`

This task piggybacks the heartbeat onto Phase 14.1's existing 3-second poll. There's no new test for it — the `RealtimePulseTest` suite from Phase 14.1 doesn't currently assert on `last_seen_at`, and adding a test for heartbeat-touch behavior would partially duplicate the coverage that Task 2's service tests provide (which directly exercise the `last_seen_at` query). Manual verification: log in, watch the `users.last_seen_at` column update.

If the implementer wants belt-and-suspenders coverage, they MAY add this single optional test to `tests/Feature/Livewire/RealtimePulseTest.php`:

```php
public function test_render_touches_last_seen_at_when_stale(): void
{
    $admin = $this->makeUser('admin');
    $admin->forceFill(['last_seen_at' => now()->subMinutes(1)])->save();
    $original = $admin->last_seen_at;

    Livewire::actingAs($admin)->test(\App\Livewire\RealtimePulse::class);

    $admin->refresh();
    $this->assertTrue($admin->last_seen_at->gt($original),
        'last_seen_at should be touched when stale (>30s old)');
}
```

But this is OPTIONAL — the service tests provide the contract guarantee.

- [ ] **Step 1: Read the current `render()` method**

Open `app/Livewire/RealtimePulse.php` and find the `render()` method (around line 36). The current shape is:

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

    $callQuery = CallLog::query()
        ->where('direction', CallLog::DIRECTION_INBOUND)
        // ... existing logic ...
```

- [ ] **Step 2: Add the heartbeat block right after the unauthenticated-user early-return**

INSERT this block immediately after the `if ($user === null) { return ... }` close-brace, before the `$callQuery = CallLog::query()...` line:

```php
        // Implicit-heartbeat presence: touch last_seen_at every 30 seconds.
        // The 3-second wire:poll cycle would otherwise produce ~20 writes/min
        // per agent. The 30s dedup window is well below the 2-min availability
        // threshold (RoundRobinAssigner::AVAILABILITY_WINDOW_MINUTES), so
        // freshness is preserved while write load drops by ~90%.
        if ($user->last_seen_at === null
            || $user->last_seen_at->lt(now()->subSeconds(30))) {
            $user->forceFill(['last_seen_at' => now()])->save();
        }
```

So the method starts:

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

    // Implicit-heartbeat presence: touch last_seen_at every 30 seconds.
    // ...
    if ($user->last_seen_at === null
        || $user->last_seen_at->lt(now()->subSeconds(30))) {
        $user->forceFill(['last_seen_at' => now()])->save();
    }

    $callQuery = CallLog::query()
        ->where('direction', CallLog::DIRECTION_INBOUND)
        // ... existing logic, unchanged ...
```

- [ ] **Step 3: Run the full suite to confirm no regression on Phase 14.1's RealtimePulseTest**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/RealtimePulseTest.php --no-coverage
```

Expected: `OK (11 tests, ...)`. None of the existing 11 RealtimePulseTest tests assert on `last_seen_at`, so the heartbeat write is invisible to them — they should all still pass.

- [ ] **Step 4: Run the full suite to confirm overall no regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (193 tests, ...)` — same as after Task 2.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/RealtimePulse.php
git commit -m "feat(presence): RealtimePulse heartbeat touches users.last_seen_at

4-line block at top of render() (after the unauthenticated early-return)
that updates last_seen_at when the recorded value is null OR more than
30s stale. The 30s dedup cuts write rate from ~20/min/user (raw 3s poll
rate) to ~2/min/user, which is well within the 2-min RoundRobin
availability window so detection accuracy is preserved.

The Phase 14.1 RealtimePulseTest suite (11 tests) doesn't assert on
last_seen_at and continues to pass — heartbeat is purely a side effect."
```

---

## Task 4: `InboundMessageProcessor` integration

**Files:**
- Modify: `app/Services/InboundMessageProcessor.php`
- Modify: `tests/Feature/Webhooks/InboundMessageProcessingTest.php`

- [ ] **Step 1: Append the failing integration test**

Read `tests/Feature/Webhooks/InboundMessageProcessingTest.php` first to understand the structure (it has its own setUp and helpers). APPEND this test method just before the final closing `}` of the class:

```php
    public function test_inbound_message_auto_assigns_to_available_agent(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $admin->assignRole(User::ROLE_ADMIN);

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'last_seen_at' => now(),  // online
        ]);
        $agent->assignRole(User::ROLE_AGENT);

        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $processor = $this->app->make(\App\Services\InboundMessageProcessor::class);

        $processor->processMessages($instance, [
            [
                'id' => 'wamid.assign_test',
                'from' => '2348011111111',
                'type' => 'text',
                'text' => ['body' => 'Hello'],
                'timestamp' => '1714500000',
            ],
        ]);

        $conversation = \App\Models\Conversation::first();
        $this->assertNotNull($conversation);
        $this->assertSame(
            $agent->id,
            $conversation->assigned_to_user_id,
            'Inbound message must auto-assign to the only available agent'
        );
    }
```

The test relies on `User::factory()`, `WhatsAppInstance::factory()`, and `RolesAndPermissionsSeeder` — all already used by the existing tests in this file. Make sure `User` is in the imports at the top.

- [ ] **Step 2: Run, confirm test FAILS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/InboundMessageProcessingTest.php --filter test_inbound_message_auto_assigns_to_available_agent --no-coverage
```

Expected: FAIL — assertion `assigned_to_user_id === $agent->id` fails because the existing processor doesn't auto-assign yet (the column stays null).

- [ ] **Step 3: Update `InboundMessageProcessor` constructor + `findOrCreateConversation`**

Open `app/Services/InboundMessageProcessor.php`. Two edits:

**Edit A — add the import** at the top of the file alongside existing `App\Services\*` imports:

```php
use App\Services\RoundRobinAssigner;
```

**Edit B — update the constructor** (currently lines 36-38):

Replace:

```php
public function __construct(
    private readonly WhatsAppCloudApiService $cloudApi,
) {
}
```

With:

```php
public function __construct(
    private readonly WhatsAppCloudApiService $cloudApi,
    private readonly RoundRobinAssigner $roundRobinAssigner,
) {
}
```

**Edit C — update `findOrCreateConversation`** (currently around line 143-149).

The current method is:

```php
private function findOrCreateConversation(WhatsAppInstance $instance, Contact $contact): Conversation
{
    return Conversation::firstOrCreate(
        ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
        ['user_id' => $instance->user_id, 'unread_count' => 0],
    );
}
```

Replace its body with:

```php
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

- [ ] **Step 4: Run the test, confirm PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/InboundMessageProcessingTest.php --no-coverage
```

Expected: ALL InboundMessageProcessingTest tests pass (the new one plus all existing).

- [ ] **Step 5: Run full suite to confirm no regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (194 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/InboundMessageProcessor.php tests/Feature/Webhooks/InboundMessageProcessingTest.php
git commit -m "feat(presence): InboundMessageProcessor auto-assigns via round-robin

Constructor now injects RoundRobinAssigner alongside WhatsAppCloudApiService.
findOrCreateConversation runs the assigner's next() AFTER firstOrCreate
IF the resulting conversation is currently unassigned. The skip-if-assigned
check makes it idempotent under concurrent webhook delivery and preserves
sticky customer-agent relationships.

New test test_inbound_message_auto_assigns_to_available_agent verifies
the full webhook-to-DB path: webhook arrives, conversation created, agent
auto-assigned."
```

---

## Task 5: `InboundCallProcessor` integration

**Files:**
- Modify: `app/Services/InboundCallProcessor.php`
- Modify: `tests/Feature/Webhooks/InboundCallProcessingTest.php`

This task is structurally identical to Task 4, applied to the call-side processor. Same constructor change, same 5-line block in `findOrCreateConversation`, same shape of integration test.

- [ ] **Step 1: Append the failing integration test**

Read `tests/Feature/Webhooks/InboundCallProcessingTest.php` first. APPEND this test method just before the final closing `}`:

```php
    public function test_inbound_call_auto_assigns_to_available_agent(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $admin->assignRole(User::ROLE_ADMIN);

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'last_seen_at' => now(),  // online
        ]);
        $agent->assignRole(User::ROLE_AGENT);

        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $processor = $this->app->make(\App\Services\InboundCallProcessor::class);

        $processor->processCalls($instance, [
            [
                'id' => 'wacid.assign_test',
                'from' => '2348011111111',
                'to' => $instance->business_phone_number,
                'event' => 'connect',
                'timestamp' => '1714500000',
            ],
        ]);

        $conversation = \App\Models\Conversation::first();
        $this->assertNotNull($conversation);
        $this->assertSame(
            $agent->id,
            $conversation->assigned_to_user_id,
            'Inbound call must auto-assign to the only available agent'
        );
    }
```

Make sure `User` is in the imports at the top of the file.

- [ ] **Step 2: Run, confirm test FAILS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/InboundCallProcessingTest.php --filter test_inbound_call_auto_assigns_to_available_agent --no-coverage
```

Expected: FAIL — assertion `assigned_to_user_id === $agent->id` fails because the existing call processor doesn't auto-assign.

- [ ] **Step 3: Update `InboundCallProcessor` constructor + `findOrCreateConversation`**

Three edits in `app/Services/InboundCallProcessor.php`:

**Edit A — add the import** at the top of the file alongside existing `App\Services\*` imports:

```php
use App\Services\RoundRobinAssigner;
```

**Edit B — update the constructor** (currently lines 36-38).

Replace:

```php
public function __construct(
    private readonly WhatsAppCloudApiService $cloudApi,
) {
}
```

With:

```php
public function __construct(
    private readonly WhatsAppCloudApiService $cloudApi,
    private readonly RoundRobinAssigner $roundRobinAssigner,
) {
}
```

**Edit C — update `findOrCreateConversation`** (currently around line 202-208).

The current method is:

```php
private function findOrCreateConversation(WhatsAppInstance $instance, Contact $contact): Conversation
{
    return Conversation::firstOrCreate(
        ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
        ['user_id' => $instance->user_id, 'unread_count' => 0],
    );
}
```

Replace its body with:

```php
private function findOrCreateConversation(WhatsAppInstance $instance, Contact $contact): Conversation
{
    $conversation = Conversation::firstOrCreate(
        ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
        ['user_id' => $instance->user_id, 'unread_count' => 0],
    );

    // Auto-assign to next available agent IF currently unassigned.
    // Sticky-to-existing-assignment is implicit: already-assigned conversations
    // skip this branch entirely. Mirrors InboundMessageProcessor — the same
    // RoundRobinAssigner serves both processors so call+message rotations
    // share the same fairness pointer (last_assigned_at on User).
    if ($conversation->assigned_to_user_id === null) {
        $agent = $this->roundRobinAssigner->next();
        if ($agent !== null) {
            $conversation->update(['assigned_to_user_id' => $agent->id]);
        }
    }

    return $conversation;
}
```

- [ ] **Step 4: Run the test, confirm PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/InboundCallProcessingTest.php --no-coverage
```

Expected: ALL InboundCallProcessingTest tests pass.

- [ ] **Step 5: Run full suite to confirm no regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (195 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/InboundCallProcessor.php tests/Feature/Webhooks/InboundCallProcessingTest.php
git commit -m "feat(presence): InboundCallProcessor auto-assigns via round-robin

Mirrors the InboundMessageProcessor change: constructor injects
RoundRobinAssigner; findOrCreateConversation runs next() after
firstOrCreate IF the resulting conversation is currently unassigned.

The same RoundRobinAssigner instance serves both processors via the
container, so call+message rotations share a single fairness pointer
(users.last_assigned_at). An agent who answered the last call is now
deprioritized for the next message AND the next call until other
agents rotate forward — workload is fair across both channels.

New test test_inbound_call_auto_assigns_to_available_agent verifies
the full webhook-to-DB path."
```

---

## Task 6: Final verification + push

**Files:** none (verification only)

- [ ] **Step 1: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (195 tests, ...)` — 185 baseline + 10 new (8 service unit + 2 webhook integration).

If anything fails outside the 10 new tests, that's a regression. STOP and report which test failed and the failure message.

- [ ] **Step 2: Verify the assignment integration via tinker (optional smoke test)**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute="
\$agent = App\Models\User::create([
    'name' => 'Tinker Agent',
    'email' => 'tinker-agent@example.com',
    'password' => bcrypt('x'),
    'role' => 'agent',
    'is_active' => true,
    'last_seen_at' => now(),
]);
\$agent->assignRole('agent');
\$assigner = app(App\Services\RoundRobinAssigner::class);
\$picked = \$assigner->next();
echo 'Picked agent: '.(\$picked->id ?? 'null').PHP_EOL;
echo 'Last assigned at: '.\$picked->last_assigned_at.PHP_EOL;
"
```

Expected: prints "Picked agent: <id>" with a non-null id and a recent timestamp.

- [ ] **Step 3: Push to origin/main**

```bash
git push origin main
```

Expected: pushes ~5-6 commits (Tasks 1, 2, 3, 4, 5, plus the spec from `d403ea2` if not already pushed).

---

## Acceptance criteria recap

- [ ] Migration adds `users.last_seen_at` + `users.last_assigned_at` indexed columns; rollback works
- [ ] `RealtimePulse::render()` touches `last_seen_at` only when stale (>30s old) — heartbeat is invisible to existing tests
- [ ] `RoundRobinAssigner::next()` returns the correct agent under all 8 unit-test scenarios
- [ ] `InboundMessageProcessor` constructor takes `RoundRobinAssigner`; integration test passes
- [ ] `InboundCallProcessor` constructor takes `RoundRobinAssigner`; integration test passes
- [ ] Already-assigned conversations are NOT reassigned (sticky behavior verified by skip-if-assigned check)
- [ ] Test suite green: 185 baseline + 10 new = **195 tests passing**, no regressions
- [ ] All commits pushed to `origin/main`
