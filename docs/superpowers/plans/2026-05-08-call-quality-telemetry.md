# Phase 19a — Call Quality Telemetry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture per-call audio quality metrics from browser `RTCPeerConnection.getStats()` (both Phase 17 Meta + Phase 18 AT calls), persist a 7-field summary as JSON on `call_logs`, and surface the headline MOS score on the `/calls` history page.

**Architecture:** Browser polls `getStats()` every 5s during call, accumulates samples in JS memory, POSTs aggregated averages on hangup to `/calls/{call}/quality`. Server runs G.107 E-model formula to derive MOS, persists to `quality_metrics` JSON column. `/calls` history renders MOS as a color-coded chip per row.

**Tech Stack:** Laravel 12 · PHP 8.2 (XAMPP local; opcache disabled for artisan) · WebRTC `RTCPeerConnection.getStats()` API · Tailwind/Blade · SQLite local DB · PHPUnit 11.

---

## Spec reference

Full design: `docs/superpowers/specs/2026-05-08-call-quality-telemetry-design.md` (committed `378134f`).

## Scope

**Tight phase**: ~5 new files, ~6 modifications, 10 new tests, baseline 257 → 267.

## File structure

### Files to create (5)

| File | Responsibility |
|---|---|
| `database/migrations/2026_05_08_200000_add_quality_metrics_to_call_logs.php` | Single JSON column |
| `app/Services/CallQualityCalculator.php` | Pure-function G.107 MOS computation |
| `resources/js/call-stats-collector.js` | Shared browser helper: startStatsCollection + aggregate + postQuality |
| `tests/Feature/Services/CallQualityCalculatorTest.php` | 5 MOS math tests |
| `tests/Feature/Http/CallQualityRouteTest.php` | 5 HTTP layer tests |

### Files to modify (6)

| File | Change |
|---|---|
| `app/Models/CallLog.php` | Add `quality_metrics` to `$fillable` and `'quality_metrics' => 'array'` to `casts()` |
| `app/Http/Controllers/CallController.php` | New `quality()` method; inject `CallQualityCalculator` |
| `routes/web.php` | New `Route::post('/calls/{call}/quality', ...)` inside `permission:conversations.reply` group |
| `resources/js/calls.js` (Phase 17) | Import collector + start on peer connected + stop & POST on teardown |
| `resources/js/outbound-call.js` (Phase 18) | Same import + start/stop pattern in both `outgoingCall` and `incomingAtCall` factories |
| `resources/views/calls/index.blade.php` | Add Quality column header + per-row MOS chip with tooltip (between Duration and Instance) |

### Existing infrastructure reused (verified before planning)

- `app/Models/CallLog.php`: existing `$fillable` array (Phase 14.x + 17 + 18 columns) + `casts()` method. Phase 18 added `'cost_estimate_kobo'` and `'sdp_*'` etc. The new `quality_metrics` field follows the same pattern.
- `app/Http/Controllers/CallController.php`: Phase 17 added `claim()`/`answer()`/`decline()`/`hangup()`. Phase 18 added `placeOutbound()`. Phase 19a appends `quality()`.
- `routes/web.php` line 163-166: existing `Route::middleware('permission:conversations.reply')->group(...)` containing `/calls/{call}/claim|answer|decline|hangup`. New `/calls/{call}/quality` goes inside that same group.
- `resources/js/calls.js` (Phase 17 commit `0fbf066`): `incomingCall` Alpine factory has `this.peer` (RTCPeerConnection) — confirmed in the file's acceptCall flow.
- `resources/js/outbound-call.js` (Phase 18 commit `5e388f3`): `outgoingCall` and `incomingAtCall` factories use `this.atClient` (AT JS SDK). **AT SDK peer access is uncertain** — see Task 4's known-limitation note.
- `resources/views/calls/index.blade.php`: existing 6-column table (When · Direction · Contact · Status · Duration · Instance). Phase 18's cost_estimate_kobo display was deferred; Phase 19a inserts the Quality column between Duration and Instance.
- `app/Services/ContactImportService.php` is the test reference for service-layer pure-function patterns.

### Environment notes (apply to every task)

- Always prefix artisan/phpunit commands with `php -d opcache.enable=0 -d opcache.enable_cli=0` (XAMPP-Windows opcache permission bug).
- Tests use SQLite in-memory via `RefreshDatabase`.
- Branch: `main`, committing direct (user-approved).
- Baseline: 257 tests must remain green at every checkpoint. Final target: **267 tests** (+10).

---

# Tasks

## Task 1: Migration + CallLog fillable/casts

**Files:**
- Create: `database/migrations/2026_05_08_200000_add_quality_metrics_to_call_logs.php`
- Modify: `app/Models/CallLog.php`

Tiny unblocker. No tests of own; later tasks exercise the column.

- [ ] **Step 1: Generate the migration**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan make:migration add_quality_metrics_to_call_logs
```

Rename to `2026_05_08_200000_add_quality_metrics_to_call_logs.php` so the timestamp is deterministic and orders after Phase 18's `2026_05_08_180000`.

- [ ] **Step 2: Replace the migration body**

```php
public function up(): void
{
    Schema::table('call_logs', function (Blueprint $table) {
        // 7-field call-quality summary, populated on call-end via the
        // browser POST to /calls/{call}/quality. NULL for pre-Phase-19a
        // rows and for calls where browser teardown happened before the
        // POST landed.
        //
        // Shape: {
        //   "mos": 4.2,                    // 1.0-5.0 G.107 derivation
        //   "avg_jitter_ms": 18,           // float, milliseconds
        //   "avg_packet_loss_pct": 0.3,    // float, 0.0-100.0
        //   "avg_rtt_ms": 145,             // integer, milliseconds
        //   "samples_captured": 18,        // integer; <3 = unreliable
        //   "ice_candidate_type": "host",  // host | srflx | relay | prflx | unknown
        //   "codec": "opus"                // string
        // }
        $table->json('quality_metrics')->nullable()->after('cost_estimate_kobo');
    });
}

public function down(): void
{
    Schema::table('call_logs', function (Blueprint $table) {
        $table->dropColumn('quality_metrics');
    });
}
```

- [ ] **Step 3: Run migration**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan migrate
```

Expected: `add_quality_metrics_to_call_logs ............................. DONE`.

- [ ] **Step 4: Update CallLog model**

Open `app/Models/CallLog.php`. Find `$fillable` array. Add `'quality_metrics'` (place it after `'cost_estimate_kobo'` for ordering consistency with the migration's `after()`):

```php
protected $fillable = [
    // ... existing entries ...
    'cost_estimate_kobo',
    'quality_metrics',
];
```

Find the `casts()` method. Add the JSON cast:

```php
protected function casts(): array
{
    return [
        // ... existing casts ...
        'quality_metrics' => 'array',
    ];
}
```

The `array` cast handles JSON ↔ PHP array round-tripping automatically — `$call->quality_metrics['mos']` reads work, `$call->update(['quality_metrics' => [...]])` writes work.

- [ ] **Step 5: Run full suite to confirm no regression**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (257 tests, ...)`. Migration is additive with NULL default; no existing tests touch the new column.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_08_200000_add_quality_metrics_to_call_logs.php app/Models/CallLog.php
git commit -m "feat(call): add call_logs.quality_metrics JSON column

Single-column schema addition for Phase 19a call quality telemetry.
Stores a 7-field summary populated on call-end by the browser's
getStats() collector helper. NULL for pre-Phase-19a rows and for
calls where the browser teardown occurred before the POST landed.

CallLog model gets the field added to \$fillable and an 'array' cast
in casts() so quality_metrics round-trips between JSON storage and
PHP array reads transparently."
```

---

## Task 2: CallQualityCalculator service + 5 tests (TDD)

**Files:**
- Create: `tests/Feature/Services/CallQualityCalculatorTest.php`
- Create: `app/Services/CallQualityCalculator.php`

Pure-function G.107 MOS computation. Strict TDD.

- [ ] **Step 1: Create the failing test file**

Create `tests/Feature/Services/CallQualityCalculatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\CallQualityCalculator;
use Tests\TestCase;

class CallQualityCalculatorTest extends TestCase
{
    public function test_excellent_call_yields_mos_above_4(): void
    {
        $calculator = new CallQualityCalculator();

        // 0% packet loss, 5ms jitter, 50ms RTT — pristine call
        $mos = $calculator->computeMos(
            packetLossPct: 0.0,
            jitterMs: 5.0,
            rttMs: 50,
        );

        $this->assertGreaterThanOrEqual(4.0, $mos, "Expected MOS ≥ 4.0 for excellent call, got {$mos}");
    }

    public function test_poor_call_yields_mos_below_3(): void
    {
        $calculator = new CallQualityCalculator();

        // 5% packet loss, 100ms jitter, 400ms RTT — degraded
        $mos = $calculator->computeMos(
            packetLossPct: 5.0,
            jitterMs: 100.0,
            rttMs: 400,
        );

        $this->assertLessThan(3.0, $mos, "Expected MOS < 3.0 for poor call, got {$mos}");
    }

    public function test_zero_inputs_yield_high_mos(): void
    {
        $calculator = new CallQualityCalculator();

        // 0/0/0 — theoretical perfect conditions
        $mos = $calculator->computeMos(0.0, 0.0, 0);

        $this->assertGreaterThan(4.0, $mos);
        $this->assertLessThanOrEqual(5.0, $mos);
    }

    public function test_extreme_packet_loss_clamped_to_min_one(): void
    {
        $calculator = new CallQualityCalculator();

        // 100% loss → R-factor goes deeply negative; MOS must clamp to 1.0
        $mos = $calculator->computeMos(100.0, 1000.0, 5000);

        $this->assertSame(1.0, $mos);
    }

    public function test_returns_two_decimal_precision(): void
    {
        $calculator = new CallQualityCalculator();

        $mos = $calculator->computeMos(1.0, 20.0, 100);

        // Verify exactly 2 decimal places by comparing to its rounded self.
        $this->assertSame(round($mos, 2), $mos);
    }
}
```

- [ ] **Step 2: Run, confirm 5 tests ERROR with class-not-found**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/CallQualityCalculatorTest.php --no-coverage
```

Expected: 5 errors with `Class "App\Services\CallQualityCalculator" not found`.

- [ ] **Step 3: Create the calculator**

Create `app/Services/CallQualityCalculator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Computes a Mean Opinion Score (MOS, 1.0-5.0) from raw WebRTC stats
 * using the ITU-T G.107 E-model approximation.
 *
 * Reference: ITU-T G.107 (06/2015) E-model, simplified for VoIP.
 * Calibration constants (2.5, 0.05, 0.024) are the de-facto standard
 * used by Twilio, Vonage, etc. Tuning these against user-reported
 * quality is a one-line constants change with one test update.
 */
class CallQualityCalculator
{
    public function computeMos(
        float $packetLossPct,
        float $jitterMs,
        int $rttMs,
    ): float {
        // R-factor approximation: starts at theoretical max (93.2),
        // subtracts impairments from each metric.
        $r = 93.2
            - $packetLossPct * 2.5
            - $jitterMs * 0.05
            - $rttMs * 0.024;

        // Clamp R to valid range (0-100) before MOS conversion.
        $r = max(0.0, min(100.0, $r));

        // E-model R → MOS conversion (cubic polynomial approximation).
        $mos = 1
            + 0.035 * $r
            + 0.000007 * $r * ($r - 60) * (100 - $r);

        // Defensive clamp; G.107 yields values in (1.0, 4.5) but
        // floating-point edge cases could overshoot.
        return round(max(1.0, min(5.0, $mos)), 2);
    }
}
```

- [ ] **Step 4: Run tests, confirm 5 PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/CallQualityCalculatorTest.php --no-coverage
```

Expected: `OK (5 tests, ...)`.

- [ ] **Step 5: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (262 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/CallQualityCalculator.php tests/Feature/Services/CallQualityCalculatorTest.php
git commit -m "feat(call): CallQualityCalculator service + 5 tests

Pure-function G.107 MOS computation. Single public method
computeMos(packetLossPct, jitterMs, rttMs) returns a clamped
1.0-5.0 score.

Calibration constants (impairment weights 2.5/0.05/0.024) are
industry-standard from the simplified E-model. Tuning against
user-reported quality once production data exists is a one-line
change with one test update.

Tests cover: excellent call (MOS ≥ 4.0), poor call (MOS < 3.0),
zero inputs (high MOS), extreme inputs (clamped to 1.0), 2-decimal
precision rounding."
```

---

## Task 3: CallController::quality + route + 5 HTTP tests (TDD)

**Files:**
- Create: `tests/Feature/Http/CallQualityRouteTest.php`
- Modify: `app/Http/Controllers/CallController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the failing test file**

Create `tests/Feature/Http/CallQualityRouteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallQualityRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_quality_endpoint_persists_payload_with_computed_mos(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeOutboundCall($agent);

        $payload = [
            'avg_jitter_ms' => 18.5,
            'avg_packet_loss_pct' => 0.3,
            'avg_rtt_ms' => 145,
            'samples_captured' => 18,
            'ice_candidate_type' => 'host',
            'codec' => 'opus',
        ];

        $response = $this->actingAs($agent)
            ->postJson(route('calls.quality', $call), $payload);

        $response->assertOk()->assertJsonStructure(['mos']);

        $fresh = $call->fresh();
        $this->assertNotNull($fresh->quality_metrics);
        $this->assertSame(18.5, $fresh->quality_metrics['avg_jitter_ms']);
        $this->assertSame(0.3, $fresh->quality_metrics['avg_packet_loss_pct']);
        $this->assertSame(145, $fresh->quality_metrics['avg_rtt_ms']);
        $this->assertSame(18, $fresh->quality_metrics['samples_captured']);
        $this->assertSame('host', $fresh->quality_metrics['ice_candidate_type']);
        $this->assertSame('opus', $fresh->quality_metrics['codec']);
        $this->assertGreaterThanOrEqual(1.0, $fresh->quality_metrics['mos']);
        $this->assertLessThanOrEqual(5.0, $fresh->quality_metrics['mos']);
    }

    public function test_outbound_owner_passes_inbound_owner_passes_other_user_403(): void
    {
        $owner = $this->makeAgent();
        $stranger = $this->makeAgent();

        // Outbound: owner = placed_by_user_id
        $outbound = $this->makeOutboundCall($owner);

        $valid = [
            'avg_jitter_ms' => 10.0,
            'avg_packet_loss_pct' => 0.0,
            'avg_rtt_ms' => 50,
            'samples_captured' => 10,
            'ice_candidate_type' => 'host',
            'codec' => 'opus',
        ];

        $this->actingAs($owner)
            ->postJson(route('calls.quality', $outbound), $valid)
            ->assertOk();

        // Stranger may not POST — 403
        $this->actingAs($stranger)
            ->postJson(route('calls.quality', $outbound), $valid)
            ->assertForbidden();

        // Inbound: owner = conversation.assigned_to_user_id
        $inbound = $this->makeInboundCall(assignedTo: $owner);

        $this->actingAs($owner)
            ->postJson(route('calls.quality', $inbound), $valid)
            ->assertOk();

        $this->actingAs($stranger)
            ->postJson(route('calls.quality', $inbound), $valid)
            ->assertForbidden();
    }

    public function test_quality_endpoint_rejects_invalid_payload(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeOutboundCall($agent);

        $invalid = [
            'avg_jitter_ms' => -5.0,  // negative — invalid
            'avg_packet_loss_pct' => 0.0,
            'avg_rtt_ms' => 50,
            'samples_captured' => 10,
            'ice_candidate_type' => 'host',
            'codec' => 'opus',
        ];

        $this->actingAs($agent)
            ->postJson(route('calls.quality', $call), $invalid)
            ->assertStatus(422);
    }

    public function test_quality_endpoint_rejects_unknown_ice_candidate_type(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeOutboundCall($agent);

        $invalid = [
            'avg_jitter_ms' => 10.0,
            'avg_packet_loss_pct' => 0.0,
            'avg_rtt_ms' => 50,
            'samples_captured' => 10,
            'ice_candidate_type' => 'made_up_value',  // not in enum
            'codec' => 'opus',
        ];

        $this->actingAs($agent)
            ->postJson(route('calls.quality', $call), $invalid)
            ->assertStatus(422);
    }

    public function test_quality_endpoint_overwrites_previous_post(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeOutboundCall($agent);

        $first = [
            'avg_jitter_ms' => 50.0,
            'avg_packet_loss_pct' => 5.0,
            'avg_rtt_ms' => 300,
            'samples_captured' => 10,
            'ice_candidate_type' => 'host',
            'codec' => 'opus',
        ];

        $this->actingAs($agent)
            ->postJson(route('calls.quality', $call), $first)
            ->assertOk();

        $second = [
            'avg_jitter_ms' => 5.0,
            'avg_packet_loss_pct' => 0.0,
            'avg_rtt_ms' => 50,
            'samples_captured' => 20,
            'ice_candidate_type' => 'host',
            'codec' => 'opus',
        ];

        $this->actingAs($agent)
            ->postJson(route('calls.quality', $call), $second)
            ->assertOk();

        // Second write replaced the first.
        $fresh = $call->fresh();
        $this->assertSame(5.0, $fresh->quality_metrics['avg_jitter_ms']);
        $this->assertSame(20, $fresh->quality_metrics['samples_captured']);
    }

    private function makeAgent(): User
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $agent->assignRole(User::ROLE_AGENT);
        $agent->givePermissionTo('conversations.reply');
        return $agent;
    }

    private function makeOutboundCall(User $owner): CallLog
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $admin->assignRole(User::ROLE_ADMIN);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $contact = Contact::factory()->create([
            'user_id' => $admin->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);
        $conversation = Conversation::create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $owner->id,
            'unread_count' => 0,
        ]);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'outbound',
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => 'sess_'.fake()->unique()->numerify('########'),
            'status' => CallLog::STATUS_ENDED,
            'started_at' => now()->subMinutes(2),
            'ended_at' => now(),
            'placed_by_user_id' => $owner->id,
            'from_phone' => '+2348100000000',
            'to_phone' => $contact->phone,
        ]);
    }

    private function makeInboundCall(User $assignedTo): CallLog
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $admin->assignRole(User::ROLE_ADMIN);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $contact = Contact::factory()->create([
            'user_id' => $admin->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);
        $conversation = Conversation::create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $assignedTo->id,
            'unread_count' => 0,
        ]);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'provider' => CallLog::PROVIDER_META_WHATSAPP,
            'meta_call_id' => 'wacid_'.fake()->unique()->numerify('########'),
            'status' => CallLog::STATUS_ENDED,
            'started_at' => now()->subMinutes(2),
            'ended_at' => now(),
            'from_phone' => $contact->phone,
            'to_phone' => '+2348100000000',
        ]);
    }
}
```

- [ ] **Step 2: Run, confirm 5 tests FAIL with route-not-found**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Http/CallQualityRouteTest.php --no-coverage
```

Expected: 5 errors — `Route [calls.quality] not defined`.

- [ ] **Step 3: Add the `quality` method to CallController**

Open `app/Http/Controllers/CallController.php`. Add the `CallQualityCalculator` import at the top (alongside existing service imports). Then APPEND the new method to the class:

```php
public function quality(
    \Illuminate\Http\Request $request,
    \App\Models\CallLog $call,
    \App\Services\CallQualityCalculator $calculator,
): \Illuminate\Http\JsonResponse {
    // Ownership check: only the agent who placed/answered may post.
    // Outbound: matches placed_by_user_id.
    // Inbound: matches the parent conversation's assigned_to_user_id.
    $userId = auth()->id();
    $owns = $call->placed_by_user_id === $userId
        || $call->conversation?->assigned_to_user_id === $userId;
    if (!$owns) {
        return response()->json(['error' => 'forbidden'], 403);
    }

    $validated = $request->validate([
        'avg_jitter_ms' => ['required', 'numeric', 'min:0', 'max:10000'],
        'avg_packet_loss_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        'avg_rtt_ms' => ['required', 'integer', 'min:0', 'max:60000'],
        'samples_captured' => ['required', 'integer', 'min:0', 'max:1000'],
        'ice_candidate_type' => ['required', 'string', 'in:host,srflx,relay,prflx,unknown'],
        'codec' => ['required', 'string', 'max:32'],
    ]);

    $mos = $calculator->computeMos(
        (float) $validated['avg_packet_loss_pct'],
        (float) $validated['avg_jitter_ms'],
        (int) $validated['avg_rtt_ms'],
    );

    $call->update([
        'quality_metrics' => array_merge($validated, ['mos' => $mos]),
    ]);

    return response()->json(['mos' => $mos]);
}
```

If the file already imports `Request`, `JsonResponse`, `CallLog`, `CallQualityCalculator`, drop the FQN prefixes for cleanliness. Match the existing controller style established by Phase 17 + 18 methods.

- [ ] **Step 4: Register the route**

Open `routes/web.php`. Find the existing `permission:conversations.reply` middleware group containing `claim`/`answer`/`decline`/`hangup` (around lines 163-166). ADD the new route inside that group:

```php
Route::post('/calls/{call}/quality', [\App\Http\Controllers\CallController::class, 'quality'])
    ->name('calls.quality');
```

If `CallController` is already imported at the top of the file, drop the FQN.

- [ ] **Step 5: Run the 5 new tests, confirm PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Http/CallQualityRouteTest.php --no-coverage
```

Expected: `OK (5 tests, ...)`.

- [ ] **Step 6: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (267 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/CallController.php routes/web.php tests/Feature/Http/CallQualityRouteTest.php
git commit -m "feat(call): CallController.quality endpoint + route + 5 tests

POST /calls/{call}/quality endpoint, gated by permission:conversations.reply.
Browser POSTs the 6-field payload (jitter/loss/rtt/samples/ice/codec)
on hangup; server validates ranges + ICE enum, computes MOS via the
G.107 calculator from Task 2, persists merged 7-field result to
call_logs.quality_metrics.

Ownership check accepts both outbound (placed_by_user_id) and inbound
(conversation->assigned_to_user_id) call owners. Strangers get 403
even with conversations.reply permission — the call must be theirs.

Tests cover: payload persistence with computed MOS, outbound vs
inbound ownership matrix, 422 on negative jitter, 422 on out-of-enum
ice_candidate_type, last-write-wins on idempotent POSTs."
```

---

## Task 4: Browser-side `call-stats-collector.js` + wiring into Phase 17 + Phase 18 factories

**Files:**
- Create: `resources/js/call-stats-collector.js`
- Modify: `resources/js/calls.js`
- Modify: `resources/js/outbound-call.js`

NO PHPUnit tests for this task — browser-side WebRTC code. Manual smoke verification on production deploy.

- [ ] **Step 1: Create the collector helper**

Create `resources/js/call-stats-collector.js`:

```js
/**
 * Phase 19a — call quality telemetry collector.
 *
 * Used by both Phase 17 (Meta raw WebRTC, calls.js) and Phase 18 (AT SDK,
 * outbound-call.js). Both factories obtain access to the underlying
 * RTCPeerConnection and pass it here.
 *
 * Lifecycle:
 *   1. startStatsCollection(peer) — call when peer reaches 'connected' state
 *   2. handle returned via { stop }
 *   3. stop() — call on teardown / hangup. Returns aggregated payload or null.
 *   4. postQuality(callId, csrf, aggregate) — POST to /calls/{call_id}/quality
 *
 * Sample cadence: 5 seconds (5000ms). Browser cost is negligible
 * (~1-2ms per getStats call). 6+ samples = robust averages.
 */
export function startStatsCollection(peer) {
    const samples = [];
    let intervalId = null;

    const tick = async () => {
        try {
            const report = await peer.getStats();
            const sample = extractRelevantStats(report);
            if (sample) samples.push(sample);
        } catch (e) {
            // Peer torn down between tick scheduling and getStats invocation.
            // Swallow — losing 1-2 samples is invisible to averages.
        }
    };

    intervalId = setInterval(tick, 5000);

    return {
        stop() {
            if (intervalId) clearInterval(intervalId);
            return aggregate(samples);
        },
    };
}

function extractRelevantStats(report) {
    let inboundRtp, candidatePair, codec;
    report.forEach((stat) => {
        if (stat.type === 'inbound-rtp' && stat.kind === 'audio') {
            inboundRtp = stat;
        }
        if (stat.type === 'candidate-pair' && stat.state === 'succeeded') {
            candidatePair = stat;
        }
        if (stat.type === 'codec' && stat.mimeType?.includes('audio')) {
            codec = stat;
        }
    });

    if (!inboundRtp) return null;

    return {
        jitter_ms: (inboundRtp.jitter ?? 0) * 1000,           // sec → ms
        packets_lost: inboundRtp.packetsLost ?? 0,
        packets_received: inboundRtp.packetsReceived ?? 0,
        rtt_ms: (candidatePair?.currentRoundTripTime ?? 0) * 1000,
        ice_local_id: candidatePair?.localCandidateId,
        codec_mime_type: codec?.mimeType,
    };
}

function aggregate(samples) {
    if (samples.length === 0) return null;

    const avg = (key) =>
        samples.reduce((sum, s) => sum + (s[key] || 0), 0) / samples.length;

    const totalReceived = samples.reduce(
        (sum, s) => sum + (s.packets_received || 0),
        0,
    );
    const totalLost = samples.reduce((sum, s) => sum + (s.packets_lost || 0), 0);
    const totalAttempted = totalReceived + totalLost;

    const last = samples[samples.length - 1];

    return {
        avg_jitter_ms: round2(avg('jitter_ms')),
        avg_packet_loss_pct:
            totalAttempted > 0
                ? round2((totalLost / totalAttempted) * 100)
                : 0,
        avg_rtt_ms: Math.round(avg('rtt_ms')),
        samples_captured: samples.length,
        ice_candidate_type: deriveIceType(last.ice_local_id),
        codec: deriveCodec(last.codec_mime_type),
    };
}

function deriveIceType(localCandidateId) {
    // candidate-pair.localCandidateId references a separate stat in the report.
    // For v1 we accept that ice_candidate_type may be 'unknown' if the browser
    // doesn't surface the type in the candidate-pair sub-record. Most browsers
    // do; Safari may delay.
    if (!localCandidateId) return 'unknown';
    const lower = localCandidateId.toLowerCase();
    if (lower.includes('relay')) return 'relay';
    if (lower.includes('srflx')) return 'srflx';
    if (lower.includes('prflx')) return 'prflx';
    if (lower.includes('host')) return 'host';
    return 'unknown';
}

function deriveCodec(mimeType) {
    // mimeType is "audio/opus" or "audio/PCMU" etc. Strip the prefix.
    if (!mimeType) return 'unknown';
    return mimeType.split('/')[1]?.toLowerCase() || 'unknown';
}

function round2(n) {
    return Math.round(n * 100) / 100;
}

/**
 * Helper to POST aggregated payload to server. Returns the fetch promise.
 * Swallows network failures silently — lost telemetry surfaces as "—" in
 * the history page, which is acceptable degradation.
 */
export async function postQuality(callId, csrfToken, aggregate) {
    if (!aggregate || aggregate.samples_captured < 1) return;
    try {
        await fetch(`/calls/${callId}/quality`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(aggregate),
        });
    } catch (e) {
        console.warn('quality post failed (non-fatal)', e);
    }
}
```

- [ ] **Step 2: Wire into Phase 17's `calls.js`**

Open `resources/js/calls.js`. Find the existing `acceptCall()` method that builds the `RTCPeerConnection`. After the line that sets `this.peer = new RTCPeerConnection(...)` (or equivalent), add:

```js
import { startStatsCollection, postQuality } from './call-stats-collector';
```

(If imports are at the top of the file, this goes there.)

In the existing factory's `acceptCall()` method, AFTER the peer connection is established (typically after `this.peer.setLocalDescription(answer)` or after the POST to `/calls/{id}/answer` returns OK), ADD:

```js
this._statsHandle = startStatsCollection(this.peer);
```

In the existing factory's `teardown()` method (which closes the peer + stops mic tracks), AFTER the existing teardown logic, ADD:

```js
const aggregate = this._statsHandle?.stop();
postQuality(this.callId, this.csrf, aggregate);
this._statsHandle = null;
```

Both Phase 17's `incomingCall` factory have access to `this.peer`. The method signatures are unchanged — only adding 1 import + ~4 lines.

- [ ] **Step 3: Wire into Phase 18's `outbound-call.js`**

Open `resources/js/outbound-call.js`. The file has TWO factories: `outgoingCall` and `incomingAtCall`. Both use `this.atClient` (Africa's Talking JS SDK) which wraps an internal `RTCPeerConnection`.

**KNOWN UNCERTAINTY**: The AT SDK's exact API for accessing the underlying peer is not verified. The plan's recommended approach assumes `this.atClient.peer` or `this.atClient.getPeerConnection()` works. If neither does, document the limitation and skip the AT-side wiring (AT calls will show gray "—" in the history; Meta calls work).

Add the import at the top of the file:

```js
import { startStatsCollection, postQuality } from './call-stats-collector';
```

In `outgoingCall.init()`, AFTER the line that sets up `this.atClient.on('connected', ...)`, modify the connected handler to also start stats:

```js
this.atClient.on('connected', () => {
    this.state = 'connected';
    this.startDurationTimer();
    // Phase 19a: try to start stats collection. AT SDK peer access is
    // version-specific; if undefined, telemetry stays null for AT calls.
    const peer = this.atClient.peer ?? this.atClient.getPeerConnection?.();
    if (peer) {
        this._statsHandle = startStatsCollection(peer);
    }
});
```

In `outgoingCall.teardown(reason)`, AFTER the existing cleanup, ADD:

```js
const aggregate = this._statsHandle?.stop();
postQuality(this.callId, this.csrf, aggregate);
this._statsHandle = null;
```

Apply the same pattern to `incomingAtCall.init()`'s connected handler and `incomingAtCall.teardown(reason)`.

If `this.atClient.peer` is undefined at runtime (verify on first deploy), the `if (peer)` guard means no harm — `_statsHandle` stays null, `.stop()` is never called, no POST is sent. Telemetry naturally degrades to "—".

- [ ] **Step 4: Build assets**

```bash
npm run build
```

Expected: build completes; manifest updated with new `call-stats-collector.js` bundled into the existing entrypoints.

- [ ] **Step 5: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (267 tests, ...)`. JS changes are invisible to PHPUnit.

- [ ] **Step 6: SKIP — manual smoke test deferred to production deploy verification**

Per Phase 17/18 precedent, RTCPeerConnection + AT SDK cannot be exercised in PHPUnit. Live verification happens during deploy. Proceed directly to commit.

- [ ] **Step 7: Commit**

```bash
git add resources/js/call-stats-collector.js resources/js/calls.js resources/js/outbound-call.js
git commit -m "feat(call): browser-side call quality telemetry collector

New shared helper resources/js/call-stats-collector.js with three
exports:
- startStatsCollection(peer): polls peer.getStats() every 5s,
  accumulates samples, returns { stop } handle.
- aggregate(samples): in-memory; produces 6-field summary on stop().
- postQuality(callId, csrf, aggregate): POST to /calls/{id}/quality
  (Phase 19a Task 3 endpoint). Swallows network failures silently.

Wired into both Phase 17 (calls.js, raw RTCPeerConnection via
this.peer) and Phase 18 (outbound-call.js, AT SDK peer accessed via
this.atClient.peer or getPeerConnection() with defensive fallback).
On peer 'connected' event, _statsHandle = startStatsCollection.
On teardown, stop + postQuality, then null-out the handle.

Both AT factories (outgoingCall, incomingAtCall) follow the same
pattern.

KNOWN: AT SDK peer access is version-specific. If the peer accessor
returns undefined at runtime, telemetry naturally degrades to gray
'—' in the history (no POST sent). Meta calls work regardless.

NO PHPUnit test — browser-side. Manual smoke verification deferred
to production deploy checklist."
```

---

## Task 5: `/calls` history page Quality column

**Files:**
- Modify: `resources/views/calls/index.blade.php`

No automated test (Blade view change).

- [ ] **Step 1: Read existing structure**

Open `resources/views/calls/index.blade.php`. The current table has columns: When · Direction · Contact · Status · Duration · Instance · (actions). Phase 19a inserts a new "Quality" column between Duration and Instance.

- [ ] **Step 2: Add the column header**

Find the `<thead>` block (around line 51-60). After the existing Duration `<th>` (around line 57), INSERT:

```blade
<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Quality') }}</th>
```

- [ ] **Step 3: Add the per-row cell**

Find the `<tbody>` block's per-row `<tr>`. After the Duration `<td>` (around line 98-100), INSERT:

```blade
<td class="px-6 py-3 text-sm">
    @if($call->quality_metrics)
        @php
            $mos = $call->quality_metrics['mos'] ?? null;
            $colorClasses = match (true) {
                $mos === null => 'bg-gray-100 text-gray-600',
                $mos >= 4.0 => 'bg-emerald-100 text-emerald-800',
                $mos >= 3.0 => 'bg-amber-100 text-amber-800',
                default => 'bg-red-100 text-red-800',
            };
            $label = match (true) {
                $mos === null => '—',
                $mos >= 4.0 => 'Excellent',
                $mos >= 3.0 => 'Fair',
                default => 'Poor',
            };
            $tooltip = sprintf(
                'MOS %s · jitter %sms · loss %s%% · RTT %sms · ICE %s',
                $mos ?? '?',
                $call->quality_metrics['avg_jitter_ms'] ?? '?',
                $call->quality_metrics['avg_packet_loss_pct'] ?? '?',
                $call->quality_metrics['avg_rtt_ms'] ?? '?',
                $call->quality_metrics['ice_candidate_type'] ?? '?',
            );
        @endphp
        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $colorClasses }}"
              title="{{ $tooltip }}">
            {{ $label }} {{ $mos !== null ? number_format($mos, 1) : '' }}
        </span>
    @else
        <span class="text-xs text-gray-400">—</span>
    @endif
</td>
```

- [ ] **Step 4: Clear view cache and run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (267 tests, ...)`. View change invisible to existing tests.

- [ ] **Step 5: Commit**

```bash
git add resources/views/calls/index.blade.php
git commit -m "feat(call): /calls history page Quality column with MOS chip

Adds a Quality column between Duration and Instance on the existing
call-history table. Renders the MOS score from call_logs.quality_metrics
as a color-coded chip:
  - ≥4.0 emerald (Excellent)
  - 3.0-3.9 amber (Fair)
  - <3.0 red (Poor)
  - null gray (—)

Tooltip shows MOS · jitter · loss · RTT · ICE for at-a-glance
diagnostic context. Pre-Phase-19a rows show gray '—' (no telemetry
collected).

No automated test — Blade view; visual regression. Manual verification:
load /calls after at least one Phase 19a-era call has been made and
confirm chip renders with non-gray color."
```

---

## Task 6: Final verification + push

**Files:** none

- [ ] **Step 1: Confirm clean working tree**

```bash
git status
```

Expected: `nothing to commit, working tree clean` (only `.claude/` untracked).

- [ ] **Step 2: Run full suite one last time**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (267 tests, ...)`.

- [ ] **Step 3: Inspect Phase 19a commit chain**

```bash
git log --oneline -8
```

Expected, top to bottom:
- Task 5: `feat(call): /calls history page Quality column with MOS chip`
- Task 4: `feat(call): browser-side call quality telemetry collector`
- Task 3: `feat(call): CallController.quality endpoint + route + 5 tests`
- Task 2: `feat(call): CallQualityCalculator service + 5 tests`
- Task 1: `feat(call): add call_logs.quality_metrics JSON column`
- Plan: `docs: add Phase 19a call quality telemetry plan`
- Spec: `docs(spec): phase 19a call quality telemetry`

- [ ] **Step 4: Push to origin**

```bash
git push origin main
```

Expected: `<prior SHA>..<latest SHA>  main -> main`.

- [ ] **Step 5: Production deploy checklist (informational)**

```
On production server:
1. cd /root/Blast_dplux
2. bash deploy.sh                         # pulls + composer + npm build + migrate + caches
3. (No infrastructure changes for Phase 19a — no new daemon, no new env keys)

Live smoke test:
4. Make a real call (outbound or inbound, Meta or AT)
5. Hang up after a few seconds of conversation
6. Visit /calls — the row for that call should render a colored MOS chip
   - If chip is gray "—": browser POST didn't land (check browser console for errors,
     check Laravel log for 422/403 on /calls/{id}/quality)
   - If chip color makes sense (emerald for clean network, amber for moderate, red for bad):
     telemetry is flowing correctly
7. For AT calls specifically: if always gray "—", the AT SDK's peer accessor
   returned undefined at runtime. Investigate this.atClient API in browser console,
   adjust outbound-call.js accordingly, redeploy.
```

If any of: Meta calls show "—" → check `calls.js` wiring + browser console for `getStats` errors. AT calls show "—" but Meta works → AT SDK peer accessor differs from assumption (line ~32 of outbound-call.js); inspect the SDK's actual API and adjust.

- [ ] **Step 6: Report**

Phase 19a done. Test trajectory:
- Phase 18 baseline: 257 tests
- Task 1 (migration + casts): 257 (no new tests)
- Task 2 (CallQualityCalculator + 5 tests): 262 (+5)
- Task 3 (controller + route + 5 tests): 267 (+5)
- Task 4 (browser collector): 267 (no new tests — JS only)
- Task 5 (Quality column): 267 (no new tests — Blade only)
- Task 6 (verify + push): 267
- Final: **267 tests, all green**

Behavioral changes shipped:
- Every call (Phase 17 Meta + Phase 18 AT) captures quality metrics during the call.
- On hangup, browser POSTs averages to `/calls/{id}/quality`.
- Server computes G.107 MOS, persists 7-field JSON to `call_logs.quality_metrics`.
- `/calls` history page shows colored MOS chips with diagnostic tooltips.
- Pre-Phase-19a rows + telemetry-failed calls show gray "—".

Foundation in place for:
- Phase 19b (TURN server) — needs `ice_candidate_type` distribution data this phase produces.
- Phase 19c (call recording) — duration distribution + quality patterns inform retention design.
- Phase 19d (voicemail / IVR) — call-quality patterns inform when voicemail is preferred.

Production rollout: standard `bash deploy.sh`. No new infrastructure (no daemon, no env keys). First post-deploy call's row in /calls will reveal whether telemetry is flowing for both providers.
