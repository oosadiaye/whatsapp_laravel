# Phase 14.1 — Real-time UX Bundle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a global real-time UX layer to BlastIQ — sticky top banner for in-flight inbound calls, in-browser ringtone, and browser notifications for new chat messages — driven by a single new Livewire component polling every 3 seconds.

**Architecture:** One new Livewire component `App\Livewire\RealtimePulse` mounted on `<x-app-layout>` (`@auth`-gated). It polls every 3s via `wire:poll.3s` and returns a unified payload (in-flight calls + unread message counts) scoped by `conversations.view_all` vs `conversations.view_assigned`. The accompanying Blade view renders a sticky top banner; an Alpine factory in `resources/js/app.js` listens for poll updates and drives HTML5 Audio + Browser Notification API side-effects.

**Tech Stack:** Laravel 12 · PHP 8.2 (XAMPP local; opcache disabled for artisan calls) · Livewire 4 (`wire:poll`) · Alpine.js 3 · spatie/laravel-permission · HTML5 Audio · Browser Notification API · PHPUnit 11.

---

## Spec reference

Full design: `docs/superpowers/specs/2026-05-06-realtime-ux-bundle-design.md` (committed `e75c022`).

## File structure

### Files to create (4)

| File | Responsibility |
|---|---|
| `public/audio/incoming-call.mp3` | Bundled CC0-licensed ring loop (~12-20KB) |
| `app/Livewire/RealtimePulse.php` | Polling component returning unified `{inflightCalls, unreadMessages}` payload |
| `resources/views/livewire/realtime-pulse.blade.php` | Banner markup + audio element + Alpine root |
| `tests/Feature/Livewire/RealtimePulseTest.php` | All 8 component tests |

### Files to modify (2)

| File | Change |
|---|---|
| `resources/views/layouts/app.blade.php` | Mount `<livewire:realtime-pulse />` inside `@auth` block |
| `resources/js/app.js` | Add `window.realtimePulse` Alpine factory with audio-unlock + notification handlers |

### Existing infrastructure reused (verified before planning)

- `App\Livewire\InFlightCall` (Voice Phase A, `app/Livewire/InFlightCall.php`) — same `wire:poll.3s` pattern; this plan elevates it to layout level
- `App\Models\CallLog::STATUSES_IN_FLIGHT` constant = `['initiated', 'ringing', 'connected']`
- `App\Models\CallLog::DIRECTION_INBOUND` and `DIRECTION_OUTBOUND` constants
- `App\Models\Conversation::unread_count` integer column
- spatie/laravel-permission `$user->can('conversations.view_all')` + `view_assigned`
- Alpine 3 already loaded via `resources/js/app.js` (idempotent init guard from `102b99c`)
- Livewire 4 `Livewire::test()` test helper for component payload assertions

### Environment notes (apply to every task)

- Always prefix artisan commands with `php -d opcache.enable=0 -d opcache.enable_cli=0` (XAMPP-Windows opcache permission bug)
- Tests use SQLite in-memory via `RefreshDatabase` trait
- Branch: `main`, committing direct (user-approved)
- Baseline: 174 tests must remain green after each task

---

# Tasks

## Task 1: Bundle the ringtone audio asset

**Files:**
- Create: `public/audio/incoming-call.mp3`

This task has no PHPUnit test — it's a binary asset. Verification is "the file exists, is reasonable size, plays in a browser." Audio sourcing is described inline; if the engineer can't source the file in the time-budget, they should commit a placeholder and document the swap as a follow-up TODO in the commit message.

- [ ] **Step 1: Create the public/audio/ directory if missing**

```bash
mkdir -p public/audio
```

- [ ] **Step 2: Source a CC0-licensed phone ring sample**

Recommended source: <https://freesound.org> — search for "phone ring" with `License: Creative Commons 0`. Examples that work well:
- "Old Phone Ringing" by Erokia (CC0) — short loop, ~3 sec
- "Telephone Ring 01" by Erkanozan (CC0) — clean ring tone

Pick one ≤ 50KB MP3, 4-second-ish loop, ringback-style (not a long melody). Save it to `public/audio/incoming-call.mp3`.

If the engineer cannot access Freesound at implementation time, they may temporarily commit a 1-second silent MP3 generated locally:

```bash
# Optional fallback — create a 1-second silent placeholder if Freesound is unreachable
ffmpeg -f lavfi -i anullsrc=r=44100:cl=mono -t 1 -q:a 9 -acodec libmp3lame public/audio/incoming-call.mp3
```

— and add `# TODO: replace placeholder with CC0 ring sample` to the commit message.

- [ ] **Step 3: Verify the file**

```bash
ls -lh public/audio/incoming-call.mp3
file public/audio/incoming-call.mp3
```

Expected:
- File size between 5KB and 50KB
- `file` reports `Audio file with ID3 ...` or `MPEG ADTS, layer III ...`

- [ ] **Step 4: Commit**

```bash
git add public/audio/incoming-call.mp3
git commit -m "feat(audio): bundle CC0 ringtone for incoming-call banner

Source: <freesound.org URL or 'placeholder' if silent>
Loops ~4s, served as a static asset (not Vite-bundled).
Used by App\\Livewire\\RealtimePulse via the layout's audio element."
```

---

## Task 2: RealtimePulse skeleton + empty-state test

**Files:**
- Create: `app/Livewire/RealtimePulse.php`
- Create: `resources/views/livewire/realtime-pulse.blade.php`
- Create: `tests/Feature/Livewire/RealtimePulseTest.php`

- [ ] **Step 1: Write the failing test (empty state for unauthenticated user)**

Create `tests/Feature/Livewire/RealtimePulseTest.php` with this content:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\RealtimePulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RealtimePulseTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_payload_for_unauthenticated_user(): void
    {
        Livewire::test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0)
            ->assertViewHas('unreadMessages', 0);
    }
}
```

- [ ] **Step 2: Run test, confirm it FAILS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/RealtimePulseTest.php --no-coverage
```

Expected: FAIL with `Class "App\Livewire\RealtimePulse" not found` (or similar).

- [ ] **Step 3: Create the empty-state view**

Create `resources/views/livewire/realtime-pulse.blade.php`:

```blade
<div wire:poll.3s>
    {{-- RealtimePulse: real-time UX layer for inbound calls + chat notifications.
         Mounted on the layout via @auth in app.blade.php. The Alpine factory
         (window.realtimePulse) lives in resources/js/app.js and consumes the
         data attributes set below. --}}

    {{-- Banner stack: up to 3 in-flight inbound calls, sticky top of viewport --}}
    @forelse($inflightCalls as $call)
        <div class="sticky top-0 z-40 bg-emerald-600 text-white px-4 py-3 shadow-md flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-xl animate-pulse" aria-hidden="true">📞</span>
                <div>
                    <div class="font-semibold">
                        Incoming call from {{ $call['contact_name'] ?? 'Unknown' }}
                    </div>
                    <div class="text-xs text-emerald-100 font-mono">
                        {{ $call['phone'] }} · ringing on {{ $call['instance_name'] }}
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('conversations.show', $call['conversation_id']) }}"
                   class="bg-white text-emerald-700 px-3 py-1.5 rounded-md text-sm font-medium hover:bg-emerald-50">
                    Open conversation →
                </a>
            </div>
        </div>
    @empty
        {{-- nothing ringing right now --}}
    @endforelse

    {{-- Hidden audio element, played from JS on incoming-call event --}}
    <audio id="bq-ringtone" preload="auto" loop>
        <source src="{{ asset('audio/incoming-call.mp3') }}" type="audio/mpeg">
    </audio>

    {{-- Data carrier for the Alpine factory in resources/js/app.js.
         The factory reads these attributes after each poll to detect
         changes (new calls, message-count delta) and dispatch side-effects. --}}
    <span id="bq-realtime-data"
          data-calls="{{ json_encode($inflightCalls) }}"
          data-unread="{{ $unreadMessages }}"
          aria-hidden="true"></span>
</div>
```

- [ ] **Step 4: Create the component class**

Create `app/Livewire/RealtimePulse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Real-time UX surface mounted on the layout. Polls every 3 seconds and
 * returns a unified payload of:
 *   - in-flight inbound calls visible to the current user (banner data)
 *   - unread message count across visible conversations (notification trigger)
 *
 * Permission scoping mirrors the inbox:
 *   - conversations.view_all → entire account
 *   - conversations.view_assigned → assigned to me + unassigned pool
 *
 * Anonymous users get an empty payload (no error, no banner) — the
 * @auth gate in app.blade.php means this is mostly belt-and-suspenders,
 * but the test exercises it explicitly for clarity.
 */
class RealtimePulse extends Component
{
    public function render()
    {
        $user = Auth::user();

        if ($user === null) {
            return view('livewire.realtime-pulse', [
                'inflightCalls' => [],
                'unreadMessages' => 0,
            ]);
        }

        return view('livewire.realtime-pulse', [
            'inflightCalls' => [],
            'unreadMessages' => 0,
        ]);
    }
}
```

- [ ] **Step 5: Run the test, confirm PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/RealtimePulseTest.php --no-coverage
```

Expected: `OK (1 test, 2 assertions)`.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/RealtimePulse.php resources/views/livewire/realtime-pulse.blade.php tests/Feature/Livewire/RealtimePulseTest.php
git commit -m "feat(realtime): RealtimePulse Livewire component skeleton + empty-state test"
```

---

## Task 3: Inflight call payload — admin (view_all) scoping

**Files:**
- Modify: `app/Livewire/RealtimePulse.php`
- Modify: `tests/Feature/Livewire/RealtimePulseTest.php`

- [ ] **Step 1: Append failing tests for view_all branch**

In `tests/Feature/Livewire/RealtimePulseTest.php`, replace the entire class body with:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\RealtimePulse;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RealtimePulseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_returns_empty_payload_for_unauthenticated_user(): void
    {
        Livewire::test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0)
            ->assertViewHas('unreadMessages', 0);
    }

    public function test_admin_sees_inflight_inbound_call_for_their_account(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
            'display_name' => 'Sales Line',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
            'from_phone' => '+2348012345678',
        ]);

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', function ($calls) use ($conv) {
                return count($calls) === 1
                    && $calls[0]['conversation_id'] === $conv->id
                    && $calls[0]['phone'] === '+2348012345678'
                    && $calls[0]['instance_name'] === 'Sales Line'
                    && $calls[0]['status'] === 'ringing';
            });
    }

    public function test_admin_does_not_see_calls_from_other_accounts(): void
    {
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $instanceB = WhatsAppInstance::factory()->create([
            'user_id' => $userB->id,
            'status' => 'CONNECTED',
        ]);
        $convB = Conversation::factory()->create([
            'user_id' => $userB->id,
            'whatsapp_instance_id' => $instanceB->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $convB->id,
            'contact_id' => $convB->contact_id,
            'whatsapp_instance_id' => $instanceB->id,
            'direction' => 'inbound',
            'status' => 'ringing',
        ]);

        Livewire::actingAs($userA)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }

    private function makeUser(string $role, ?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? "{$role}-".uniqid().'@example.com',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
```

- [ ] **Step 2: Run, confirm 2 new tests FAIL**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/RealtimePulseTest.php --no-coverage
```

Expected: 1 PASS (empty state, unchanged), 2 FAILS (the new tests assert calls in payload, but render() still returns empty).

- [ ] **Step 3: Implement the view_all branch in `RealtimePulse::render()`**

In `app/Livewire/RealtimePulse.php`, replace the `render()` method body with:

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
            ->whereIn('status', CallLog::STATUSES_IN_FLIGHT)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->with(['contact', 'whatsappInstance']);

        if ($user->can('conversations.view_all')) {
            // Admin / manager / super_admin: every call on a conversation
            // owned by this user (account scope).
            $callQuery->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id));
        } else {
            // Agent (view_assigned only): assigned to me OR unassigned pool.
            $callQuery->whereHas('conversation', fn ($q) =>
                $q->where(fn ($qq) =>
                    $qq->where('assigned_to_user_id', $user->id)
                       ->orWhereNull('assigned_to_user_id')
                )
            );
        }

        $inflightCalls = $callQuery
            ->latest()
            ->limit(3)
            ->get()
            ->map(fn ($call) => [
                'id' => $call->id,
                'conversation_id' => $call->conversation_id,
                'contact_name' => $call->contact->name ?? null,
                'phone' => $call->from_phone,
                'instance_name' => $call->whatsappInstance->display_name
                    ?? $call->whatsappInstance->instance_name,
                'status' => $call->status,
                'started_at' => $call->started_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return view('livewire.realtime-pulse', [
            'inflightCalls' => $inflightCalls,
            'unreadMessages' => 0,  // wired up in Task 6
        ]);
    }
```

Add the imports at the top of the file (above `use Livewire\Component;`):

```php
use App\Models\CallLog;
```

- [ ] **Step 4: Run, confirm all 3 tests PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/RealtimePulseTest.php --no-coverage
```

Expected: `OK (3 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/RealtimePulse.php tests/Feature/Livewire/RealtimePulseTest.php
git commit -m "feat(realtime): RealtimePulse view_all branch — admin sees account inbound calls"
```

---

## Task 4: Inflight call payload — agent (view_assigned) scoping

**Files:**
- Modify: `tests/Feature/Livewire/RealtimePulseTest.php`

The view_assigned branch is already implemented in Task 3's `render()`. Task 4 adds the test coverage that proves it works correctly.

- [ ] **Step 1: Append agent-scoping tests**

In `tests/Feature/Livewire/RealtimePulseTest.php`, APPEND these methods just before the `private function makeUser` method (i.e., as new test methods inside the class):

```php
    public function test_agent_sees_inflight_call_on_assigned_conversation(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
        ]);

        Livewire::actingAs($agent)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 1);
    }

    public function test_agent_sees_inflight_call_on_unassigned_conversation(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => null,  // unassigned pool
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
        ]);

        Livewire::actingAs($agent)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 1);
    }

    public function test_agent_does_not_see_call_assigned_to_someone_else(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $otherAgent = $this->makeUser('agent', 'other@example.com');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $otherAgent->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
        ]);

        Livewire::actingAs($agent)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }
```

- [ ] **Step 2: Run, confirm all 6 tests PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/RealtimePulseTest.php --no-coverage
```

Expected: `OK (6 tests, ...)`. (3 new tests verify the view_assigned branch from Task 3's `render()` works correctly — no controller change needed.)

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Livewire/RealtimePulseTest.php
git commit -m "test(realtime): cover view_assigned branch — agents see assigned + unassigned calls only"
```

---

## Task 5: Filtering — terminal status, recency, outbound exclusion

**Files:**
- Modify: `tests/Feature/Livewire/RealtimePulseTest.php`

Filters are already implemented in Task 3's `render()`. Task 5 proves they work.

- [ ] **Step 1: Append filter tests**

In `tests/Feature/Livewire/RealtimePulseTest.php`, append before `private function makeUser`:

```php
    public function test_excludes_calls_with_terminal_status(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        // Create one CallLog per terminal status — none should appear.
        foreach (['ended', 'missed', 'declined', 'failed'] as $terminal) {
            CallLog::factory()->create([
                'conversation_id' => $conv->id,
                'contact_id' => $conv->contact_id,
                'whatsapp_instance_id' => $instance->id,
                'direction' => 'inbound',
                'status' => $terminal,
            ]);
        }

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }

    public function test_excludes_calls_older_than_30_minutes(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
            'created_at' => now()->subMinutes(31),
        ]);

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }

    public function test_excludes_outbound_calls(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'outbound',
            'status' => 'ringing',
            'placed_by_user_id' => $admin->id,
        ]);

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }
```

- [ ] **Step 2: Run, confirm all 9 tests PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/RealtimePulseTest.php --no-coverage
```

Expected: `OK (9 tests, ...)`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Livewire/RealtimePulseTest.php
git commit -m "test(realtime): cover terminal-status, 30min-recency, outbound-exclusion filters"
```

---

## Task 6: Unread message count in payload

**Files:**
- Modify: `app/Livewire/RealtimePulse.php`
- Modify: `tests/Feature/Livewire/RealtimePulseTest.php`

- [ ] **Step 1: Append failing test**

In `tests/Feature/Livewire/RealtimePulseTest.php`, append before `private function makeUser`:

```php
    public function test_unread_message_count_sums_visible_conversations(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);

        // Three conversations owned by admin — should sum to 10
        Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'unread_count' => 3,
        ]);
        Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'unread_count' => 5,
        ]);
        Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'unread_count' => 2,
        ]);

        // One conversation owned by ANOTHER admin — must NOT contribute
        $other = $this->makeUser('admin', 'other@example.com');
        $otherInstance = WhatsAppInstance::factory()->create([
            'user_id' => $other->id,
            'status' => 'CONNECTED',
        ]);
        Conversation::factory()->create([
            'user_id' => $other->id,
            'whatsapp_instance_id' => $otherInstance->id,
            'unread_count' => 100,
        ]);

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('unreadMessages', 10);
    }
```

- [ ] **Step 2: Run, confirm test FAILS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/RealtimePulseTest.php --filter unread_message_count --no-coverage
```

Expected: FAIL — `unreadMessages` is still hardcoded to `0` from Task 3.

- [ ] **Step 3: Wire up the unread count in `RealtimePulse::render()`**

In `app/Livewire/RealtimePulse.php`, find the line `'unreadMessages' => 0,  // wired up in Task 6` and replace the entire `render()` method's tail (after the `$inflightCalls = ...->all();` line) with:

```php
        // Unread message count across visible conversations — same scoping
        // rules as the call payload (see comment above on view_all vs view_assigned).
        $messageQuery = Conversation::query();
        if ($user->can('conversations.view_all')) {
            $messageQuery->where('user_id', $user->id);
        } else {
            $messageQuery->where(fn ($q) =>
                $q->where('assigned_to_user_id', $user->id)
                  ->orWhereNull('assigned_to_user_id')
            );
        }
        $unreadMessages = (int) $messageQuery->sum('unread_count');

        return view('livewire.realtime-pulse', [
            'inflightCalls' => $inflightCalls,
            'unreadMessages' => $unreadMessages,
        ]);
    }
```

Add `use App\Models\Conversation;` to the imports at the top of the file.

- [ ] **Step 4: Run, confirm all 10 tests PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/RealtimePulseTest.php --no-coverage
```

Expected: `OK (10 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/RealtimePulse.php tests/Feature/Livewire/RealtimePulseTest.php
git commit -m "feat(realtime): unread message count summed per user's visible conversations"
```

---

## Task 7: Alpine factory + audio + Notification handlers

**Files:**
- Modify: `resources/js/app.js`
- Modify: `resources/views/livewire/realtime-pulse.blade.php`

This task adds the JS-side machinery that consumes the data attributes set by the Livewire view and dispatches audio + notification side effects.

- [ ] **Step 1: Add the Alpine factory to `resources/js/app.js`**

Read the current `resources/js/app.js`. After the `if (typeof window.Alpine === 'undefined') { ... Alpine.start(); }` block, append:

```js

/**
 * RealtimePulse Alpine factory.
 *
 * Mounted on the <div> root of resources/views/livewire/realtime-pulse.blade.php.
 * Consumes data attributes (set by the Blade view after each Livewire poll)
 * to detect new in-flight calls and unread-message deltas, then drives:
 *   - HTML5 Audio playback (with the autoplay-unlock pattern)
 *   - Browser Notification API (with permission handling)
 *
 * State is per-tab — multiple tabs each track their own "what was last seen"
 * counter. (BroadcastChannel-based dedup is a future enhancement.)
 */
window.realtimePulse = () => ({
    seenCallIds: [],
    lastUnread: 0,
    audioUnlocked: false,

    init() {
        // Read initial state from data attributes
        const data = document.getElementById('bq-realtime-data');
        if (data) {
            try {
                const calls = JSON.parse(data.dataset.calls || '[]');
                this.seenCallIds = calls.map(c => c.id);
                this.lastUnread = parseInt(data.dataset.unread || '0', 10);
            } catch (e) {
                // Malformed payload — treat as empty
            }
        }

        // Audio autoplay unlock: latch onto the FIRST user gesture
        const unlock = () => {
            const audio = document.getElementById('bq-ringtone');
            if (!audio) return;
            audio.muted = true;
            audio.play().then(() => {
                audio.pause();
                audio.muted = false;
                audio.currentTime = 0;
                this.audioUnlocked = true;
            }).catch(() => {});
            window.removeEventListener('click', unlock);
            window.removeEventListener('keydown', unlock);
        };
        window.addEventListener('click', unlock, { once: true });
        window.addEventListener('keydown', unlock, { once: true });

        // Notification permission ask, once per device
        if ('Notification' in window
            && Notification.permission === 'default'
            && localStorage.getItem('bq:notification-asked') !== '1') {
            setTimeout(() => {
                Notification.requestPermission().finally(() => {
                    localStorage.setItem('bq:notification-asked', '1');
                });
            }, 2000);
        }

        // After every Livewire DOM update, re-read data attrs and dispatch
        document.addEventListener('livewire:morph.updated', () => this.handleUpdate());

        // Run once on mount in case the initial payload already has a call
        this.handleUpdate();
    },

    handleUpdate() {
        const data = document.getElementById('bq-realtime-data');
        if (!data) return;

        let calls = [];
        try { calls = JSON.parse(data.dataset.calls || '[]'); } catch (e) {}

        const currentIds = calls.map(c => c.id);
        const newIds = currentIds.filter(id => !this.seenCallIds.includes(id));

        // New incoming call → ring + (optional) notification
        if (newIds.length > 0) {
            const audio = document.getElementById('bq-ringtone');
            if (audio && this.audioUnlocked) {
                audio.play().catch(() => {});
            }

            // Also fire a desktop notification for the new call (always,
            // not just when tab unfocused — calls are more important than chat)
            if ('Notification' in window && Notification.permission === 'granted') {
                const newCall = calls.find(c => newIds.includes(c.id));
                if (newCall) {
                    const note = new Notification('Incoming call', {
                        body: `${newCall.contact_name || 'Unknown'} · ${newCall.phone}`,
                        icon: '/favicon.ico',
                        tag: 'bq-call-' + newCall.id,
                        requireInteraction: true,
                    });
                    note.onclick = () => {
                        window.focus();
                        window.location.href = '/conversations/' + newCall.conversation_id;
                    };
                }
            }
        }

        // No active calls → stop audio
        if (currentIds.length === 0) {
            const audio = document.getElementById('bq-ringtone');
            if (audio) {
                audio.pause();
                audio.currentTime = 0;
            }
        }

        this.seenCallIds = currentIds;

        // Unread message delta → notification IF tab unfocused
        const currentUnread = parseInt(data.dataset.unread || '0', 10);
        if (currentUnread > this.lastUnread
            && document.hidden
            && 'Notification' in window
            && Notification.permission === 'granted') {
            const delta = currentUnread - this.lastUnread;
            new Notification('New message', {
                body: `${delta} new message${delta === 1 ? '' : 's'} — total ${currentUnread} unread`,
                icon: '/favicon.ico',
                tag: 'bq-message-pulse',
            });
        }
        this.lastUnread = currentUnread;
    },
});
```

- [ ] **Step 2: Wire the factory to the Blade view**

In `resources/views/livewire/realtime-pulse.blade.php`, change the outermost `<div wire:poll.3s>` to also mount the Alpine factory:

```blade
<div wire:poll.3s x-data="realtimePulse()" x-init="init()">
```

(Replace the existing `<div wire:poll.3s>` opening tag — keep everything else inside unchanged.)

- [ ] **Step 3: Rebuild the Vite assets**

```bash
npm run build
```

Expected output (line 3-5 of the result):
```
✓ XX modules transformed.
public/build/assets/app-XXXXXXXX.js   ~XX KB
✓ built in X.XXs
```

- [ ] **Step 4: Verify PHPUnit suite still green (no JS-related Blade compile errors)**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Livewire/RealtimePulseTest.php --no-coverage
```

Expected: `OK (10 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/app.js resources/views/livewire/realtime-pulse.blade.php
git commit -m "feat(realtime): Alpine factory wires audio playback + browser notifications

- Audio autoplay unlock pattern: silent .play() on first user gesture so
  subsequent rings work without throwing on Chrome's autoplay policy
- Notification permission requested once after 2s delay; declined state
  persisted to localStorage as 'bq:notification-asked'
- Incoming call always fires a notification (calls > chat in priority);
  message-count delta only when document.hidden (don't double-notify
  the user looking at the tab)
- Listens to livewire:morph.updated to re-evaluate after every poll cycle"
```

---

## Task 8: Mount RealtimePulse on the layout

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1: Read app.blade.php to find the right mount point**

```bash
sed -n '15,30p' resources/views/layouts/app.blade.php
```

Look for `<body ...>` and the first `@auth` (or `@if (auth()->check())`) block — if there's no existing `@auth` block immediately after `<body>`, that's where we'll add one.

- [ ] **Step 2: Mount the Livewire component inside an @auth block at the top of the body**

Open `resources/views/layouts/app.blade.php`. Immediately after the opening `<body>` tag (which is around line 17 based on the earlier exploration), add:

```blade
@auth
    <livewire:realtime-pulse />
@endauth
```

If the existing layout already has structural wrappers like `<aside>` or sidebar drawers right after `<body>`, place the `@auth` block BEFORE those — the sticky banner needs to be the first DOM element so its `position: sticky` works as the topmost element.

The exact edit should look like:

```blade
<body class="font-sans antialiased bg-gray-50">
    @auth
        <livewire:realtime-pulse />
    @endauth
    {{-- existing sidebar / topbar / content wrapper continue below --}}
```

- [ ] **Step 3: Clear view cache + verify no template errors**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
```

Expected: `INFO Compiled views cleared successfully.`

- [ ] **Step 4: Run full test suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (184 tests, ...)` — 174 baseline + 10 new from this phase.

If a test outside `tests/Feature/Livewire/RealtimePulseTest.php` fails: the most likely cause is an existing Feature test that asserts on the BODY content of a page rendered through `<x-app-layout>`. The new `<livewire:realtime-pulse />` would inject extra HTML (banner stack + audio element + data carrier) which could break content-substring assertions. Check the failing test's assertion — if it's `assertSee('something specific')` it usually still passes; if it's `assertSeeInOrder([...])` or `assertViewHas('html', ...)` it might trip on the new injected content. Loosen the assertion if needed; don't remove the new mount.

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat(realtime): mount RealtimePulse component on the app layout

Wraps in @auth so anonymous pages (login, forgot-password) don't fire the
3-second poll. Position before sidebar so the sticky-top banner can sit
above the rest of the layout chrome."
```

---

## Task 9: Final verification + push

**Files:** none (verification only)

- [ ] **Step 1: Run full suite + ensure asset build is current**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
npm run build
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (184 tests, ...)` — 174 baseline + 10 new.

- [ ] **Step 2: Verify visible artifacts**

```bash
ls -lh public/audio/incoming-call.mp3
ls -lh public/build/assets/app-*.js | tail -1
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan livewire:list 2>&1 | grep -i realtime
```

Expected:
- Audio file 5KB-50KB
- A fresh `app-XXXXXXXX.js` build artifact
- `realtime-pulse` in the Livewire component list

- [ ] **Step 3: Push**

```bash
git push origin main
```

Expected output: `XXXXXXX..XXXXXXX  main -> main` (~9 commits pushed: one per Task 1-8).

- [ ] **Step 4: Manual smoke test on local dev (optional but recommended)**

Spin up the local server:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 -S 127.0.0.1:8000 -t public server.php
```

In a browser, login as `admin@blastiq.com` / `password`. Open another terminal:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker
```

In tinker:

```php
$user = App\Models\User::where('email','admin@blastiq.com')->first();
$instance = App\Models\WhatsAppInstance::factory()->create(['user_id' => $user->id, 'status' => 'CONNECTED', 'display_name' => 'Test Line']);
$contact = App\Models\Contact::factory()->create(['user_id' => $user->id, 'name' => 'Test Caller']);
$conv = App\Models\Conversation::factory()->create([
    'user_id' => $user->id,
    'contact_id' => $contact->id,
    'whatsapp_instance_id' => $instance->id,
]);
$call = App\Models\CallLog::factory()->create([
    'conversation_id' => $conv->id,
    'contact_id' => $contact->id,
    'whatsapp_instance_id' => $instance->id,
    'direction' => 'inbound',
    'status' => 'ringing',
    'from_phone' => '+2348012345678',
]);
echo "Created in-flight inbound call ID: {$call->id}";
```

Within 3-6 seconds the green banner should appear at the top of any browser page. Click anywhere on the page first to unlock audio; on the next call (`->create([...])` again), audio will play.

To clear it:

```php
$call->update(['status' => 'ended']);
```

Banner disappears within 3-6 seconds; ringtone stops.

---

## Acceptance criteria recap

- [ ] When a customer calls, all eligible agents see the banner within 3-6 seconds
- [ ] Caller phone number always visible; contact name shown when present in DB
- [ ] Banner shows which WhatsApp number the call is ringing on (instance display name)
- [ ] Ringtone plays after first user gesture; loops while ringing; stops on terminal status
- [ ] Browser notification fires for new inbound messages when tab is unfocused
- [ ] Permission prompt appears once after 2s; declined state persists across sessions
- [ ] Banner respects `view_all` vs `view_assigned` permissions (10 tests cover this)
- [ ] Full test suite green, 184 tests (174 baseline + 10 new)
- [ ] All 9 commits pushed to `origin/main`
