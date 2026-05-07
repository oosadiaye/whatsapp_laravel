# Phase 17 — Inbound WhatsApp Call Browser Answer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the existing Phase 14.1 ringing notification into a real WebRTC voice path so the assigned agent can click Accept, talk into their browser microphone, and hear the customer through the browser audio output.

**Architecture:** Webhook → InboundCallProcessor stores SDP offer + fires `preAcceptCall` to Meta + broadcasts `CallRinging` over Reverb private channel. Agent's IncomingCall Alpine factory subscribes via Echo, renders Accept/Decline. On Accept: claim row atomically, request mic, build `RTCPeerConnection`, generate SDP answer, POST to server which calls `acceptCall($sdp)` against Meta. Audio peer flows browser↔Meta over WebRTC (DTLS-SRTP + OPUS).

**Tech Stack:** Laravel 12 · Laravel Reverb (new) · Laravel Echo + pusher-js (new JS) · PHP 8.2 (XAMPP local; opcache disabled for artisan) · Livewire 4 · Alpine.js · WebRTC native APIs · SQLite local DB · PHPUnit 11.

---

## Spec reference

Full design: `docs/superpowers/specs/2026-05-07-inbound-call-browser-answer-design.md` (committed `682b5c4`).

## Scope warning

This is the largest single phase since 14.1. **9 implementation tasks**, ~13 new files, ~15 modifications, 18 new tests. New infrastructure dependency (Reverb daemon). Plan accordingly: don't try to ship in one sitting — each task commits green so you can pause/resume between tasks safely.

## File structure

### Files to create (~14)

| File | Responsibility |
|---|---|
| `database/migrations/2026_05_07_180000_add_claim_columns_to_call_logs.php` | answered_by_session_id, sdp_offer, sdp_answer columns |
| `database/migrations/2026_05_07_180001_add_mic_permission_state_to_users.php` | mic_permission_state column with default 'pending' |
| `app/Events/Calling/CallRinging.php` | Broadcast on private-user.{id} when webhook arrives with SDP offer |
| `app/Events/Calling/CallClaimed.php` | Broadcast when one tab wins the atomic claim, others dismiss |
| `app/Events/Calling/CallTerminated.php` | Broadcast on any call-end cause (decline, hangup, customer disconnect, stale cleanup) |
| `app/Console/Commands/CleanupStaleCalls.php` | Periodic stale-call sweep (30-min threshold) |
| `app/Livewire/IncomingCall.php` | Hosts the Alpine peer-connection state |
| `resources/views/livewire/incoming-call.blade.php` | Accept/Decline/in-call banner UI with x-data binding to calls.js |
| `resources/js/calls.js` | Alpine factory + RTCPeerConnection lifecycle (mic capture, SDP exchange, audio rendering, hangup) |
| `deploy/supervisor-reverb.conf` | Reverb daemon supervisor program template |
| `deploy/install-reverb.sh` | Bootstrap script (path-substitute + register + start) |
| `tests/Feature/Services/WhatsAppCloudApiCallingTest.php` | 5 service tests for preAcceptCall + acceptCall |
| `tests/Feature/Http/CallRouteTest.php` | 6 controller route tests for claim/answer/decline/hangup |
| `tests/Feature/Console/CleanupStaleCallsTest.php` | 4 stale-cleanup command tests |

### Files to modify (~15)

| File | Change |
|---|---|
| `app/Models/User.php` | Add MIC_PENDING/MIC_GRANTED/MIC_DENIED constants |
| `app/Services/WhatsAppCloudApiService.php` | Add `preAcceptCall()` and `acceptCall()` methods |
| `app/Services/InboundCallProcessor.php` | Persist sdp_offer, fire preAcceptCall, broadcast CallRinging |
| `app/Http/Controllers/CallController.php` | Add `claim`, `answer`, `decline`, `hangup` methods |
| `routes/web.php` | 4 new routes inside `permission:conversations.reply` group |
| `routes/console.php` | Schedule `calls:cleanup-stale` everyMinute withoutOverlapping |
| `routes/channels.php` | Authorize `user.{id}` private channel |
| `resources/views/livewire/realtime-pulse.blade.php` | Mount `<livewire:incoming-call :call="..." />` inside the call banner |
| `resources/js/app.js` | Initialize Echo with Reverb config + import calls.js |
| `composer.json` | Add laravel/reverb dependency |
| `package.json` | Add laravel-echo + pusher-js dependencies |
| `deploy/nginx.conf` | WebSocket upgrade location block at /app |
| `deploy.sh` | New step calling install-reverb.sh once on first deploy |
| `.env.example` | REVERB_* + VITE_REVERB_* entries |
| `tests/Feature/Webhooks/InboundCallProcessingTest.php` | 3 appended tests for SDP persistence + preAcceptCall + CallRinging broadcast |

### Existing infrastructure reused (verified before planning)

- `App\Models\CallLog` constants `STATUS_INITIATED`, `STATUS_RINGING`, `STATUS_CONNECTED`, `STATUS_ENDED`, `STATUS_MISSED`, `STATUS_DECLINED`, `STATUS_FAILED` exist (lines 19-25). Use these — do NOT introduce magic strings.
- `App\Services\WhatsAppCloudApiService` private helpers `client(WhatsAppInstance)` and `url(string)` (lines 39, 54). New `preAcceptCall` + `acceptCall` follow the same pattern as existing `initiateCall` (line 155) and `endCall` (line 189).
- `App\Http\Controllers\CallController` exists with only `index()` method (line 21-23). We extend the class with new methods.
- `routes/web.php` has existing `permission:conversations.reply` middleware group (used by Phase 13.x for reply endpoints). Verify exact line during implementation.
- `routes/channels.php` exists (Laravel 12 default).
- `tests/Feature/Webhooks/InboundCallProcessingTest.php` exists with 13 prior tests (Phase 14.x). New tests append before final closing brace.
- Phase 14.1's `RealtimePulse` Livewire component + view + Alpine factory (`window.realtimePulse`) is the integration surface.

### Environment notes (apply to every task)

- Always prefix artisan/phpunit commands with `php -d opcache.enable=0 -d opcache.enable_cli=0` (XAMPP-Windows opcache permission bug).
- Tests use SQLite in-memory via `RefreshDatabase`. `Event::fake()` and `Http::fake()` for broadcast/HTTP mocking.
- Branch: `main`, committing direct (user-approved).
- Baseline: 216 tests must remain green at every checkpoint. Final target: **234 tests** (+18).

---

# Tasks

## Task 1: Database migrations + User model constants

**Files:**
- Create: `database/migrations/2026_05_07_180000_add_claim_columns_to_call_logs.php`
- Create: `database/migrations/2026_05_07_180001_add_mic_permission_state_to_users.php`
- Modify: `app/Models/User.php`

This is the unblocker. Subsequent tasks reference the new columns. Tiny, no dedicated test (later tasks exercise these fields).

- [ ] **Step 1: Generate the call_logs migration**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan make:migration add_claim_columns_to_call_logs
```

Rename the generated file to `2026_05_07_180000_add_claim_columns_to_call_logs.php`.

- [ ] **Step 2: Replace the call_logs migration body**

```php
public function up(): void
{
    Schema::table('call_logs', function (Blueprint $table) {
        // The browser session UUID that claimed this call. Atomic UPDATE
        // WHERE answered_by_session_id IS NULL guarantees first-tab-wins
        // semantics with no application-layer race window.
        $table->string('answered_by_session_id', 64)->nullable()
            ->after('placed_by_user_id');
        $table->index('answered_by_session_id');

        // SDP offer received from Meta in the connect webhook. Stored so
        // a tab loading mid-ring can fetch the offer via Livewire mount,
        // not only from the live Reverb broadcast.
        $table->text('sdp_offer')->nullable()->after('answered_by_session_id');

        // SDP answer the agent's browser generated. Audit aid only.
        $table->text('sdp_answer')->nullable()->after('sdp_offer');
    });
}

public function down(): void
{
    Schema::table('call_logs', function (Blueprint $table) {
        $table->dropIndex(['answered_by_session_id']);
        $table->dropColumn(['answered_by_session_id', 'sdp_offer', 'sdp_answer']);
    });
}
```

- [ ] **Step 3: Generate the users migration**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan make:migration add_mic_permission_state_to_users
```

Rename to `2026_05_07_180001_add_mic_permission_state_to_users.php`.

- [ ] **Step 4: Replace the users migration body**

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // pending = never asked (default), granted = browser said yes,
        // denied = browser said no. The browser's permission API is the
        // source of truth; this column drives a "Grant microphone access"
        // hint banner only.
        $table->string('mic_permission_state', 16)
            ->default('pending')
            ->after('presence_status_set_at');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('mic_permission_state');
    });
}
```

- [ ] **Step 5: Run migrations**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan migrate
```

Expected: both new migrations run DONE.

- [ ] **Step 6: Add User constants**

Open `app/Models/User.php`. Find the existing `PRESENCE_*` constants (Phase 14.3 added them). Append three new constants right after the `PRESENCE_STATUSES` array:

```php
public const MIC_PENDING = 'pending';
public const MIC_GRANTED = 'granted';
public const MIC_DENIED  = 'denied';

public const MIC_PERMISSION_STATES = [
    self::MIC_PENDING,
    self::MIC_GRANTED,
    self::MIC_DENIED,
];
```

No cast needed — `mic_permission_state` is a plain string.

- [ ] **Step 7: Run full suite to confirm no regression**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (216 tests, ...)`. Migrations are additive with safe defaults; constants are dormant.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_05_07_180000_add_claim_columns_to_call_logs.php database/migrations/2026_05_07_180001_add_mic_permission_state_to_users.php app/Models/User.php
git commit -m "feat(call): add call_logs claim columns + users.mic_permission_state

Two migrations and three User constants for Phase 17 inbound browser
answer:

- call_logs.answered_by_session_id (string, indexed) for atomic tab
  claim — first POST /calls/{id}/claim wins via WHERE IS NULL.
- call_logs.sdp_offer (text) stores Meta's SDP offer from the connect
  webhook so mid-ring tab loads can recover state via Livewire mount.
- call_logs.sdp_answer (text) audit-only.
- users.mic_permission_state ('pending'/'granted'/'denied') drives the
  'Grant microphone access' hint banner; browser's permission API is
  the actual source of truth.

User model gets MIC_PENDING/MIC_GRANTED/MIC_DENIED constants plus a
MIC_PERMISSION_STATES validation array."
```

---

## Task 2: Reverb install + Echo client + channel authorization

**Files:**
- Modify: `composer.json` (via composer require)
- Modify: `package.json` (via npm install)
- Modify: `routes/channels.php`
- Modify: `resources/js/app.js`
- Modify: `.env.example`

This task installs the new infrastructure dependency. No tests yet — the broadcast events that USE Reverb arrive in later tasks.

- [ ] **Step 1: Install Reverb composer package**

```bash
composer require laravel/reverb
```

Expected: package installed, `composer.json` updated, `composer.lock` updated.

- [ ] **Step 2: Install Reverb's published config + .env keys**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan reverb:install
```

This is Laravel's helper that adds `BROADCAST_CONNECTION=reverb` + `REVERB_APP_*` keys to `.env`, publishes `config/reverb.php`, and adds the `reverb` driver to `config/broadcasting.php`. Accept all prompts.

- [ ] **Step 3: Install JS client libraries**

```bash
npm install --save laravel-echo pusher-js
```

Expected: `package.json` and `package-lock.json` updated.

- [ ] **Step 4: Authorize the user.{id} private channel**

Open `routes/channels.php`. Add at the end of the file (or replace empty default):

```php
Broadcast::channel('user.{id}', function ($user, $id) {
    // Each user authorizes ONLY for their own user-scoped channel.
    // Echo passes the authenticated session cookie; this closure runs
    // inside the standard Laravel session guard.
    return (int) $user->id === (int) $id;
});
```

- [ ] **Step 5: Initialize Echo in app.js**

Open `resources/js/app.js`. Find the Alpine bootstrap section (top of file, around lines 1-25). PREPEND these imports + Echo init at the very top, before the existing `import './bootstrap';`:

```js
import './bootstrap';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import Alpine from 'alpinejs';

// Reverb / Echo bootstrap.
//
// Why Pusher.js even though we run Reverb: laravel-echo speaks the Pusher
// wire protocol, and Reverb is wire-compatible with Pusher. The library
// expects 'pusher' as the broadcaster name; the actual server is Reverb
// listening on our own host. Same protocol, different server.
window.Pusher = Pusher;
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

// userId is read from a meta tag we'll add to app.blade.php for
// channel-naming convenience (Echo.private(`user.${userId}`)). The meta
// tag is rendered server-side from auth()->id() and is naturally absent
// for guests, so guest pages never try to subscribe to a private channel.
const userIdMeta = document.querySelector('meta[name="user-id"]');
window.userId = userIdMeta ? parseInt(userIdMeta.getAttribute('content'), 10) : null;
```

- [ ] **Step 6: Add user-id meta tag to app layout**

Open `resources/views/layouts/app.blade.php`. Find the `<head>` block. Right after the `<meta name="csrf-token" ...>` line, add:

```blade
@auth
    <meta name="user-id" content="{{ auth()->id() }}">
@endauth
```

- [ ] **Step 7: Update .env.example with Reverb keys**

Open `.env.example` and append:

```env

# Reverb (Phase 17 inbound call browser answer)
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=blastiq
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${APP_URL_HOST:-localhost}"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

(The actual `.env` was populated by `reverb:install` in Step 2.)

- [ ] **Step 8: Run full suite + npm build to confirm no regression**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
npm run build
```

Expected: tests `OK (216 tests, ...)`. Build completes without errors.

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock package.json package-lock.json config/reverb.php config/broadcasting.php routes/channels.php resources/js/app.js resources/views/layouts/app.blade.php .env.example
git commit -m "feat(call): install Reverb + Echo + private user channel auth

Phase 17 infrastructure dependency: laravel/reverb composer package +
laravel-echo + pusher-js npm packages (Reverb is wire-compatible with
the Pusher protocol, so Echo speaks to Reverb the same way it speaks
to Pusher).

routes/channels.php authorizes 'user.{id}' so each user subscribes
ONLY to their own user-scoped private channel — used by Phase 17 for
CallRinging/CallClaimed/CallTerminated events targeting the assigned
agent specifically.

resources/js/app.js initializes Echo at module load (before Alpine
start) so any Alpine factory can access window.Echo.private(...).
The user-id meta tag in app.blade.php (rendered server-side under
@auth) is read by app.js to know which channel name to subscribe.

Reverb daemon installation + supervisor program defer to Task 8."
```

---

## Task 3: WhatsAppCloudApiService — `preAcceptCall` + `acceptCall` + 5 tests (TDD)

**Files:**
- Create: `tests/Feature/Services/WhatsAppCloudApiCallingTest.php`
- Modify: `app/Services/WhatsAppCloudApiService.php`

- [ ] **Step 1: Create the failing test file**

Create `tests/Feature/Services/WhatsAppCloudApiCallingTest.php` with this EXACT content:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsAppInstance;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppCloudApiCallingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pre_accept_call_posts_correct_payload_with_no_sdp(): void
    {
        $instance = WhatsAppInstance::factory()->create([
            'phone_number_id' => '123456789',
            'access_token' => 'EAAtest',
        ]);
        $service = $this->app->make(WhatsAppCloudApiService::class);

        Http::fake([
            '*/123456789/calls' => Http::response(['success' => true], 200),
        ]);

        $service->preAcceptCall($instance, 'wacid.abc123');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($request->url(), '123456789/calls')
                && $body['action'] === 'pre_accept'
                && $body['call_id'] === 'wacid.abc123'
                && $body['messaging_product'] === 'whatsapp'
                && !isset($body['session']);
        });
    }

    public function test_pre_accept_call_posts_with_sdp_when_provided(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $service = $this->app->make(WhatsAppCloudApiService::class);

        Http::fake(['*' => Http::response([], 200)]);

        $service->preAcceptCall($instance, 'wacid.abc', 'v=0\r\no=...');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['action'] === 'pre_accept'
                && $body['session']['sdp_type'] === 'answer'
                && $body['session']['sdp'] === 'v=0\r\no=...';
        });
    }

    public function test_pre_accept_call_does_not_throw_on_4xx(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $service = $this->app->make(WhatsAppCloudApiService::class);

        Http::fake(['*' => Http::response(['error' => 'bad'], 400)]);

        // Pre-accept is OPTIONAL — failure logs warning but does NOT throw.
        // Call should still ring on the agent's screen even without pre-accept.
        $service->preAcceptCall($instance, 'wacid.abc');

        $this->assertTrue(true, 'preAcceptCall did not throw on 4xx — correct behavior');
    }

    public function test_accept_call_posts_sdp_answer_correctly(): void
    {
        $instance = WhatsAppInstance::factory()->create([
            'phone_number_id' => '999',
        ]);
        $service = $this->app->make(WhatsAppCloudApiService::class);

        Http::fake(['*' => Http::response([], 200)]);

        $service->acceptCall($instance, 'wacid.xyz', 'sdp-answer-blob');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($request->url(), '999/calls')
                && $body['action'] === 'accept'
                && $body['call_id'] === 'wacid.xyz'
                && $body['session']['sdp_type'] === 'answer'
                && $body['session']['sdp'] === 'sdp-answer-blob';
        });
    }

    public function test_accept_call_throws_on_4xx(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $service = $this->app->make(WhatsAppCloudApiService::class);

        Http::fake(['*' => Http::response(['error' => 'bad'], 400)]);

        $this->expectException(WhatsAppApiException::class);
        $service->acceptCall($instance, 'wacid.abc', 'sdp');
    }
}
```

- [ ] **Step 2: Run, confirm all 5 tests ERROR with method-not-found**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/WhatsAppCloudApiCallingTest.php --no-coverage
```

Expected: 5 errors, all with `Call to undefined method ... preAcceptCall()` or `acceptCall()`.

- [ ] **Step 3: Add the two methods to WhatsAppCloudApiService**

Open `app/Services/WhatsAppCloudApiService.php`. Find the existing `endCall` method (around line 189). INSERT these two new methods directly after `endCall` (before the next existing method like `markAsRead`):

```php
/**
 * Tell Meta we're engaging with an inbound call before the agent
 * actually clicks Accept. Holds the call open and avoids the
 * "audio clipping" Meta documents for back-to-back pre_accept+accept.
 *
 * Endpoint: POST /v20.0/{phone_number_id}/calls action=pre_accept
 *
 * Pre-accept is OPTIONAL — 4xx is logged as a warning but NOT thrown.
 * The call still rings on the agent's dashboard and Accept still works
 * without the no-clipping benefit.
 *
 * @param  string|null  $sdpAnswer  When non-null, included in the session.
 *                                  Some Meta API versions require SDP at
 *                                  pre_accept time; if so, callers will
 *                                  pass the agent's SDP answer here.
 */
public function preAcceptCall(WhatsAppInstance $instance, string $metaCallId, ?string $sdpAnswer = null): void
{
    $payload = [
        'messaging_product' => 'whatsapp',
        'call_id' => $metaCallId,
        'action' => 'pre_accept',
    ];
    if ($sdpAnswer !== null) {
        $payload['session'] = ['sdp_type' => 'answer', 'sdp' => $sdpAnswer];
    }

    $response = $this->client($instance)->post(
        $this->url("{$instance->phone_number_id}/calls"),
        $payload,
    );

    if ($response->failed()) {
        $this->logHttp('preAcceptCall', $instance, $response->status(), $response->body());
        \Log::warning('preAcceptCall failed; continuing without pre-accept benefit', [
            'meta_call_id' => $metaCallId,
            'status' => $response->status(),
        ]);
    }
}

/**
 * Accept an inbound call, sending the agent's SDP answer to Meta.
 * Meta then drives ICE/DTLS handshake; audio peer establishes
 * browser↔Meta over WebRTC.
 *
 * Endpoint: POST /v20.0/{phone_number_id}/calls action=accept
 *
 * @throws WhatsAppApiException  on 4xx — without accept, no audio path.
 */
public function acceptCall(WhatsAppInstance $instance, string $metaCallId, string $sdpAnswer): void
{
    $response = $this->client($instance)->post(
        $this->url("{$instance->phone_number_id}/calls"),
        [
            'messaging_product' => 'whatsapp',
            'call_id' => $metaCallId,
            'action' => 'accept',
            'session' => [
                'sdp_type' => 'answer',
                'sdp' => $sdpAnswer,
            ],
        ],
    );

    if ($response->failed()) {
        $this->logHttp('acceptCall', $instance, $response->status(), $response->body());
        throw new WhatsAppApiException(
            "acceptCall failed: {$response->status()} - {$response->body()}"
        );
    }
}
```

If the file uses a `use` statement for `Log`, replace `\Log::warning` with `Log::warning` and add the import. If `WhatsAppApiException` isn't already imported (it should be, from existing methods), verify and add.

- [ ] **Step 4: Run the 5 tests, confirm all PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/WhatsAppCloudApiCallingTest.php --no-coverage
```

Expected: `OK (5 tests, ...)`.

- [ ] **Step 5: Run full suite to confirm no regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (221 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WhatsAppCloudApiService.php tests/Feature/Services/WhatsAppCloudApiCallingTest.php
git commit -m "feat(call): WhatsAppCloudApiService preAcceptCall + acceptCall + 5 tests

Two new methods on the existing service alongside initiateCall and
endCall. Both POST to the same /{phone_number_id}/calls endpoint with
different action values per Meta's WhatsApp Business Calling API spec.

preAcceptCall is fire-and-forget by design — 4xx logs a warning but
does not throw. Callers (InboundCallProcessor in Task 4) fire it
immediately on webhook receipt so Meta knows the business is engaging
the call before the agent finishes resolving the mic permission prompt.

acceptCall throws WhatsAppApiException on 4xx because without it
there is no audio path — the call cannot continue and the caller
must surface the failure to the agent UI.

Tests: payload shape verification (with/without SDP), 4xx behavior
divergence (warn vs throw), Http::fake for full-stack assertion."
```

---

## Task 4: InboundCallProcessor extension + CallRinging event + 3 appended tests

**Files:**
- Create: `app/Events/Calling/CallRinging.php`
- Modify: `app/Services/InboundCallProcessor.php`
- Modify: `tests/Feature/Webhooks/InboundCallProcessingTest.php`

- [ ] **Step 1: Create the CallRinging event**

Create `app/Events/Calling/CallRinging.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events\Calling;

use App\Models\CallLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when InboundCallProcessor sees a new ringing call. Routes
 * to the assigned agent's user-scoped private channel so only that
 * agent's open browser tabs receive the SDP offer + Accept/Decline UI.
 *
 * Phase 17 — replaces the Phase 14.1 polled-banner discovery model
 * with real-time push (~100ms latency vs. up-to-3s).
 */
class CallRinging implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public CallLog $call)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.' . $this->call->assigned_to_user_id);
    }

    public function broadcastAs(): string
    {
        return 'call.ringing';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->call->id,
            'meta_call_id' => $this->call->meta_call_id,
            'contact_name' => $this->call->contact->display_name ?? null,
            'phone' => $this->call->from_phone,
            'sdp_offer' => $this->call->sdp_offer,
        ];
    }
}
```

- [ ] **Step 2: Append 3 failing tests to InboundCallProcessingTest**

Open `tests/Feature/Webhooks/InboundCallProcessingTest.php`. APPEND these tests just before the final closing `}` of the class:

```php
    public function test_inbound_call_persists_sdp_offer_from_webhook(): void
    {
        // Reuse the existing test pattern's seeder + factory setup. If
        // setUp() does not seed RolesAndPermissionsSeeder, prepend
        // $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class).

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $admin->assignRole(User::ROLE_ADMIN);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $agent->assignRole(User::ROLE_AGENT);
        $instance = \App\Models\WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 200)]);

        $processor = $this->app->make(\App\Services\InboundCallProcessor::class);
        $processor->processCalls($instance, [
            [
                'id' => 'wacid.sdp_test',
                'from' => '2348011111111',
                'to' => $instance->business_phone_number,
                'event' => 'connect',
                'timestamp' => '1714500000',
                'session' => [
                    'sdp' => 'v=0\r\no=- 123 IN IP4 0.0.0.0\r\n',
                    'sdp_type' => 'offer',
                ],
            ],
        ]);

        $call = \App\Models\CallLog::where('meta_call_id', 'wacid.sdp_test')->first();
        $this->assertNotNull($call);
        $this->assertSame('v=0\r\no=- 123 IN IP4 0.0.0.0\r\n', $call->sdp_offer);
    }

    public function test_inbound_call_invokes_pre_accept_call(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $admin->assignRole(User::ROLE_ADMIN);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'is_active' => true, 'last_seen_at' => now()]);
        $agent->assignRole(User::ROLE_AGENT);
        $instance = \App\Models\WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'phone_number_id' => '777']);

        \Illuminate\Support\Facades\Http::fake([
            '*/777/calls' => \Illuminate\Support\Facades\Http::response([], 200),
        ]);

        $processor = $this->app->make(\App\Services\InboundCallProcessor::class);
        $processor->processCalls($instance, [
            [
                'id' => 'wacid.preaccept',
                'from' => '2348011111111',
                'to' => $instance->business_phone_number,
                'event' => 'connect',
                'timestamp' => '1714500000',
                'session' => ['sdp' => 'sdp-blob', 'sdp_type' => 'offer'],
            ],
        ]);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($request->url(), '777/calls')
                && ($body['action'] ?? null) === 'pre_accept'
                && ($body['call_id'] ?? null) === 'wacid.preaccept';
        });
    }

    public function test_inbound_call_dispatches_call_ringing_event(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\Calling\CallRinging::class]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $admin->assignRole(User::ROLE_ADMIN);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'is_active' => true, 'last_seen_at' => now()]);
        $agent->assignRole(User::ROLE_AGENT);
        $instance = \App\Models\WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 200)]);

        $processor = $this->app->make(\App\Services\InboundCallProcessor::class);
        $processor->processCalls($instance, [
            [
                'id' => 'wacid.event_test',
                'from' => '2348011111111',
                'to' => $instance->business_phone_number,
                'event' => 'connect',
                'timestamp' => '1714500000',
                'session' => ['sdp' => 'sdp', 'sdp_type' => 'offer'],
            ],
        ]);

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\Calling\CallRinging::class, function ($event) use ($agent) {
            return $event->call->meta_call_id === 'wacid.event_test'
                && $event->call->assigned_to_user_id === $agent->id;
        });
    }
```

If `User` is not imported at the top of the file, add `use App\Models\User;`.

- [ ] **Step 3: Run, confirm 3 new tests FAIL**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/InboundCallProcessingTest.php --filter "test_inbound_call_persists_sdp_offer|test_inbound_call_invokes_pre_accept_call|test_inbound_call_dispatches_call_ringing_event" --no-coverage
```

Expected: 3 failures — sdp_offer not persisted, preAcceptCall not invoked, CallRinging not dispatched.

- [ ] **Step 4: Extend InboundCallProcessor**

Open `app/Services/InboundCallProcessor.php`. The current Phase 14.2 flow inside `processCalls()` (or the per-call handler within it) creates the CallLog and runs auto-assign. Find the location AFTER auto-assign completes and BEFORE the method returns. Add:

```php
// Phase 17: persist SDP offer + tell Meta we're engaging + push to agent's browser.
$sdpOffer = $payload['session']['sdp'] ?? null;
if ($sdpOffer !== null) {
    $callLog->update(['sdp_offer' => $sdpOffer]);
}

// Pre-accept is fire-and-forget — failure does NOT abort the call.
// preAcceptCall handles its own 4xx logging.
try {
    $this->cloudApi->preAcceptCall($instance, $callLog->meta_call_id);
} catch (\Throwable $e) {
    \Log::warning('preAcceptCall threw unexpectedly; continuing', ['error' => $e->getMessage()]);
}

// Push the SDP offer + call metadata to the assigned agent's browser
// over Reverb. Only fire if there's an assignee — unassigned calls
// (round-robin returned null) have no target channel.
if ($callLog->assigned_to_user_id !== null) {
    \App\Events\Calling\CallRinging::dispatch($callLog);
}
```

Adjust variable names if the existing method uses different ones (`$cloudApi` vs `$this->whatsappCloudApi`, etc.) — read the file to match.

- [ ] **Step 5: Run the 3 new tests, confirm PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/InboundCallProcessingTest.php --filter "test_inbound_call_persists_sdp_offer|test_inbound_call_invokes_pre_accept_call|test_inbound_call_dispatches_call_ringing_event" --no-coverage
```

Expected: 3 passes.

- [ ] **Step 6: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (224 tests, ...)`.

If any prior InboundCallProcessingTest test breaks: the new code paths fire on every call. Add `Http::fake(['*' => Http::response([], 200)])` to those tests' setup so the new preAcceptCall HTTP attempt doesn't blow up.

- [ ] **Step 7: Commit**

```bash
git add app/Events/Calling/CallRinging.php app/Services/InboundCallProcessor.php tests/Feature/Webhooks/InboundCallProcessingTest.php
git commit -m "feat(call): InboundCallProcessor persists SDP + fires preAccept + broadcasts CallRinging

Three additive responsibilities to the existing Phase 14.2 inbound
call processor, executed AFTER round-robin assignment but BEFORE
return:

1. Extract session.sdp from the webhook payload and persist on the
   CallLog (column added in Task 1). Mid-ring tab loads can fetch
   this offer via Livewire mount even if they missed the live
   Reverb broadcast.

2. Synchronously invoke WhatsAppCloudApiService::preAcceptCall (Task 3)
   so Meta knows the business is engaging before the agent's permission
   prompt resolves. Wrapped in try/catch so unexpected throws don't
   abort the webhook handler — pre-accept is best-effort.

3. Dispatch App\\Events\\Calling\\CallRinging on the assigned agent's
   private channel. The event broadcasts call_id, contact_name, phone,
   and the SDP offer. Skipped if assigned_to_user_id is null
   (round-robin returned null due to no available agents — call lands
   in unassigned filter, no target channel for broadcast).

Three new tests in InboundCallProcessingTest cover SDP persistence,
preAcceptCall invocation, and CallRinging dispatch."
```

---

## Task 5: CallController routes (claim, answer, decline, hangup) + 6 tests + Claimed/Terminated events

**Files:**
- Create: `app/Events/Calling/CallClaimed.php`
- Create: `app/Events/Calling/CallTerminated.php`
- Create: `tests/Feature/Http/CallRouteTest.php`
- Modify: `app/Http/Controllers/CallController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create CallClaimed event**

Create `app/Events/Calling/CallClaimed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events\Calling;

use App\Models\CallLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallClaimed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public CallLog $call)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.' . $this->call->assigned_to_user_id);
    }

    public function broadcastAs(): string
    {
        return 'call.claimed';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->call->id,
            'claimed_by_session_id' => $this->call->answered_by_session_id,
        ];
    }
}
```

- [ ] **Step 2: Create CallTerminated event**

Create `app/Events/Calling/CallTerminated.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events\Calling;

use App\Models\CallLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallTerminated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public CallLog $call, public string $reason)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.' . $this->call->assigned_to_user_id);
    }

    public function broadcastAs(): string
    {
        return 'call.terminated';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->call->id,
            'reason' => $this->reason,
        ];
    }
}
```

- [ ] **Step 3: Create the failing test file**

Create `tests/Feature/Http/CallRouteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Events\Calling\CallClaimed;
use App\Events\Calling\CallTerminated;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CallRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_claim_first_session_wins_second_gets_409(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);

        $first = $this->actingAs($agent)
            ->postJson(route('calls.claim', $call), ['session_id' => 'aaaaaaaa-bbbb-cccc-dddd-111111111111']);
        $first->assertOk()->assertJson(['claimed' => true]);

        $second = $this->actingAs($agent)
            ->postJson(route('calls.claim', $call), ['session_id' => 'aaaaaaaa-bbbb-cccc-dddd-222222222222']);
        $second->assertStatus(409);

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-111111111111', $call->fresh()->answered_by_session_id);
    }

    public function test_claim_same_session_is_idempotent(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);
        $sid = 'aaaaaaaa-bbbb-cccc-dddd-333333333333';

        $this->actingAs($agent)->postJson(route('calls.claim', $call), ['session_id' => $sid])->assertOk();
        $this->actingAs($agent)->postJson(route('calls.claim', $call), ['session_id' => $sid])->assertOk();
    }

    public function test_answer_invokes_accept_call_with_sdp_after_claim(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);
        $sid = 'aaaaaaaa-bbbb-cccc-dddd-444444444444';
        $call->update(['answered_by_session_id' => $sid]);

        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($agent)
            ->postJson(route('calls.answer', $call), [
                'session_id' => $sid,
                'sdp' => 'sdp-answer-blob',
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['action'] ?? null) === 'accept'
                && ($body['session']['sdp'] ?? null) === 'sdp-answer-blob';
        });
        $this->assertSame('sdp-answer-blob', $call->fresh()->sdp_answer);
    }

    public function test_answer_409s_if_session_id_does_not_match_claim(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);
        $call->update(['answered_by_session_id' => 'session-A']);

        $this->actingAs($agent)
            ->postJson(route('calls.answer', $call), [
                'session_id' => 'session-B',
                'sdp' => 'sdp',
            ])
            ->assertStatus(409);
    }

    public function test_decline_invokes_end_call_and_broadcasts_terminated(): void
    {
        Event::fake([CallTerminated::class]);
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);

        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($agent)
            ->postJson(route('calls.decline', $call))
            ->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['action'] ?? null) === 'terminate';
        });
        Event::assertDispatched(CallTerminated::class, function ($event) use ($call) {
            return $event->call->id === $call->id && $event->reason === 'declined';
        });
        $this->assertSame(CallLog::STATUS_DECLINED, $call->fresh()->status);
    }

    public function test_hangup_invokes_end_call_and_broadcasts_terminated(): void
    {
        Event::fake([CallTerminated::class]);
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);
        $call->update(['status' => CallLog::STATUS_CONNECTED]);

        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($agent)
            ->postJson(route('calls.hangup', $call))
            ->assertOk();

        Event::assertDispatched(CallTerminated::class, function ($event) use ($call) {
            return $event->call->id === $call->id && $event->reason === 'agent_hung_up';
        });
        $this->assertSame(CallLog::STATUS_ENDED, $call->fresh()->status);
    }

    private function makeAgent(): User
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $agent->assignRole(User::ROLE_AGENT);
        return $agent;
    }

    private function makeRingingCall(User $agent): CallLog
    {
        $owner = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $owner->assignRole(User::ROLE_ADMIN);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create(['user_id' => $owner->id, 'phone' => '23480'.fake()->unique()->numerify('########')]);
        $conversation = \App\Models\Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
            'unread_count' => 0,
        ]);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'meta_call_id' => 'wacid.'.fake()->unique()->numerify('########'),
            'status' => CallLog::STATUS_RINGING,
            'started_at' => now(),
            'sdp_offer' => 'fake-sdp-offer',
        ]);
    }
}
```

If `Conversation::create` requires additional columns in your schema, add them. The agent must have `conversations.reply` permission via the agent role for the route middleware to pass — verify in `RolesAndPermissionsSeeder` that this is true (Phase 13/14 should have set this up).

- [ ] **Step 4: Run, confirm 6 tests FAIL with route-not-found**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Http/CallRouteTest.php --no-coverage
```

Expected: 6 errors — route names like `calls.claim` not defined.

- [ ] **Step 5: Add 4 new methods to CallController**

Open `app/Http/Controllers/CallController.php`. The class currently has only `index()`. APPEND these 4 methods inside the class:

```php
public function claim(\Illuminate\Http\Request $request, \App\Models\CallLog $call): \Illuminate\Http\JsonResponse
{
    $sessionId = $request->input('session_id');
    if (!is_string($sessionId) || strlen($sessionId) > 64) {
        return response()->json(['error' => 'invalid session_id'], 422);
    }

    // Atomic: only succeeds if NULL (first claim) or already this session (idempotent).
    $rowsAffected = \DB::table('call_logs')
        ->where('id', $call->id)
        ->where(function ($q) use ($sessionId) {
            $q->whereNull('answered_by_session_id')
              ->orWhere('answered_by_session_id', $sessionId);
        })
        ->update(['answered_by_session_id' => $sessionId]);

    if ($rowsAffected === 0) {
        return response()->json(['error' => 'already claimed in another window'], 409);
    }

    $call->refresh();
    \App\Events\Calling\CallClaimed::dispatch($call);

    return response()->json(['claimed' => true]);
}

public function answer(\Illuminate\Http\Request $request, \App\Models\CallLog $call): \Illuminate\Http\JsonResponse
{
    $sessionId = $request->input('session_id');
    $sdp = $request->input('sdp');

    if ($call->answered_by_session_id !== $sessionId) {
        return response()->json(['error' => 'must claim before answering, or different session'], 409);
    }
    if (!is_string($sdp) || $sdp === '') {
        return response()->json(['error' => 'sdp required'], 422);
    }

    $service = app(\App\Services\WhatsAppCloudApiService::class);
    $service->acceptCall($call->whatsappInstance, $call->meta_call_id, $sdp);
    $call->update(['sdp_answer' => $sdp]);

    return response()->json(['accepted' => true]);
}

public function decline(\App\Models\CallLog $call): \Illuminate\Http\JsonResponse
{
    $service = app(\App\Services\WhatsAppCloudApiService::class);
    $service->endCall($call->whatsappInstance, $call->meta_call_id);
    $call->update([
        'status' => \App\Models\CallLog::STATUS_DECLINED,
        'ended_at' => now(),
    ]);
    \App\Events\Calling\CallTerminated::dispatch($call, 'declined');

    return response()->json(['declined' => true]);
}

public function hangup(\App\Models\CallLog $call): \Illuminate\Http\JsonResponse
{
    $service = app(\App\Services\WhatsAppCloudApiService::class);
    $service->endCall($call->whatsappInstance, $call->meta_call_id);
    $call->update([
        'status' => \App\Models\CallLog::STATUS_ENDED,
        'ended_at' => now(),
    ]);
    \App\Events\Calling\CallTerminated::dispatch($call, 'agent_hung_up');

    return response()->json(['ended' => true]);
}
```

If the file already imports `Request`, `JsonResponse`, `DB`, etc., you can drop the FQN prefixes. Match existing style.

- [ ] **Step 6: Register the 4 routes**

Open `routes/web.php`. Find the existing `permission:conversations.reply` middleware group (Phase 13.x). INSIDE that group, ADD:

```php
// Phase 17 — inbound call browser answer
Route::post('/calls/{call}/claim', [\App\Http\Controllers\CallController::class, 'claim'])->name('calls.claim');
Route::post('/calls/{call}/answer', [\App\Http\Controllers\CallController::class, 'answer'])->name('calls.answer');
Route::post('/calls/{call}/decline', [\App\Http\Controllers\CallController::class, 'decline'])->name('calls.decline');
Route::post('/calls/{call}/hangup', [\App\Http\Controllers\CallController::class, 'hangup'])->name('calls.hangup');
```

If `CallController` is already imported at the top of `routes/web.php`, drop the FQN.

- [ ] **Step 7: Run the 6 new tests, confirm PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Http/CallRouteTest.php --no-coverage
```

Expected: `OK (6 tests, ...)`.

- [ ] **Step 8: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (230 tests, ...)`.

- [ ] **Step 9: Commit**

```bash
git add app/Events/Calling/CallClaimed.php app/Events/Calling/CallTerminated.php app/Http/Controllers/CallController.php routes/web.php tests/Feature/Http/CallRouteTest.php
git commit -m "feat(call): CallController claim/answer/decline/hangup routes + 6 tests

Four POST endpoints under permission:conversations.reply gating, each
matching one stage of the Phase 17 inbound-answer state machine:

- claim:   atomic UPDATE call_logs SET answered_by_session_id = ?
           WHERE id = ? AND (IS NULL OR equals ?). First-tab-wins +
           idempotent re-claim. Broadcasts CallClaimed for losing tabs.
- answer:  validates session_id matches the prior claim, calls
           WhatsAppCloudApiService::acceptCall with the agent's SDP
           answer (the browser RTCPeerConnection generates it), persists
           sdp_answer for audit.
- decline: maps to existing endCall (terminate action). Status =
           STATUS_DECLINED. Broadcasts CallTerminated with reason='declined'.
- hangup:  same shape as decline but reason='agent_hung_up' and
           status = STATUS_ENDED.

Two new broadcast events (CallClaimed, CallTerminated) on the same
private-user.{id} channel used by CallRinging in Task 4.

Tests cover claim race + idempotency, session_id mismatch on answer,
end-call HTTP shape, and CallTerminated event dispatch with correct reason."
```

---

## Task 6: IncomingCall Livewire component + Blade view + calls.js Alpine factory + RealtimePulse mount

**Files:**
- Create: `app/Livewire/IncomingCall.php`
- Create: `resources/views/livewire/incoming-call.blade.php`
- Create: `resources/js/calls.js`
- Modify: `resources/js/app.js`
- Modify: `resources/views/livewire/realtime-pulse.blade.php`

**No automated test for this task.** The browser-side WebRTC peer connection (RTCPeerConnection, getUserMedia, audio rendering) cannot be exercised by PHPUnit. Manual smoke testing in Step 8 verifies actual audio. Earlier tasks' tests cover all server-side state transitions; this task only wires the JS↔server interface.

- [ ] **Step 1: Create IncomingCall Livewire component**

Create `app/Livewire/IncomingCall.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use Livewire\Component;

/**
 * Phase 17 — replaces the simple "Open conversation" button on the
 * Phase 14.1 call banner with full Accept/Decline/in-call WebRTC UI.
 *
 * The Livewire side is intentionally minimal: it owns the CallLog
 * binding, the Alpine factory in resources/js/calls.js owns the entire
 * RTCPeerConnection lifecycle (Livewire cannot hold a JS object across
 * re-renders, so the heavy state is JS-side).
 */
class IncomingCall extends Component
{
    public CallLog $call;

    public function mount(CallLog $call): void
    {
        $this->call = $call;
    }

    public function render()
    {
        return view('livewire.incoming-call');
    }
}
```

- [ ] **Step 2: Create the Blade view**

Create `resources/views/livewire/incoming-call.blade.php`:

```blade
<div x-data="incomingCall({
    callId: {{ $call->id }},
    metaCallId: @js($call->meta_call_id),
    sdpOffer: @js($call->sdp_offer),
    sessionId: @js(session()->getId()),
    contactName: @js($call->contact->display_name ?? 'Unknown'),
    phone: @js($call->from_phone),
    csrf: @js(csrf_token()),
})" x-init="init()">
    <template x-if="state === 'ringing'">
        <div class="flex items-center gap-3 bg-emerald-600 text-white px-4 py-3 shadow-md">
            <span class="text-xl animate-pulse" aria-hidden="true">📞</span>
            <div class="flex-1">
                <div class="font-semibold" x-text="`Incoming call from ${contactName}`"></div>
                <div class="text-xs text-emerald-100 font-mono" x-text="phone"></div>
            </div>
            <button @click="acceptCall()"
                    class="bg-white text-emerald-700 px-4 py-2 rounded-md text-sm font-medium hover:bg-emerald-50">
                Accept
            </button>
            <button @click="declineCall()"
                    class="bg-red-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-red-700">
                Decline
            </button>
        </div>
    </template>

    <template x-if="state === 'connecting'">
        <div class="bg-amber-100 border-b border-amber-300 text-amber-900 px-4 py-3">
            <span>Connecting to <span x-text="contactName"></span>...</span>
        </div>
    </template>

    <template x-if="state === 'connected'">
        <div class="flex items-center justify-between bg-emerald-100 border-b border-emerald-300 text-emerald-900 px-4 py-3">
            <span>
                On call: <span x-text="contactName" class="font-semibold"></span>
                · <span x-text="formatDuration(durationSeconds)"></span>
            </span>
            <div class="flex items-center gap-2">
                <button @click="toggleMute()"
                        class="px-3 py-1.5 rounded text-sm font-medium"
                        :class="muted ? 'bg-amber-600 text-white' : 'bg-white text-emerald-700 border border-emerald-300'"
                        x-text="muted ? 'Unmute' : 'Mute'"></button>
                <button @click="hangup()"
                        class="bg-red-600 text-white px-4 py-1.5 rounded text-sm font-medium hover:bg-red-700">
                    Hang up
                </button>
            </div>
        </div>
    </template>

    <template x-if="state === 'mic_denied'">
        <div class="bg-red-100 border-b border-red-300 text-red-900 px-4 py-3 text-sm">
            Microphone access required to answer calls. Click the lock icon in your browser address bar
            to grant permission, then reload the page.
        </div>
    </template>

    <template x-if="state === 'claimed_elsewhere'">
        <div class="bg-gray-100 border-b border-gray-300 text-gray-700 px-4 py-3 text-sm">
            Call answered in another window or device.
        </div>
    </template>

    <audio id="bq-remote-audio" autoplay></audio>
</div>
```

- [ ] **Step 3: Create the calls.js Alpine factory**

Create `resources/js/calls.js`:

```js
/**
 * Phase 17 — RTCPeerConnection lifecycle for inbound WhatsApp call answer.
 *
 * Flow on Accept click:
 *   1. POST /calls/{id}/claim with session_id (atomic — first wins).
 *   2. getUserMedia({audio:true}) — browser permission prompt.
 *   3. new RTCPeerConnection({iceServers:[stun]}).
 *   4. peer.ontrack → set remote stream as <audio> srcObject (audio plays).
 *   5. peer.addTrack(micTrack, micStream) — outbound audio.
 *   6. peer.setRemoteDescription({type:'offer', sdp: sdpOffer}).
 *   7. answer = peer.createAnswer(); peer.setLocalDescription(answer).
 *   8. POST /calls/{id}/answer { session_id, sdp: answer.sdp }.
 *   9. Server forwards to Meta via acceptCall — audio peer establishes.
 *
 * On Decline / Hangup / claimed_elsewhere / customer disconnect (via
 * CallTerminated Echo event): teardown — peer.close(), stop mic tracks.
 */
window.incomingCall = (data) => ({
    ...data,
    state: 'ringing',
    peer: null,
    micStream: null,
    muted: false,
    durationSeconds: 0,
    durationTimer: null,
    echoChannel: null,

    init() {
        if (window.userId && window.Echo) {
            this.echoChannel = window.Echo.private(`user.${window.userId}`);
            this.echoChannel.listen('.call.claimed', (event) => {
                if (event.call_id === this.callId
                    && event.claimed_by_session_id !== this.sessionId) {
                    this.state = 'claimed_elsewhere';
                }
            });
            this.echoChannel.listen('.call.terminated', (event) => {
                if (event.call_id === this.callId) {
                    this.teardown('remote_terminated');
                }
            });
        }
    },

    async acceptCall() {
        try {
            // 1. Atomic claim — first POST wins.
            const claimRes = await this.post(`/calls/${this.callId}/claim`, { session_id: this.sessionId });
            if (claimRes.status === 409) {
                this.state = 'claimed_elsewhere';
                return;
            }
            if (!claimRes.ok) {
                throw new Error(`Claim failed: ${claimRes.status}`);
            }

            this.state = 'connecting';

            // 2. Microphone permission (just-in-time per Q3).
            this.micStream = await navigator.mediaDevices.getUserMedia({ audio: true });

            // 3. Peer connection. STUN is enough for most networks; TURN is Phase 19.
            this.peer = new RTCPeerConnection({
                iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
            });

            // 4. Audio rendering — Meta's stream → <audio> element.
            this.peer.ontrack = (event) => {
                const audioEl = document.getElementById('bq-remote-audio');
                if (audioEl && event.streams[0]) {
                    audioEl.srcObject = event.streams[0];
                }
            };

            // 5. Outbound audio — agent's mic.
            this.micStream.getAudioTracks().forEach(track => {
                this.peer.addTrack(track, this.micStream);
            });

            // 6-7. SDP exchange (offer from Meta, answer from us).
            await this.peer.setRemoteDescription({ type: 'offer', sdp: this.sdpOffer });
            const answer = await this.peer.createAnswer();
            await this.peer.setLocalDescription(answer);

            // 8. Forward answer to server, which calls Meta acceptCall.
            const answerRes = await this.post(`/calls/${this.callId}/answer`, {
                session_id: this.sessionId,
                sdp: answer.sdp,
            });
            if (!answerRes.ok) {
                throw new Error(`Answer failed: ${answerRes.status}`);
            }

            this.state = 'connected';
            this.startDurationTimer();
        } catch (error) {
            if (error && error.name === 'NotAllowedError') {
                this.state = 'mic_denied';
            } else {
                console.error('acceptCall failed', error);
                this.state = 'mic_denied'; // generic failure surface
            }
            // Tell server to release the call so customer doesn't hear silence.
            await this.post(`/calls/${this.callId}/decline`, {});
            this.cleanupMedia();
        }
    },

    async declineCall() {
        await this.post(`/calls/${this.callId}/decline`, {});
        this.teardown('agent_declined');
    },

    async hangup() {
        await this.post(`/calls/${this.callId}/hangup`, {});
        this.teardown('agent_hung_up');
    },

    toggleMute() {
        this.muted = !this.muted;
        this.micStream?.getAudioTracks().forEach(t => t.enabled = !this.muted);
    },

    startDurationTimer() {
        this.durationTimer = setInterval(() => this.durationSeconds++, 1000);
    },

    cleanupMedia() {
        try { this.peer?.close(); } catch (_) {}
        this.micStream?.getTracks().forEach(t => t.stop());
        this.peer = null;
        this.micStream = null;
        clearInterval(this.durationTimer);
        this.durationTimer = null;
    },

    teardown(reason) {
        this.cleanupMedia();
        this.state = 'terminated';
    },

    async post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
    },

    formatDuration(seconds) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${m}:${s.toString().padStart(2, '0')}`;
    },
});
```

- [ ] **Step 4: Import calls.js from app.js**

Open `resources/js/app.js`. After the existing Alpine bootstrap and BEFORE the `realtimePulse` factory (so calls.js's window.incomingCall is registered before any DOM that uses it), add:

```js
import './calls';
```

- [ ] **Step 5: Mount IncomingCall in the RealtimePulse banner**

Open `resources/views/livewire/realtime-pulse.blade.php`. The current view loops over `$inflightCalls` and renders the simple banner. REPLACE the per-call banner block (the `<div>` with `Incoming call from...` and `Open conversation →`) with a Livewire mount:

```blade
@forelse($inflightCalls as $call)
    @php
        $callLog = \App\Models\CallLog::find($call['id']);
    @endphp
    @if($callLog)
        <livewire:incoming-call :call="$callLog" :wire:key="'call-'.$callLog->id" />
    @endif
@empty
    {{-- nothing ringing right now --}}
@endforelse
```

If the original `$inflightCalls` payload structure differs (it's an array of arrays, not models), this `find()` lookup is the simplest bridge. If you'd prefer to pass full `CallLog` models from `RealtimePulse::render()`, refactor the component's render method to return `$inflightCallsModels` instead of arrays — minor change.

- [ ] **Step 6: Build assets**

```bash
npm run build
```

Expected: build completes, manifest updated, calls.js bundled into the output.

- [ ] **Step 7: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (230 tests, ...)`. The view change is invisible to existing tests; the new component has no automated test.

- [ ] **Step 8: Manual smoke test**

This is the **critical verification step**. The browser-side WebRTC code has no PHPUnit coverage; it must be exercised against a real call before committing.

1. Start the dev server: `php -d opcache.enable=0 -d opcache.enable_cli=0 artisan serve`
2. Start Reverb: `php -d opcache.enable=0 -d opcache.enable_cli=0 artisan reverb:start --host=127.0.0.1 --port=8080`
3. Start Vite dev: `npm run dev`
4. Log in as the seeded agent in the browser.
5. Trigger an inbound call to your Meta-registered business number.
6. Verify: banner appears with Accept/Decline. Click Accept. Mic prompt appears, grant. Banner transitions to "Connecting" then "On call." Speak — customer should hear you. Customer speaks — you should hear them.
7. Click Hang up. Banner clears.

If any of: ringtone but no Accept button → CallRinging not delivered (Reverb not running OR channel auth failing). Accept button but no transition → claim/answer endpoints failing (check network tab). Connecting forever → SDP exchange failing OR Meta rejecting accept. No audio → ICE gathering or TURN issue (Phase 19 territory).

Document any deviations from expected behavior; if blocked, escalate before committing.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/IncomingCall.php resources/views/livewire/incoming-call.blade.php resources/js/calls.js resources/js/app.js resources/views/livewire/realtime-pulse.blade.php
git commit -m "feat(call): IncomingCall Livewire + WebRTC client + RealtimePulse mount

The browser-side answer machinery for Phase 17. Three pieces:

1. App\\Livewire\\IncomingCall — minimal Livewire component holding the
   CallLog binding. Heavy state lives in JS because Livewire cannot
   retain a JS RTCPeerConnection object across re-renders.

2. resources/js/calls.js — Alpine factory window.incomingCall holding
   the entire RTCPeerConnection lifecycle: claim → mic → peer → SDP
   exchange → audio rendering → mute/hangup → teardown. Subscribes to
   Echo private-user.{id} for CallClaimed (other tab won) and
   CallTerminated (remote ended) events.

3. resources/views/livewire/incoming-call.blade.php — Tailwind UI for
   the four states: ringing (Accept/Decline), connecting (spinner),
   connected (mute + hangup + duration), mic_denied (recovery hint),
   claimed_elsewhere (banner stub).

resources/views/livewire/realtime-pulse.blade.php replaces the prior
simple 'Open conversation' button with the new Livewire component
mount, keyed by call ID for proper Livewire 4 child re-render
semantics.

No automated test for this task — RTCPeerConnection cannot be
exercised by PHPUnit. Phase 19 will add Playwright/Dusk for browser-
side automation. Manual smoke test verified actual audio flow before
commit (see Task 6 Step 8 in the plan)."
```

---

## Task 7: CleanupStaleCalls command + 4 tests + schedule

**Files:**
- Create: `app/Console/Commands/CleanupStaleCalls.php`
- Create: `tests/Feature/Console/CleanupStaleCallsTest.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Create the failing test file**

Create `tests/Feature/Console/CleanupStaleCallsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Events\Calling\CallTerminated;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CleanupStaleCallsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_thirty_minute_stale_ringing_marked_missed(): void
    {
        $call = $this->makeCall(CallLog::STATUS_RINGING, now()->subMinutes(35));

        $this->artisan('calls:cleanup-stale')->assertSuccessful();

        $call->refresh();
        $this->assertSame(CallLog::STATUS_MISSED, $call->status);
        $this->assertSame('stale - no terminate webhook received', $call->failure_reason);
        $this->assertNotNull($call->ended_at);
    }

    public function test_thirty_minute_stale_connected_marked_ended(): void
    {
        $call = $this->makeCall(CallLog::STATUS_CONNECTED, now()->subMinutes(45));

        $this->artisan('calls:cleanup-stale')->assertSuccessful();

        $call->refresh();
        $this->assertSame(CallLog::STATUS_ENDED, $call->status);
        $this->assertSame('stale - no terminate webhook received', $call->failure_reason);
    }

    public function test_recent_calls_untouched(): void
    {
        $recent = $this->makeCall(CallLog::STATUS_RINGING, now()->subMinutes(5));

        $this->artisan('calls:cleanup-stale')->assertSuccessful();

        $this->assertSame(CallLog::STATUS_RINGING, $recent->fresh()->status);
        $this->assertNull($recent->fresh()->ended_at);
    }

    public function test_stale_call_dispatches_terminated_event(): void
    {
        Event::fake([CallTerminated::class]);
        $call = $this->makeCall(CallLog::STATUS_RINGING, now()->subMinutes(35));

        $this->artisan('calls:cleanup-stale')->assertSuccessful();

        Event::assertDispatched(CallTerminated::class, function ($event) use ($call) {
            return $event->call->id === $call->id && $event->reason === 'stale_cleanup';
        });
    }

    private function makeCall(string $status, \Illuminate\Support\Carbon $startedAt): CallLog
    {
        $owner = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $owner->assignRole(User::ROLE_ADMIN);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'is_active' => true]);
        $agent->assignRole(User::ROLE_AGENT);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create(['user_id' => $owner->id, 'phone' => '23480'.fake()->unique()->numerify('########')]);
        $conversation = \App\Models\Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
            'unread_count' => 0,
        ]);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'meta_call_id' => 'wacid.'.fake()->unique()->numerify('########'),
            'status' => $status,
            'started_at' => $startedAt,
        ]);
    }
}
```

- [ ] **Step 2: Run, confirm 4 tests FAIL with command-not-found**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Console/CleanupStaleCallsTest.php --no-coverage
```

Expected: 4 errors — `Command "calls:cleanup-stale" is not defined`.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/CleanupStaleCalls.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Calling\CallTerminated;
use App\Models\CallLog;
use Illuminate\Console\Command;

/**
 * Phase 17 stale-call sweeper. Catches the case where Meta's terminate
 * webhook never arrives — without this, a CallLog row would be stuck
 * in 'ringing' or 'connected' indefinitely and the agent's banner
 * would never dismiss.
 *
 * Threshold: 30 minutes from started_at. Generous enough that genuine
 * long calls (rare for WhatsApp) aren't stomped early. Configurable
 * via Setting in a future phase if usage data shows a need.
 *
 * Scheduled everyMinute() in routes/console.php so a stuck banner
 * dismisses within ~1 minute of when the terminate webhook should
 * have arrived.
 */
class CleanupStaleCalls extends Command
{
    protected $signature = 'calls:cleanup-stale';

    protected $description = 'Mark calls as stale if Meta terminate webhook never arrived (30-min threshold)';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(30);

        $stale = CallLog::query()
            ->whereIn('status', [CallLog::STATUS_RINGING, CallLog::STATUS_CONNECTED])
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stale as $call) {
            $newStatus = $call->status === CallLog::STATUS_RINGING
                ? CallLog::STATUS_MISSED
                : CallLog::STATUS_ENDED;

            $call->update([
                'status' => $newStatus,
                'ended_at' => now(),
                'failure_reason' => 'stale - no terminate webhook received',
            ]);

            CallTerminated::dispatch($call, 'stale_cleanup');
        }

        $this->info(sprintf('Cleaned up %d stale call(s).', $stale->count()));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule the command**

Open `routes/console.php`. Append:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('calls:cleanup-stale')
    ->everyMinute()
    ->withoutOverlapping();
```

If `Schedule` is already imported, drop the use statement. Place the call alongside any existing scheduled commands.

- [ ] **Step 5: Run the 4 tests, confirm PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Console/CleanupStaleCallsTest.php --no-coverage
```

Expected: `OK (4 tests, ...)`.

- [ ] **Step 6: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (234 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/CleanupStaleCalls.php routes/console.php tests/Feature/Console/CleanupStaleCallsTest.php
git commit -m "feat(call): CleanupStaleCalls artisan command + everyMinute schedule + 4 tests

Defense in depth against missing Meta terminate webhooks. Without this
sweep, a CallLog row whose terminate webhook never arrived would stay
in 'ringing' or 'connected' forever and the agent's banner would never
dismiss.

Threshold: 30 minutes from started_at — generous enough that real long
calls aren't stomped early but tight enough that stuck banners clear
within ~1 minute of when the webhook should have arrived (since the
schedule fires everyMinute).

Cleanup transitions: ringing → STATUS_MISSED, connected → STATUS_ENDED.
failure_reason annotated 'stale - no terminate webhook received' so
ops can spot a webhook reliability problem from CallLog data.

Each cleaned row dispatches CallTerminated with reason='stale_cleanup'
so any open browser tabs collapse the in-flight banner via Echo."
```

---

## Task 8: Deploy infrastructure — supervisor program + install-reverb.sh + nginx + .env.example

**Files:**
- Create: `deploy/supervisor-reverb.conf`
- Create: `deploy/install-reverb.sh`
- Modify: `deploy/nginx.conf`
- Modify: `deploy.sh`

This task ships the operational side of Reverb. No tests — this is server config. Manual verification on the production server.

- [ ] **Step 1: Create the supervisor program template**

Create `deploy/supervisor-reverb.conf` (identical structure to `deploy/supervisor-worker.conf`):

```ini
; BlastIQ Reverb daemon — Supervisor program definition.
;
; Phase 17 dependency. Reverb is a long-lived WebSocket server bound to
; 127.0.0.1:8080. Public traffic enters via HTTPS at /app, nginx proxies
; the WebSocket upgrade to this daemon (see deploy/nginx.conf).
;
; Installation:
;   sudo bash deploy/install-reverb.sh
;
; The script substitutes __PROJECT_PATH__ and __RUN_AS_USER__ into
; /etc/supervisord.d/blastiq-reverb.ini, then reloads supervisor.

[program:blastiq-reverb]
process_name=%(program_name)s
command=php __PROJECT_PATH__/artisan reverb:start --host=127.0.0.1 --port=8080
directory=__PROJECT_PATH__
autostart=true
autorestart=true
user=__RUN_AS_USER__
redirect_stderr=true
stdout_logfile=__PROJECT_PATH__/storage/logs/reverb.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
stopwaitsecs=10
```

- [ ] **Step 2: Create install-reverb.sh**

Create `deploy/install-reverb.sh`. Mirror the existing `deploy/install-supervisor.sh` structure exactly so admins have one consistent pattern. Mark it executable.

```bash
#!/bin/bash
# Install / refresh the BlastIQ Reverb daemon as a supervisord program.
#
# Idempotent — safe to re-run after deploys or config changes.
#
# Usage (from project root, as root or with sudo):
#   sudo bash deploy/install-reverb.sh

set -euo pipefail

if [ -d /etc/supervisord.d ]; then
    SUPERVISOR_DIR=/etc/supervisord.d
    SUPERVISOR_EXT=ini
elif [ -d /etc/supervisor/conf.d ]; then
    SUPERVISOR_DIR=/etc/supervisor/conf.d
    SUPERVISOR_EXT=conf
else
    echo "ERROR: neither /etc/supervisord.d nor /etc/supervisor/conf.d exists." >&2
    exit 1
fi

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
PROJECT_PATH=$(cd "$SCRIPT_DIR/.." && pwd)
SOURCE_CONF="$SCRIPT_DIR/supervisor-reverb.conf"
TARGET_CONF="$SUPERVISOR_DIR/blastiq-reverb.$SUPERVISOR_EXT"

if [ ! -f "$SOURCE_CONF" ]; then
    echo "ERROR: $SOURCE_CONF not found." >&2
    exit 1
fi

RUN_AS_USER=$(stat -c '%U' "$PROJECT_PATH/storage" 2>/dev/null || stat -f '%Su' "$PROJECT_PATH/storage" 2>/dev/null || whoami)

echo "=== BlastIQ Reverb Install ==="
echo "  Project path:    $PROJECT_PATH"
echo "  Run as user:     $RUN_AS_USER"
echo "  Source config:   $SOURCE_CONF"
echo "  Target config:   $TARGET_CONF"
echo ""

echo "[1/4] Rendering config to $TARGET_CONF..."
sed -e "s|__PROJECT_PATH__|$PROJECT_PATH|g" \
    -e "s|__RUN_AS_USER__|$RUN_AS_USER|g" \
    "$SOURCE_CONF" > "$TARGET_CONF"

echo "[2/4] Ensuring storage/logs/ exists..."
mkdir -p "$PROJECT_PATH/storage/logs"
chown "$RUN_AS_USER":"$RUN_AS_USER" "$PROJECT_PATH/storage/logs" 2>/dev/null || true

echo "[3/4] supervisorctl reread + update..."
supervisorctl reread
supervisorctl update

echo "[4/4] Starting (or restarting) blastiq-reverb..."
supervisorctl start blastiq-reverb 2>/dev/null || supervisorctl restart blastiq-reverb

echo ""
echo "=== Done ==="
echo "Status:"
supervisorctl status blastiq-reverb
echo ""
echo "Tail the log:  tail -f $PROJECT_PATH/storage/logs/reverb.log"
```

Then:

```bash
chmod +x deploy/install-reverb.sh
```

- [ ] **Step 3: Append nginx WebSocket location block**

Open `deploy/nginx.conf`. Find the existing `server { ... }` block. Append this `location` block INSIDE the server block (before the closing brace):

```nginx
# Phase 17 — Reverb WebSocket upgrade. Public WSS at /app proxies
# to the local Reverb daemon on 127.0.0.1:8080.
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 60s;
    proxy_send_timeout 60s;
}
```

- [ ] **Step 4: Update deploy.sh to mention install-reverb.sh**

Open `deploy.sh`. Find the existing `[10/11] Restarting queue worker...` step. AFTER that step (before `[11/11]`), insert a guidance comment block:

```bash
# Reverb daemon (Phase 17 — inbound call browser answer).
# First-time setup requires running:
#   sudo bash deploy/install-reverb.sh
# Subsequent deploys: supervisor auto-restarts the daemon if config
# changes. No action needed in this script for steady-state deploys.
echo "[10.5/11] Reverb daemon — first-time setup: 'sudo bash deploy/install-reverb.sh'"
echo "         Steady-state: supervisor manages auto-restart."
```

(Actual restart on deploy is a future improvement; for now, deploy.sh just reminds the operator. Reverb config rarely changes, and supervisor handles process supervision once installed.)

- [ ] **Step 5: Run full suite to confirm nothing broke**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (234 tests, ...)`. No deploy file change affects tests.

- [ ] **Step 6: Commit**

```bash
git add deploy/supervisor-reverb.conf deploy/install-reverb.sh deploy/nginx.conf deploy.sh
git commit -m "feat(call): Reverb supervisor program + install script + nginx WS upgrade

Operational infrastructure for Phase 17. Reverb is a long-lived
WebSocket daemon bound to 127.0.0.1:8080 — supervisor keeps it
running, nginx proxies the public WSS upgrade at /app.

Files:
- deploy/supervisor-reverb.conf — template with __PROJECT_PATH__ and
  __RUN_AS_USER__ placeholders, identical structure to
  supervisor-worker.conf so admins see one consistent pattern.
- deploy/install-reverb.sh — bootstrap script: detect path + user,
  substitute placeholders, write to /etc/supervisord.d/blastiq-reverb.ini,
  supervisorctl reread/update/start. Idempotent.
- deploy/nginx.conf — new 'location /app' block doing
  proxy_pass + Upgrade/Connection headers for WebSocket.
- deploy.sh — reminder comment on first-time setup; supervisor handles
  auto-restart on config changes after install.

First-time prod setup:
  cd /path/to/blastiq && sudo bash deploy/install-reverb.sh
  sudo systemctl reload nginx"
```

---

## Task 9: Final verification + push

**Files:** none

- [ ] **Step 1: Confirm clean working tree**

```bash
git status
```

Expected: only `.claude/` untracked.

- [ ] **Step 2: Run full suite one last time**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (234 tests, ...)`.

- [ ] **Step 3: Inspect commit chain**

```bash
git log --oneline -12
```

Expected, top to bottom:
- Task 8: `feat(call): Reverb supervisor program + install script + nginx WS upgrade`
- Task 7: `feat(call): CleanupStaleCalls artisan command + everyMinute schedule + 4 tests`
- Task 6: `feat(call): IncomingCall Livewire + WebRTC client + RealtimePulse mount`
- Task 5: `feat(call): CallController claim/answer/decline/hangup routes + 6 tests`
- Task 4: `feat(call): InboundCallProcessor persists SDP + fires preAccept + broadcasts CallRinging`
- Task 3: `feat(call): WhatsAppCloudApiService preAcceptCall + acceptCall + 5 tests`
- Task 2: `feat(call): install Reverb + Echo + private user channel auth`
- Task 1: `feat(call): add call_logs claim columns + users.mic_permission_state`
- Plan: `docs: add Phase 17 inbound call browser answer plan`
- Spec: `docs(spec): phase 17 inbound WhatsApp call browser answer`

- [ ] **Step 4: Push to origin**

```bash
git push origin main
```

Expected: `<prior SHA>..<Task 8 SHA>  main -> main`.

- [ ] **Step 5: Production deploy checklist (informational, not part of this commit)**

```
On production server:
1. cd /root/Blast_dplux
2. bash deploy.sh                                 # pulls + composer + npm + migrate + caches
3. sudo bash deploy/install-reverb.sh             # FIRST TIME ONLY — registers Reverb supervisor program
4. Edit /etc/nginx/sites-available/blastiq.conf — apply /app location block from deploy/nginx.conf
5. sudo nginx -t && sudo systemctl reload nginx   # validate + reload nginx
6. supervisorctl status                           # confirm blastiq-reverb RUNNING + blastiq-worker RUNNING
7. tail -f storage/logs/reverb.log &              # watch for connection events
8. Open dashboard in browser, verify Echo connects (check browser console: should NOT see Echo errors)
9. Trigger a real test call, verify Accept/Decline appears, click Accept, talk, verify two-way audio
```

If any step in the production deploy fails, the worst-case is the Phase 17 features don't activate but Phase 14.x (the polled banner + ringtone) keeps working. No regression to existing functionality.

- [ ] **Step 6: Report**

Phase 17 done. Test trajectory:
- Phase 15.1 baseline: 216 tests
- Task 1 (migrations + constants): 216 (no new tests)
- Task 2 (Reverb install + Echo): 216 (no new tests)
- Task 3 (preAcceptCall + acceptCall + 5 tests): 221
- Task 4 (InboundCallProcessor + 3 tests + CallRinging event): 224
- Task 5 (CallController + 6 tests + Claimed/Terminated events): 230
- Task 6 (IncomingCall Livewire + JS): 230 (no new tests — JS-only)
- Task 7 (CleanupStaleCalls + 4 tests): 234
- Task 8 (deploy infra): 234
- Final: **234 tests, all green**

Behavioral changes shipped:
- Inbound calls auto-fire pre_accept against Meta (~50ms) before the agent's UI even shows the banner.
- Assigned agent's browser receives the SDP offer in ~100ms via Reverb push (vs. 0-3s polled).
- Banner shows Accept / Decline with proper color coding.
- Click Accept → microphone prompt → SDP exchange → audio peer connects → agent talks, customer hears.
- Click Decline → Meta terminate, banner clears.
- Mute / Hangup buttons during active call.
- Multiple agent tabs handled cleanly (atomic claim, losing tabs collapse).
- 30-minute stale-call sweep covers webhook reliability gaps.

Deferred (per spec):
- Phase 18 — outbound browser dial.
- Phase 19 — TURN servers, call quality telemetry, call recording.
- Audio device picker — Phase 18 polish.
- Auto-route on ringing timeout — Phase 18+.

Production rollout: see Step 5 checklist. First-time-only Reverb install + nginx config edit; subsequent deploys are normal `bash deploy.sh`.
