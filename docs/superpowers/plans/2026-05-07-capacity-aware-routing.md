# Phase 14.4 — Capacity-Aware Routing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a global per-agent concurrency cap to round-robin auto-assignment so an agent already handling N+ active conversations is filtered out of the routing pool.

**Architecture:** `RoundRobinAssigner::next()` gains a correlated-subquery WHERE clause counting `conversations` rows assigned to each candidate user with `last_inbound_at >= now()-24h`. Cap value lives in the existing `settings` table (key `round_robin_cap_per_agent`, default `5`), editable on `/settings`. When all eligible agents are at cap, `next()` returns null and the conversation stays unassigned.

**Tech Stack:** Laravel 12 · PHP 8.2 (XAMPP local; opcache disabled for artisan) · SQLite local DB · PHPUnit 11.

---

## Spec reference

Full design: `docs/superpowers/specs/2026-05-07-capacity-aware-routing-design.md` (committed `f3811a0`).

## File structure

### Files to modify (5)

| File | Change |
|---|---|
| `app/Services/RoundRobinAssigner.php` | Add `ACTIVE_WINDOW_HOURS = 24` constant; add `whereRaw` correlated-subquery filter; read cap via `Setting::get('round_robin_cap_per_agent', 5)` cast to `(int)`; update class docblock |
| `tests/Feature/Services/RoundRobinAssignerTest.php` | Append 5 new tests covering: at-cap excluded, one-below-cap included, old-inbound (>24h) ignored, settings-driven, cap-zero-disables-all |
| `database/seeders/DatabaseSeeder.php` | Add one entry `'round_robin_cap_per_agent' => '5'` to the `$settings` array |
| `app/Http/Controllers/SettingsController.php` | Add one validation rule `'round_robin_cap_per_agent' => ['nullable','integer','min:0','max:1000']` to the `update()` method |
| `resources/views/settings/index.blade.php` | Add one number-input field group with help text inside the existing "Sending Defaults" or new section |

(No new files. No new tables. No new permissions.)

### Existing infrastructure reused (verified before planning)

- `App\Services\RoundRobinAssigner::next()` (Phase 14.3, last touched commit `32c7c14`) — race-safe `DB::transaction() + lockForUpdate()`. Adding one more WHERE clause requires no other change.
- `App\Models\Setting` static methods: `Setting::get(string $key, $default = null)` returns the value or default; `Setting::set(string $key, $value)` does `updateOrCreate`. Settings table has `(key text unique, value text)` columns.
- `App\Http\Controllers\SettingsController::update()` already loops over validated keys and calls `Setting::set($key, $value)` for each — adding a new key is one line in the validation array.
- `resources/views/settings/index.blade.php` has a "Sending Defaults" panel at lines 21-46 with four existing number/text input fields. The new field can extend this panel or get its own panel — implementer's call when reading the file.
- `database/seeders/DatabaseSeeder.php` has a `$settings` array (around lines 28-36) containing `default_rate_per_minute`, `default_delay_min`, etc. Adding one entry is a one-line append.
- `tests/Feature/Services/RoundRobinAssignerTest.php` has 11 existing tests, a `setUp()` that seeds `RolesAndPermissionsSeeder`, and a `private function makeAgent(...)` helper at the bottom. The new tests reuse `makeAgent()`.

### Environment notes (apply to every task)

- Always prefix artisan/phpunit commands with `php -d opcache.enable=0 -d opcache.enable_cli=0` (XAMPP-Windows opcache permission bug).
- Tests use SQLite in-memory via `RefreshDatabase`.
- Branch: `main`, committing direct (user-approved).
- Baseline: 205 tests must remain green at every checkpoint. Final target: **210 tests** (5 new service tests).

### Test impact on Phase 14.2/14.3 (analyzed — no breakage expected)

- Phase 14.2's 8 round-robin service tests don't create any conversations. The new cap filter evaluates to `(SELECT COUNT(*) ...) = 0 < 5`, true for every agent — filter is a no-op.
- Phase 14.2's 2 webhook integration tests create 1 agent and dispatch 1 inbound message. Default cap=5, count starts at 0 — agent stays eligible.
- Phase 14.3's 7 PresenceToggle tests don't touch RoundRobinAssigner.

If anything goes red post-implementation, the most likely cause is the test database starting from an empty `settings` table — `Setting::get('round_robin_cap_per_agent', 5)` returns the default `5`, which keeps everything passing. No fixture work needed.

---

# Tasks

## Task 1: Service change + 5 tests (TDD)

**Files:**
- Modify: `app/Services/RoundRobinAssigner.php`
- Modify: `tests/Feature/Services/RoundRobinAssignerTest.php`

This is the heart of the phase. TDD cycle: write the 5 tests, watch them fail, add the WHERE clause, watch them pass.

- [ ] **Step 1: Append the 5 failing tests**

Open `tests/Feature/Services/RoundRobinAssignerTest.php`. APPEND these five test methods just before the final closing `}` of the class (after the existing `test_treats_busy_and_available_identically_in_rotation` test, before the `private function makeAgent(...)` helper):

```php
    public function test_excludes_agent_at_cap(): void
    {
        \App\Models\Setting::set('round_robin_cap_per_agent', '3');

        $agent = $this->makeAgent(lastSeenAt: now());

        // 3 active conversations (last_inbound_at within 24h) — at cap
        for ($i = 0; $i < 3; $i++) {
            $this->makeAssignedConversation($agent, lastInboundAt: now());
        }

        $assigner = new RoundRobinAssigner();

        $this->assertNull(
            $assigner->next(),
            'Agent at cap (3 active conversations) must be excluded from rotation'
        );
    }

    public function test_includes_agent_one_below_cap(): void
    {
        \App\Models\Setting::set('round_robin_cap_per_agent', '3');

        $agent = $this->makeAgent(lastSeenAt: now());
        // Only 2 active conversations — below cap
        for ($i = 0; $i < 2; $i++) {
            $this->makeAssignedConversation($agent, lastInboundAt: now());
        }

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame($agent->id, $picked->id);
    }

    public function test_does_not_count_conversations_with_old_inbound(): void
    {
        \App\Models\Setting::set('round_robin_cap_per_agent', '3');

        $agent = $this->makeAgent(lastSeenAt: now());
        // 5 conversations, all with last_inbound_at OUTSIDE the 24h window
        for ($i = 0; $i < 5; $i++) {
            $this->makeAssignedConversation($agent, lastInboundAt: now()->subHours(25));
        }

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull(
            $picked,
            'Conversations with last_inbound_at >24h ago must NOT count toward cap '
            .'(those are dormant — agent is effectively free)'
        );
        $this->assertSame($agent->id, $picked->id);
    }

    public function test_uses_settings_value_for_cap(): void
    {
        \App\Models\Setting::set('round_robin_cap_per_agent', '2');

        $a = $this->makeAgent(email: 'a@example.com', lastSeenAt: now());
        $b = $this->makeAgent(email: 'b@example.com', lastSeenAt: now());

        // Agent A: 2 active conversations — AT cap (excluded)
        $this->makeAssignedConversation($a, lastInboundAt: now());
        $this->makeAssignedConversation($a, lastInboundAt: now());

        // Agent B: 1 active conversation — below cap (eligible)
        $this->makeAssignedConversation($b, lastInboundAt: now());

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame(
            $b->id,
            $picked->id,
            'Cap of 2 from settings must filter A (count=2) and pick B (count=1)'
        );
    }

    public function test_cap_of_zero_returns_null_for_all_online_agents(): void
    {
        // cap=0 means manual-only mode: no agent is ever auto-picked.
        // (count) < 0 is always false, so all agents filtered out.
        \App\Models\Setting::set('round_robin_cap_per_agent', '0');

        $this->makeAgent(email: 'a@example.com', lastSeenAt: now());
        $this->makeAgent(email: 'b@example.com', lastSeenAt: now());

        $assigner = new RoundRobinAssigner();

        $this->assertNull(
            $assigner->next(),
            'Cap=0 disables auto-assignment entirely (manual-only mode)'
        );
    }

    private function makeAssignedConversation(
        \App\Models\User $agent,
        \Illuminate\Support\Carbon $lastInboundAt,
    ): \App\Models\Conversation {
        $owner = \App\Models\User::factory()->create([
            'role' => \App\Models\User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $instance = \App\Models\WhatsAppInstance::factory()->create([
            'user_id' => $owner->id,
        ]);
        $contact = \App\Models\Contact::factory()->create([
            'user_id' => $owner->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);

        return \App\Models\Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
            'last_inbound_at' => $lastInboundAt,
            'last_message_at' => $lastInboundAt,
            'unread_count' => 0,
        ]);
    }
```

The `makeAssignedConversation` helper creates a conversation with the right shape: a separate owner+instance+contact (because `(contact_id, whatsapp_instance_id)` is unique on conversations), assigned to the agent, with the given `last_inbound_at`. If `Conversation::factory()`, `Contact::factory()`, or `WhatsAppInstance::factory()` differ from the call signature shown — read the factory definitions and adjust the array literal. The phone uses `fake()->unique()` to avoid contact-table unique-constraint clashes across loop iterations.

- [ ] **Step 2: Run the 5 new tests, confirm all FAIL**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/RoundRobinAssignerTest.php --filter "test_excludes_agent_at_cap|test_includes_agent_one_below_cap|test_does_not_count_conversations_with_old_inbound|test_uses_settings_value_for_cap|test_cap_of_zero_returns_null_for_all_online_agents" --no-coverage
```

Expected:
- `test_excludes_agent_at_cap` FAILS — current `next()` doesn't filter on conversation count, so it returns the at-cap agent.
- `test_uses_settings_value_for_cap` FAILS — same reason.
- `test_cap_of_zero_returns_null_for_all_online_agents` FAILS — same reason.
- `test_includes_agent_one_below_cap` and `test_does_not_count_conversations_with_old_inbound` likely PASS already (current code returns the agent regardless).

If `makeAssignedConversation` itself errors (factory shape mismatch), fix the helper before proceeding — those errors are not the contract being tested.

- [ ] **Step 3: Add the constant, import, WHERE clause, and updated docblock**

Open `app/Services/RoundRobinAssigner.php`. Two import additions, one constant, one new WHERE clause, one updated docblock comment.

**Edit A — add `Setting` import** at the top of the file alongside the existing `App\Models\User` import:

```php
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
```

(Order alphabetically.)

**Edit B — add the new constant** below the existing `AVAILABILITY_WINDOW_MINUTES = 2`:

```php
public const AVAILABILITY_WINDOW_MINUTES = 2;

/**
 * Window for the per-agent capacity cap. A conversation counts toward
 * an agent's "active load" only if its last_inbound_at is within this
 * many hours of now(). 24 hours matches WhatsApp customer-support
 * cadence — threads quieter than that are considered dormant and the
 * agent is treated as free of them for routing purposes.
 */
public const ACTIVE_WINDOW_HOURS = 24;
```

**Edit C — update `next()`** with the cap read + correlated-subquery WHERE. Replace the current method body:

```php
public function next(): ?User
{
    return DB::transaction(function (): ?User {
        $agent = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('is_active', true)
            ->where('presence_status', '!=', User::PRESENCE_AWAY)
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
```

with:

```php
public function next(): ?User
{
    // Cap is read OUTSIDE the transaction — it's a slow-changing config
    // value, not a routing-time race participant. Cast to (int) defensively:
    // Setting::get returns string from the DB, and a non-numeric value
    // (manual tampering) coerces to 0, which means "manual-only mode" — a
    // safe failure direction (errs toward not-routing rather than over-
    // routing).
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

**Edit D — update the class docblock** to mention the cap. The current docblock around lines 11-26 says:

```
* "Available" means: role=agent, is_active=true, presence_status != 'away',
* and last_seen_at within the last AVAILABILITY_WINDOW_MINUTES. The
* 'busy' presence_status remains in rotation — busy is a social signal
* broadcast to teammates, not a routing rule. The poll-driven heartbeat
* in App\Livewire\RealtimePulse keeps last_seen_at fresh while the agent
* has the app open.
```

Append a new paragraph after that block:

```
*
* Capacity cap (Phase 14.4): an agent whose count of conversations with
* last_inbound_at within ACTIVE_WINDOW_HOURS is at or above the global
* cap (settings.round_robin_cap_per_agent, default 5) is excluded. When
* all eligible agents are at cap, next() returns null and the conversation
* stays unassigned — managers handle saturation via the existing Unassigned
* filter on /conversations.
```

- [ ] **Step 4: Run the 5 new tests, confirm all PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/RoundRobinAssignerTest.php --filter "test_excludes_agent_at_cap|test_includes_agent_one_below_cap|test_does_not_count_conversations_with_old_inbound|test_uses_settings_value_for_cap|test_cap_of_zero_returns_null_for_all_online_agents" --no-coverage
```

Expected: all 5 PASS.

- [ ] **Step 5: Run the full RoundRobinAssignerTest, confirm all 16 PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/RoundRobinAssignerTest.php --no-coverage
```

Expected: `OK (16 tests, ...)` — 11 prior tests + 5 new.

- [ ] **Step 6: Run the full suite, confirm 210 PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (210 tests, ...)` — 205 prior + 5 new. The 2 webhook integration tests (Phase 14.2) continue to pass because they create 1 agent + 1 inbound message, well under cap=5.

If a webhook test fails with "expected agent ID X but got null," check that the test creates the conversation row WITHOUT `last_inbound_at` set in the past — the cap subquery only counts conversations with `last_inbound_at >= now()-24h`, so a freshly-created conversation with `last_inbound_at = null` does NOT count toward the cap. (NULL is not `>=` any timestamp in SQL.) This is the desired semantics.

- [ ] **Step 7: Commit**

```bash
git add app/Services/RoundRobinAssigner.php tests/Feature/Services/RoundRobinAssignerTest.php
git commit -m "feat(routing): cap concurrent conversations per agent in RoundRobinAssigner

next() gains a correlated-subquery WHERE clause that filters out agents
whose count of conversations with last_inbound_at >= now()-24h is at or
above the global cap. Cap value is read once per call from
Setting::get('round_robin_cap_per_agent', 5) and cast to (int).

When all eligible agents are at cap (or no agent online), next() returns
null and the conversation stays unassigned — the existing webhook-
processor guard (if (\$agent !== null)) handles this gracefully and the
conversation surfaces in the Unassigned filter for manager handling.

Five new tests cover:
- agent at cap excluded
- agent one below cap included
- conversations with last_inbound_at >24h ago do NOT count
- settings value drives the cap (not a hardcoded constant)
- cap=0 disables auto-assignment entirely (manual-only mode)

ACTIVE_WINDOW_HOURS=24 lives as a service constant rather than a setting:
the value rarely needs to change and exposing it would require a separate
spec for window-tuning UX. Easy to elevate to a setting if real demand
emerges.

Class docblock updated to document the cap behavior."
```

---

## Task 2: Settings UI + controller validation + seed value

**Files:**
- Modify: `app/Http/Controllers/SettingsController.php`
- Modify: `resources/views/settings/index.blade.php`
- Modify: `database/seeders/DatabaseSeeder.php`

This task makes the cap admin-tunable from `/settings`. No automated test — Settings page changes are exercised manually. The functional contract (cap actually drives routing) is covered by Task 1's `test_uses_settings_value_for_cap`.

- [ ] **Step 1: Add the validation rule**

Open `app/Http/Controllers/SettingsController.php`. Find the `update()` method's `validate()` array (around line 23). Currently it validates 3 keys:

```php
$validated = $request->validate([
    'default_rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:60'],
    'default_delay_min' => ['nullable', 'integer', 'min:1', 'max:30'],
    'default_delay_max' => ['nullable', 'integer', 'min:1', 'max:60'],
]);
```

Add ONE entry. The full updated array:

```php
$validated = $request->validate([
    'default_rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:60'],
    'default_delay_min' => ['nullable', 'integer', 'min:1', 'max:30'],
    'default_delay_max' => ['nullable', 'integer', 'min:1', 'max:60'],
    'round_robin_cap_per_agent' => ['nullable', 'integer', 'min:0', 'max:1000'],
]);
```

`min:0` is intentional — cap=0 is a supported "manual-only mode." `max:1000` is a sanity guard against typos like `5000`.

The existing `foreach` loop below the validate block already handles new keys generically (`Setting::set($key, $value)`), so no other controller change.

- [ ] **Step 2: Add the form field**

Open `resources/views/settings/index.blade.php`. Find the "Sending Defaults" panel (lines 21-46) which contains the existing 4 fields. Add a NEW panel just below it for routing settings. INSERT this block right after the closing `</div>` of the Sending Defaults panel (after line 46) and before the "App Settings" panel (line 48):

```blade
                {{-- Routing & Assignment --}}
                <div class="rounded-xl bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-medium text-gray-900">Routing & Assignment</h3>
                    <p class="mt-1 text-sm text-gray-500">Controls how inbound conversations are auto-assigned to agents.</p>
                    <div class="mt-4 grid grid-cols-2 gap-4">
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
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            <p class="mt-1 text-xs text-gray-500">
                                Maximum active conversations auto-assigned to each agent. "Active" = inbound message
                                within the last 24 hours. Set to 0 to disable auto-assignment entirely (conversations
                                stay unassigned for managers to assign manually). Default 5.
                            </p>
                            @error('round_robin_cap_per_agent')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
```

The styling (`rounded-xl bg-white p-6 shadow-sm`, `focus:border-[#25D366]`) matches the existing panels exactly so the new card visually belongs.

- [ ] **Step 3: Add the seed value**

Open `database/seeders/DatabaseSeeder.php`. Find the `$settings` array (around lines 28-36). Currently:

```php
$settings = [
    'default_rate_per_minute' => '10',
    'default_delay_min' => '2',
    'default_delay_max' => '8',
    'default_country_code' => '234',
    'app_name' => 'BlastIQ',
    'timezone' => 'Africa/Lagos',
];
```

Add one entry. Updated array:

```php
$settings = [
    'default_rate_per_minute' => '10',
    'default_delay_min' => '2',
    'default_delay_max' => '8',
    'default_country_code' => '234',
    'app_name' => 'BlastIQ',
    'timezone' => 'Africa/Lagos',
    'round_robin_cap_per_agent' => '5',
];
```

The string `'5'` matches the storage type (`Setting::value` is `text`). The service casts to `(int)` at read time.

- [ ] **Step 4: Run the full suite to confirm no regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (210 tests, ...)`.

The view change is invisible to tests (no test renders the Settings page). The controller change adds a validation rule for an optional field — existing tests posting to `/settings` without the new key still pass (the `nullable` rule allows it). The seeder change runs only when tests use `--seed` flags, which RoundRobinAssignerTest's `setUp()` does NOT (it seeds RolesAndPermissionsSeeder explicitly, not DatabaseSeeder).

- [ ] **Step 5: Manual smoke test (recommended, optional if confident)**

Spin up the dev server and verify the new field renders + saves:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan serve
```

Visit `http://localhost:8000/settings` as the seeded admin (`admin@blastiq.com` / `password`). Confirm:
- A new "Routing & Assignment" card appears below "Sending Defaults" and above "App Settings."
- The "Round-robin cap per agent" field shows `5` (the default value).
- Change to `3`, click Save. The page reloads with success flash.
- Re-open `/settings`, the field shows `3`.
- In tinker, verify: `App\Models\Setting::get('round_robin_cap_per_agent')` → `'3'`.

If skipping the smoke test, proceed to commit.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/SettingsController.php resources/views/settings/index.blade.php database/seeders/DatabaseSeeder.php
git commit -m "feat(routing): expose round_robin_cap_per_agent in Settings UI

Three matched changes that put the Phase 14.4 cap under admin control:

1. SettingsController.update() validation: added rule
   'round_robin_cap_per_agent' => ['nullable', 'integer', 'min:0',
   'max:1000']. min:0 is intentional — cap=0 is the supported
   manual-only mode. max:1000 is a typo guard.

2. resources/views/settings/index.blade.php: new 'Routing & Assignment'
   panel just below 'Sending Defaults' with one number-input field and
   help text explaining the 24h activity window + the cap=0 escape hatch.

3. DatabaseSeeder: 'round_robin_cap_per_agent' => '5' added to
   \$settings array so fresh installs ship with a sensible default in
   the table. Existing deploys keep working via the
   Setting::get(..., 5) service-side default."
```

---

## Task 3: Final verification + push

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

Expected: `OK (210 tests, ...)`.

- [ ] **Step 3: Inspect the Phase 14.4 commit chain**

```bash
git log --oneline -5
```

Expected to see, top to bottom:
- Task 2: `feat(routing): expose round_robin_cap_per_agent in Settings UI`
- Task 1: `feat(routing): cap concurrent conversations per agent in RoundRobinAssigner`
- Plan: `docs: add Phase 14.4 capacity-aware routing plan` (this file)
- Spec: `docs(spec): phase 14.4 capacity-aware routing`

- [ ] **Step 4: Push to origin**

```bash
git push origin main
```

Expected: `<prior SHA>..<Task 2 SHA>  main -> main`.

- [ ] **Step 5: Report**

Phase 14.4 done. Test trajectory:
- Phase 14.3 baseline: 205 tests
- Task 1 (service + 5 tests): 210
- Task 2 (Settings UI/controller/seeder): 210 (no test changes — view + config)
- Final: **210 tests, all green**

Behavioral changes shipped:
- Inbound webhooks no longer auto-assign to agents who already have ≥ cap active conversations.
- Default cap is 5, configurable via `/settings` → "Routing & Assignment" → "Round-robin cap per agent."
- Cap=0 is a supported manual-only mode for admins who want to disable auto-assignment without touching code.
- "Active" = `last_inbound_at >= now()-24h`. Conversations dormant longer than 24h don't count toward an agent's load.
- When all eligible agents are at cap, conversations land unassigned and surface in the existing `/conversations?filter=unassigned` filter.

Deferred (per spec):
- Per-agent cap override (Phase 14.5+ if requested)
- Per-WhatsApp-instance cap (multi-product teams)
- Manager dashboard showing each agent's X/cap load (Phase 15)
- "Capacity 3/5" badge on the agent's PresenceToggle
- Routing-saturation notifications
