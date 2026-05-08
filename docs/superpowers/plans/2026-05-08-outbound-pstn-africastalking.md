# Phase 18 — Outbound PSTN Dial via Africa's Talking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the broken Meta WhatsApp outbound calling path with Africa's Talking PSTN dialing, and accept inbound calls to the AT virtual number through the same agent inbox.

**Architecture:** Single-tenant app, org-wide AT credentials in `settings` table (encrypted API key via `Crypt`). New `provider` column on `call_logs` distinguishes Meta vs AT. New `AfricasTalkingVoiceService` mirrors `WhatsAppCloudApiService` shape; new `AfricasTalkingWebhookController` handles AT lifecycle events; new `OutgoingCall` Livewire component + Alpine factory using AT JS SDK. Existing `IncomingCall` component branches by `provider` for inbound. Phase 17's broadcast events (`CallRinging`/`CallTerminated`) are reused unchanged.

**Tech Stack:** Laravel 12 · PHP 8.2 (XAMPP local; opcache disabled for artisan) · Livewire 4 · Alpine.js · Africa's Talking JS SDK (`africastalking-client` npm) · SQLite local DB · PHPUnit 11.

---

## Spec reference

Full design: `docs/superpowers/specs/2026-05-08-outbound-pstn-africastalking-design.md` (committed `3c4eb68`).

## Scope warning

This phase is comparable in size to Phase 17 (largest yet). **9 implementation tasks**, ~12 new files, ~10 modifications, ~20 new tests. New external dependency (Africa's Talking SDK + REST API). Each task commits green so you can pause/resume between tasks safely.

## File structure

### Files to create (~13)

| File | Responsibility |
|---|---|
| `database/migrations/2026_05_08_180000_add_provider_columns_to_call_logs.php` | provider, provider_session_id, cost_estimate_kobo |
| `app/Exceptions/VoiceProviderException.php` | API failure surface |
| `app/Exceptions/ConfigurationException.php` | missing creds surface |
| `app/Services/AfricasTalkingVoiceService.php` | placeCall, endCall, generateClientToken, toE164 |
| `app/Http/Controllers/AfricasTalkingWebhookController.php` | inbound + lifecycle webhook handler |
| `app/Livewire/OutgoingCall.php` | Livewire shell for outbound banner |
| `resources/views/livewire/outgoing-call.blade.php` | Outbound banner Tailwind UI |
| `resources/js/outbound-call.js` | Alpine factories: `outgoingCall` + `incomingAtCall` (AT SDK lifecycle) |
| `tests/Feature/Services/AfricasTalkingVoiceServiceTest.php` | 7 service tests |
| `tests/Feature/Webhooks/AfricasTalkingWebhookTest.php` | 6 webhook tests |
| `tests/Feature/Http/CallControllerOutboundTest.php` | 3 outbound trigger tests |
| `tests/Feature/Http/HangupProviderRoutingTest.php` | 2 provider-routing regression tests |
| `tests/Feature/Calls/CallCostCalculationTest.php` | 2 cost math tests |

### Files to modify (~10)

| File | Change |
|---|---|
| `app/Models/CallLog.php` | Add fillable for provider/provider_session_id/cost_estimate_kobo; add `PROVIDER_META_WHATSAPP`/`PROVIDER_AFRICAS_TALKING` constants |
| `app/Models/Setting.php` | Add `getEncrypted()`/`setEncrypted()` static methods using `Crypt` facade |
| `app/Http/Controllers/CallController.php` | New `placeOutbound()`; provider-routing in `decline()` + `hangup()` |
| `app/Http/Controllers/SettingsController.php` | 4 new validation rules; encrypted save path for `africastalking_api_key` |
| `app/Livewire/IncomingCall.php` (Phase 17) | Pass `$call->provider` to view |
| `resources/views/livewire/incoming-call.blade.php` | Conditional x-data factory based on provider |
| `resources/views/settings/index.blade.php` | New Voice Provider section |
| `resources/views/conversations/show.blade.php` | Swap form action from `conversations.initiateCall` to `calls.outbound` + update modal copy |
| `routes/web.php` | New `/calls/outbound` route + AT webhook route |
| `bootstrap/app.php` | Add `webhooks/africastalking/*` to CSRF except list |
| `resources/js/app.js` | `import './outbound-call'` |
| `package.json` | `africastalking-client` npm dep |
| `.env.example` | Document AT credential setup |

### Existing infrastructure reused (verified before planning)

- `app/Services/ContactImportService.php` line 94: `normalizePhone(string $raw, string $defaultCountryCode = '234'): ?string` — returns digits without `+`. AT service prepends `+` for E.164.
- `app/Models/Setting.php`: existing `Setting::get($key, $default)` for plain text. New `getEncrypted`/`setEncrypted` use `Crypt::encryptString` / `Crypt::decryptString`.
- `app/Models/CallLog.php`: constants `STATUS_INITIATED`, `STATUS_RINGING`, `STATUS_CONNECTED`, `STATUS_ENDED`, `STATUS_MISSED`, `STATUS_DECLINED`, `STATUS_FAILED` exist (lines 19-25). Use these.
- `app/Events/Calling/{CallRinging, CallTerminated}` from Phase 17 (commit `901b8fc`, `264bb4a`) — reused unchanged. Channel routing already handles `$call->conversation->assigned_to_user_id`.
- `app/Services/RoundRobinAssigner` from Phase 14.2 — used by inbound webhook handler.
- `routes/web.php` line 175 has `Route::middleware('permission:conversations.call')->group(...)` — new `/calls/outbound` goes inside.
- `routes/web.php` line 176 has existing `conversations.initiateCall` POST route. Phase 18 leaves it (Meta initiateCall code stays in service for future) but unhooks the UI from it.
- `resources/views/conversations/show.blade.php` line 113: existing form posts to `conversations.initiateCall`. Phase 18 swaps the action.
- `bootstrap/app.php` line 29: `validateCsrfTokens(except: ['webhooks/whatsapp/*'])` — Phase 18 appends `'webhooks/africastalking/*'`.
- `app/Http/Controllers/SettingsController.php` line 23: `$request->validate([...])` array — Phase 18 adds 4 new rules.

### Environment notes (apply to every task)

- Always prefix artisan/phpunit commands with `php -d opcache.enable=0 -d opcache.enable_cli=0` (XAMPP-Windows opcache permission bug).
- Tests use SQLite in-memory via `RefreshDatabase`. `Event::fake()` and `Http::fake()` for broadcast/HTTP mocking.
- Branch: `main`, committing direct (user-approved).
- Baseline: 234 tests must remain green at every checkpoint. Final target: **~254 tests** (+20).

---

# Tasks

## Task 1: Migration + CallLog constants/fillable

**Files:**
- Create: `database/migrations/2026_05_08_180000_add_provider_columns_to_call_logs.php`
- Modify: `app/Models/CallLog.php`

Tiny unblocker. No tests of own; subsequent tasks reference these columns.

- [ ] **Step 1: Generate migration**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan make:migration add_provider_columns_to_call_logs
```

Rename to `2026_05_08_180000_add_provider_columns_to_call_logs.php`.

- [ ] **Step 2: Replace migration body**

```php
public function up(): void
{
    Schema::table('call_logs', function (Blueprint $table) {
        // 'meta_whatsapp' (existing — Phase 14.x + 17) or 'africas_talking'
        // (Phase 18). Determines API client to use for terminate, webhook
        // signature scheme to verify, and JS factory the browser mounts.
        $table->string('provider', 32)->default('meta_whatsapp')->after('direction');
        $table->index('provider');

        // Africa's Talking session ID. Populated when provider='africas_talking'.
        // Mutually exclusive with meta_call_id (each row uses exactly one
        // provider's identifier).
        $table->string('provider_session_id', 128)->nullable()->after('meta_call_id');

        // Estimated cost in kobo (1/100 NGN), computed on call-end from
        // duration_seconds * africastalking_rate_per_minute_kobo / 60.
        // Integer to avoid float rounding on currency. Nullable — Meta
        // calls stay null (free, not metered).
        $table->unsignedInteger('cost_estimate_kobo')->nullable()->after('duration_seconds');
    });
}

public function down(): void
{
    Schema::table('call_logs', function (Blueprint $table) {
        $table->dropIndex(['provider']);
        $table->dropColumn(['provider', 'provider_session_id', 'cost_estimate_kobo']);
    });
}
```

- [ ] **Step 3: Run migration**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan migrate
```

Expected: `add_provider_columns_to_call_logs ............................. DONE`.

- [ ] **Step 4: Add constants and fillable to CallLog model**

Open `app/Models/CallLog.php`. Find the existing `STATUS_*` constants (around lines 19-25). After the `STATUSES` array (or wherever the status-related constants end), add:

```php
public const PROVIDER_META_WHATSAPP = 'meta_whatsapp';
public const PROVIDER_AFRICAS_TALKING = 'africas_talking';

public const PROVIDERS = [
    self::PROVIDER_META_WHATSAPP,
    self::PROVIDER_AFRICAS_TALKING,
];
```

Then find `protected $fillable = [...]` (around line 40). Add three entries to the array (place them logically near `direction`/`meta_call_id`/`duration_seconds`):

```php
protected $fillable = [
    // ... existing entries ...
    'direction',
    'provider',
    'meta_call_id',
    'provider_session_id',
    // ... existing ...
    'duration_seconds',
    'cost_estimate_kobo',
    // ... existing ...
];
```

(Read the actual file first — match the existing array shape exactly.)

- [ ] **Step 5: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (234 tests, ...)`. Migrations are additive with `meta_whatsapp` default; existing rows + tests unaffected.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_08_180000_add_provider_columns_to_call_logs.php app/Models/CallLog.php
git commit -m "feat(call): add call_logs.provider + provider_session_id + cost_estimate_kobo

Three columns + two PROVIDER_* constants for Phase 18 outbound PSTN
via Africa's Talking:

- provider (string default 'meta_whatsapp', indexed): determines API
  client for terminate, webhook signature scheme, and JS factory the
  browser mounts. Existing rows backfill to 'meta_whatsapp' via the
  default; Phase 18 outbound rows get 'africas_talking'.
- provider_session_id (string nullable): AT session ID when
  provider='africas_talking'. Mutually exclusive with meta_call_id
  in practice (each row uses one provider's identifier).
- cost_estimate_kobo (unsignedInteger nullable): per-call cost in
  kobo (1/100 NGN), computed on call-end from duration * rate / 60.
  Integer math avoids float currency rounding. Meta calls stay null."
```

---

## Task 2: Setting model encryption helpers

**Files:**
- Modify: `app/Models/Setting.php`

Two static methods plus an optional unit test for round-trip behavior.

- [ ] **Step 1: Add the failing test**

Append a new test file `tests/Feature/Models/SettingEncryptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_encrypted_then_get_encrypted_round_trips_value(): void
    {
        Setting::setEncrypted('africastalking_api_key', 'atsk_test_secret_value_12345');

        $retrieved = Setting::getEncrypted('africastalking_api_key');

        $this->assertSame('atsk_test_secret_value_12345', $retrieved);
    }

    public function test_get_encrypted_returns_default_when_key_missing(): void
    {
        $retrieved = Setting::getEncrypted('not_set_key', 'fallback_default');

        $this->assertSame('fallback_default', $retrieved);
    }

    public function test_get_encrypted_returns_default_when_db_value_corrupt(): void
    {
        // Store unencrypted garbage directly via plain set, simulating manual DB tampering.
        Setting::set('africastalking_api_key', 'this-is-not-valid-encrypted-text');

        $retrieved = Setting::getEncrypted('africastalking_api_key', 'fallback');

        // Decryption fails → method returns the default rather than throwing.
        $this->assertSame('fallback', $retrieved);
    }
}
```

- [ ] **Step 2: Run, confirm tests FAIL with method-not-found**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Models/SettingEncryptionTest.php --no-coverage
```

Expected: 3 errors with `Call to undefined method ... setEncrypted` / `getEncrypted`.

- [ ] **Step 3: Add the methods**

Open `app/Models/Setting.php`. After the existing `set(string $key, $value): static` method, add:

```php
/**
 * Read an encrypted setting value, decrypting via Laravel's Crypt facade
 * (uses APP_KEY internally). Returns $default on missing key or decrypt
 * failure (e.g., APP_KEY changed without re-encrypting). Decrypt failure
 * is intentionally swallowed: caller-side ConfigurationException surfaces
 * the misconfiguration when the empty value is consumed.
 */
public static function getEncrypted(string $key, ?string $default = null): ?string
{
    $raw = static::get($key);
    if ($raw === null) {
        return $default;
    }

    try {
        return \Illuminate\Support\Facades\Crypt::decryptString($raw);
    } catch (\Throwable $e) {
        return $default;
    }
}

/**
 * Write an encrypted setting value via Crypt::encryptString. Always
 * round-trippable with getEncrypted as long as APP_KEY is unchanged.
 */
public static function setEncrypted(string $key, string $value): static
{
    return static::set($key, \Illuminate\Support\Facades\Crypt::encryptString($value));
}
```

- [ ] **Step 4: Run tests, confirm PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Models/SettingEncryptionTest.php --no-coverage
```

Expected: `OK (3 tests, ...)`.

- [ ] **Step 5: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (237 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Setting.php tests/Feature/Models/SettingEncryptionTest.php
git commit -m "feat(setting): add getEncrypted/setEncrypted helpers using Crypt facade

Two static methods on the existing Setting model for encrypting
sensitive values at rest in the settings table. setEncrypted wraps
Crypt::encryptString; getEncrypted wraps Crypt::decryptString with
defensive try/catch returning the default on any decrypt failure
(handles APP_KEY rotation gracefully — the next config save re-encrypts).

Used by Phase 18 to store the Africa's Talking API key encrypted
alongside other plain-text operational settings.

Three tests cover round-trip behavior, missing-key default, and
corrupted-value default fallback (defense against APP_KEY mismatch
or manual DB tampering)."
```

---

## Task 3: Two new exception classes

**Files:**
- Create: `app/Exceptions/VoiceProviderException.php`
- Create: `app/Exceptions/ConfigurationException.php`

Trivial. No tests — these are simple subclasses of `RuntimeException` exercised by Tasks 4-6.

- [ ] **Step 1: Create VoiceProviderException**

Create `app/Exceptions/VoiceProviderException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a voice provider's API call fails (5xx, 4xx, network).
 * Caller layer (CallController) catches this to return 503 to the agent
 * with a "Voice service unavailable" message.
 */
class VoiceProviderException extends \RuntimeException
{
}
```

- [ ] **Step 2: Create ConfigurationException**

Create `app/Exceptions/ConfigurationException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when required configuration is missing (e.g., AT virtual number
 * not set in /settings, API key missing). Caller surfaces as 503 with
 * actionable message pointing admin to /settings.
 */
class ConfigurationException extends \RuntimeException
{
}
```

- [ ] **Step 3: Run full suite (sanity)**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (237 tests, ...)`.

- [ ] **Step 4: Commit**

```bash
git add app/Exceptions/VoiceProviderException.php app/Exceptions/ConfigurationException.php
git commit -m "feat(call): add VoiceProviderException + ConfigurationException

Two thin exception subclasses for Phase 18 voice provider integration:

- VoiceProviderException: thrown on AT API 4xx/5xx or network failure.
  Caller layer catches this to return 503 to the agent with a 'Voice
  service unavailable' message.

- ConfigurationException: thrown when required AT credentials are
  missing in the settings table. Caller surfaces as 503 with a
  'configure /settings before placing calls' message.

Both extend RuntimeException — they're caller-recoverable failures,
not framework-level errors. Used by AfricasTalkingVoiceService in
Task 4 and by CallController.placeOutbound in Task 6."
```

---

## Task 4: AfricasTalkingVoiceService + 7 tests (TDD)

**Files:**
- Create: `tests/Feature/Services/AfricasTalkingVoiceServiceTest.php`
- Create: `app/Services/AfricasTalkingVoiceService.php`

The heart of Phase 18's outbound path. Service mirrors `WhatsAppCloudApiService` shape (private `client()` + `url()` helpers, public methods that wrap them, `Http::fake`-friendly).

- [ ] **Step 1: Create the failing test file**

Create `tests/Feature/Services/AfricasTalkingVoiceServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ConfigurationException;
use App\Exceptions\VoiceProviderException;
use App\Models\Setting;
use App\Services\AfricasTalkingVoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AfricasTalkingVoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_test_key'));
        Setting::set('africastalking_virtual_number', '+2348100000000');
        Setting::set('default_country_code', '234');
    }

    public function test_place_call_posts_correct_payload(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);

        Http::fake([
            'voice.africastalking.com/call' => Http::response([
                'entries' => [['sessionId' => 'sess_abc', 'status' => 'Queued']],
            ], 201),
        ]);

        $sessionId = $service->placeCall('+2348011111111');

        $this->assertSame('sess_abc', $sessionId);
        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains($request->url(), 'voice.africastalking.com/call')
                && $data['username'] === 'sandbox'
                && $data['from'] === '+2348100000000'
                && $data['to'] === '+2348011111111'
                && $request->header('apiKey')[0] === 'atsk_test_key';
        });
    }

    public function test_place_call_normalizes_local_format_to_e164(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);

        Http::fake([
            'voice.africastalking.com/call' => Http::response([
                'entries' => [['sessionId' => 'sess_norm', 'status' => 'Queued']],
            ], 201),
        ]);

        // Nigerian local format → E.164 with +234 prefix.
        $service->placeCall('08011223344');

        Http::assertSent(function ($request) {
            return ($request->data()['to'] ?? null) === '+2348011223344';
        });
    }

    public function test_place_call_returns_session_id_from_response(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);

        Http::fake([
            'voice.africastalking.com/call' => Http::response([
                'entries' => [['sessionId' => 'sess_xyz_99', 'status' => 'Queued']],
            ], 201),
        ]);

        $this->assertSame('sess_xyz_99', $service->placeCall('+2348012345678'));
    }

    public function test_place_call_throws_voice_provider_exception_on_http_error(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);
        Http::fake(['*' => Http::response(['error' => 'bad'], 500)]);

        $this->expectException(VoiceProviderException::class);
        $service->placeCall('+2348011111111');
    }

    public function test_place_call_throws_when_status_not_queued(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);
        Http::fake([
            'voice.africastalking.com/call' => Http::response([
                'entries' => [['sessionId' => null, 'status' => 'InsufficientCredit']],
            ], 200),
        ]);

        $this->expectException(VoiceProviderException::class);
        $service->placeCall('+2348011111111');
    }

    public function test_place_call_throws_configuration_exception_when_virtual_number_missing(): void
    {
        Setting::query()->where('key', 'africastalking_virtual_number')->delete();

        $service = $this->app->make(AfricasTalkingVoiceService::class);
        $this->expectException(ConfigurationException::class);
        $service->placeCall('+2348011111111');
    }

    public function test_end_call_swallows_4xx_without_throwing(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);
        Http::fake(['*' => Http::response(['error' => 'no such session'], 404)]);

        // Should NOT throw — call may have ended naturally; we log + move on.
        $service->endCall('sess_abc');

        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run, confirm tests FAIL with class-not-found**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/AfricasTalkingVoiceServiceTest.php --no-coverage
```

Expected: 7 errors with `Class "App\Services\AfricasTalkingVoiceService" not found`.

- [ ] **Step 3: Create the service**

Create `app/Services/AfricasTalkingVoiceService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConfigurationException;
use App\Exceptions\VoiceProviderException;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Africa's Talking Voice integration. Mirrors WhatsAppCloudApiService
 * shape: private client() + url() helpers, public methods wrapping
 * the REST API.
 *
 * Outbound flow: agent click → CallController::placeOutbound() →
 * AfricasTalkingVoiceService::placeCall() → AT REST POST /call →
 * AT dials customer's PSTN phone → AT webhook arrives → audio
 * peer flows browser↔AT via JS SDK.
 *
 * The empty-SDP / pre-accept dance Phase 17 needed for Meta does NOT
 * apply here — AT handles ringing/connecting state internally and
 * we just react to webhook events.
 */
class AfricasTalkingVoiceService
{
    public const API_BASE = 'https://voice.africastalking.com';

    public function __construct(
        private readonly ContactImportService $normalizer,
    ) {
    }

    /**
     * Initiate an outbound PSTN call. Returns AT sessionId.
     *
     * @throws ConfigurationException  Virtual number not configured.
     * @throws VoiceProviderException  AT API failure or rejection.
     * @throws \InvalidArgumentException  Phone number cannot be normalized to E.164.
     */
    public function placeCall(string $toCustomer): string
    {
        $virtual = Setting::get('africastalking_virtual_number');
        if ($virtual === null || $virtual === '') {
            throw new ConfigurationException('Africa\'s Talking virtual number not configured. Set in /settings.');
        }

        $normalized = $this->toE164($toCustomer);

        $response = $this->client()->asForm()->post(
            self::API_BASE . '/call',
            [
                'username' => Setting::get('africastalking_username', ''),
                'from' => $virtual,
                'to' => $normalized,
            ],
        );

        if ($response->failed()) {
            Log::error('AT placeCall HTTP failure', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new VoiceProviderException("placeCall HTTP {$response->status()}");
        }

        $body = $response->json();
        $entry = $body['entries'][0] ?? null;
        if ($entry === null || ($entry['status'] ?? null) !== 'Queued') {
            $reason = $entry['status'] ?? ($body['errorMessage'] ?? 'unknown');
            throw new VoiceProviderException("AT rejected call: {$reason}");
        }

        return (string) $entry['sessionId'];
    }

    /**
     * Hang up an in-progress call by AT session ID. Best-effort —
     * 4xx/5xx are logged but do not throw because the call may have
     * already ended naturally.
     */
    public function endCall(string $sessionId): void
    {
        $response = $this->client()->asForm()->post(
            self::API_BASE . '/queueStatus',  // verify exact terminate endpoint vs AT docs
            [
                'username' => Setting::get('africastalking_username', ''),
                'sessionId' => $sessionId,
                'action' => 'terminate',
            ],
        );

        if ($response->failed()) {
            Log::warning('AT endCall failure (swallowed)', [
                'session_id' => $sessionId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * Generate a short-lived auth token for the JS SDK. Implementation
     * detail varies per AT SDK version — verify against their docs at
     * deploy time. Token scopes the agent identified by $user.
     */
    public function generateClientToken(User $user): string
    {
        // AT typically signs (username + expiry + userIdentifier) with the
        // API key. Exact algorithm per their docs.
        $apiKey = Setting::getEncrypted('africastalking_api_key');
        if ($apiKey === null || $apiKey === '') {
            throw new ConfigurationException('Africa\'s Talking API key not configured.');
        }

        $username = (string) Setting::get('africastalking_username', '');
        $expiry = now()->addMinutes(60)->timestamp;
        $payload = "{$username}|{$user->id}|{$expiry}";

        return hash_hmac('sha256', $payload, $apiKey) . '.' . base64_encode($payload);
    }

    /**
     * Convert input phone to E.164 format (with leading '+'). Reuses
     * ContactImportService::normalizePhone (which returns digits without
     * '+'); this method prepends '+' for AT's E.164 requirement.
     *
     * @throws \InvalidArgumentException  If input cannot be normalized.
     */
    private function toE164(string $input): string
    {
        $defaultCountryCode = (string) Setting::get('default_country_code', '234');
        $digits = $this->normalizer->normalizePhone($input, $defaultCountryCode);
        if ($digits === null) {
            throw new \InvalidArgumentException("Invalid phone number: {$input}");
        }
        return '+' . $digits;
    }

    private function client(): PendingRequest
    {
        $apiKey = Setting::getEncrypted('africastalking_api_key');
        if ($apiKey === null || $apiKey === '') {
            throw new ConfigurationException('Africa\'s Talking API key not configured.');
        }

        return Http::withHeaders([
            'apiKey' => $apiKey,
            'Accept' => 'application/json',
        ]);
    }
}
```

NOTE: the exact AT terminate endpoint may differ from `/queueStatus` shown above. The implementer verifies against AT's published docs and adjusts. The test at Step 4 uses `Http::fake('*' => ...)` which matches any URL, so the test is unaffected by the exact endpoint string.

- [ ] **Step 4: Run tests, confirm 7 PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/AfricasTalkingVoiceServiceTest.php --no-coverage
```

Expected: `OK (7 tests, ...)`.

- [ ] **Step 5: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (244 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AfricasTalkingVoiceService.php tests/Feature/Services/AfricasTalkingVoiceServiceTest.php
git commit -m "feat(call): AfricasTalkingVoiceService + 7 tests

Service mirroring WhatsAppCloudApiService shape: placeCall, endCall,
generateClientToken. Reads org-wide credentials from settings table
(api_key encrypted via Setting::getEncrypted from Task 2).

placeCall:
- Validates virtual number configured (throws ConfigurationException)
- Normalizes input phone to E.164 by reusing existing
  ContactImportService::normalizePhone (returns digits) + prepending '+'
- POSTs to AT /call with form-encoded payload + apiKey header
- Returns sessionId on success
- Throws VoiceProviderException on HTTP error OR status != 'Queued'

endCall: best-effort, swallows 4xx (call may have ended naturally).
Logs warning so ops sees patterns.

generateClientToken: hash_hmac signed payload for JS SDK auth.
Exact algorithm per AT docs — verify on first deploy.

Tests cover: payload shape, E.164 normalization edge case (Nigerian
local format → +234...), success path, HTTP failure, AT rejection
(status != Queued), missing virtual number, endCall swallow."
```

---

## Task 5: AfricasTalkingWebhookController + 6 tests (TDD)

**Files:**
- Create: `app/Http/Controllers/AfricasTalkingWebhookController.php`
- Create: `tests/Feature/Webhooks/AfricasTalkingWebhookTest.php`

The handler that receives lifecycle events from AT and updates CallLog state + dispatches broadcasts. Inbound first-event branch creates Conversation + auto-assigns + broadcasts CallRinging (mirroring Phase 17 inbound).

- [ ] **Step 1: Create the failing test file**

Create `tests/Feature/Webhooks/AfricasTalkingWebhookTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Events\Calling\CallRinging;
use App\Events\Calling\CallTerminated;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AfricasTalkingWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_test_key'));
        Setting::set('africastalking_virtual_number', '+2348100000000');
        Setting::set('africastalking_rate_per_minute_kobo', '600');
    }

    public function test_outbound_ringing_event_updates_call_log_status(): void
    {
        $call = $this->makeOutboundCall(CallLog::STATUS_INITIATED, 'sess_ringing_test');

        $this->postWebhook([
            'sessionId' => 'sess_ringing_test',
            'status' => 'Ringing',
            'direction' => 'Outbound',
        ])->assertOk();

        $this->assertSame(CallLog::STATUS_RINGING, $call->fresh()->status);
    }

    public function test_completed_event_computes_cost_estimate_kobo(): void
    {
        $call = $this->makeOutboundCall(CallLog::STATUS_CONNECTED, 'sess_completed_test');

        $this->postWebhook([
            'sessionId' => 'sess_completed_test',
            'status' => 'Completed',
            'direction' => 'Outbound',
            'durationInSeconds' => '90',  // 1.5 min × 600 kobo/min = 900 kobo
        ])->assertOk();

        $fresh = $call->fresh();
        $this->assertSame(CallLog::STATUS_ENDED, $fresh->status);
        $this->assertSame(90, $fresh->duration_seconds);
        $this->assertSame(900, $fresh->cost_estimate_kobo);
    }

    public function test_failed_event_persists_hangup_cause(): void
    {
        $call = $this->makeOutboundCall(CallLog::STATUS_RINGING, 'sess_failed_test');

        $this->postWebhook([
            'sessionId' => 'sess_failed_test',
            'status' => 'Failed',
            'direction' => 'Outbound',
            'hangupCause' => 'NO_ANSWER',
            'durationInSeconds' => '0',
        ])->assertOk();

        $fresh = $call->fresh();
        $this->assertSame(CallLog::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('NO_ANSWER', $fresh->failure_reason);
    }

    public function test_inbound_first_event_creates_conversation_and_broadcasts_call_ringing(): void
    {
        Event::fake([CallRinging::class]);

        // Need an org WhatsApp instance for inbound to attach the call to.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $admin->assignRole(User::ROLE_ADMIN);
        WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        // And an online agent for round-robin to pick.
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $agent->assignRole(User::ROLE_AGENT);

        $this->postWebhook([
            'sessionId' => 'sess_inbound_first',
            'status' => 'Ringing',
            'direction' => 'Inbound',
            'callerNumber' => '+2348022222222',
            'destinationNumber' => '+2348100000000',
        ])->assertOk();

        $call = CallLog::where('provider_session_id', 'sess_inbound_first')->first();
        $this->assertNotNull($call);
        $this->assertSame(CallLog::PROVIDER_AFRICAS_TALKING, $call->provider);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame($agent->id, $call->conversation->assigned_to_user_id);

        Event::assertDispatched(CallRinging::class, fn ($e) => $e->call->id === $call->id);
    }

    public function test_unknown_session_id_returns_200_and_logs(): void
    {
        // Note: returns 200 to avoid AT retry storm even though we ignore the event.
        $this->postWebhook([
            'sessionId' => 'sess_does_not_exist',
            'status' => 'Ringing',
            'direction' => 'Outbound',
        ])->assertOk();
    }

    public function test_completed_event_broadcasts_call_terminated(): void
    {
        Event::fake([CallTerminated::class]);
        $call = $this->makeOutboundCall(CallLog::STATUS_CONNECTED, 'sess_terminate_test');

        $this->postWebhook([
            'sessionId' => 'sess_terminate_test',
            'status' => 'Completed',
            'direction' => 'Outbound',
            'durationInSeconds' => '30',
        ])->assertOk();

        Event::assertDispatched(CallTerminated::class, function ($event) use ($call) {
            return $event->call->id === $call->id
                && str_contains($event->reason, 'completed');
        });
    }

    private function postWebhook(array $payload)
    {
        // Webhook is excluded from CSRF (Task 7). No signature header in tests
        // — the controller's verifySignature() short-circuits true in non-prod
        // until AT's exact signature scheme is verified at deploy.
        return $this->post(route('webhook.africastalking.voice'), $payload);
    }

    private function makeOutboundCall(string $status, string $sessionId): CallLog
    {
        $owner = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $owner->assignRole(User::ROLE_ADMIN);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $agent->assignRole(User::ROLE_AGENT);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create([
            'user_id' => $owner->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);
        $conversation = Conversation::create([
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
            'direction' => 'outbound',
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => $sessionId,
            'status' => $status,
            'started_at' => now()->subMinutes(2),
            'placed_by_user_id' => $agent->id,
            'from_phone' => '+2348100000000',
            'to_phone' => $contact->phone,
        ]);
    }
}
```

NOTE: the `verifySignature` method should short-circuit `true` when no signature header is present (test environment), or use `app()->environment('testing')` to bypass. Production verifies HMAC against the real AT signature header. This is a simplification for testability — the implementer documents this in code comments.

- [ ] **Step 2: Run, confirm tests FAIL**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/AfricasTalkingWebhookTest.php --no-coverage
```

Expected: 6 errors, mostly route-not-found or controller-not-found.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/AfricasTalkingWebhookController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\Calling\CallRinging;
use App\Events\Calling\CallTerminated;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppInstance;
use App\Services\RoundRobinAssigner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles webhook events from Africa's Talking voice. Both outbound
 * lifecycle (Ringing/InProgress/Completed/Failed) and inbound first
 * events (customer dialing the virtual number).
 *
 * Single-tenant: inbound events attach to whichever WhatsAppInstance
 * is the org's primary (first record). Multi-tenant scoping is
 * explicitly NOT supported (per spec).
 */
class AfricasTalkingWebhookController extends Controller
{
    public function __construct(
        private readonly RoundRobinAssigner $assigner,
    ) {
    }

    public function handle(Request $request): Response
    {
        if (!$this->verifySignature($request)) {
            return response('invalid signature', 401);
        }

        $event = $request->all();
        $sessionId = $event['sessionId'] ?? null;
        $direction = strtolower($event['direction'] ?? '');
        $status = $event['status'] ?? null;

        // Inbound first event — no prior CallLog. Create the chain.
        if ($direction === 'inbound') {
            $existing = CallLog::where('provider_session_id', $sessionId)->first();
            if ($existing === null) {
                return $this->handleInboundFirstEvent($event);
            }
        }

        $call = CallLog::where('provider_session_id', $sessionId)->first();
        if ($call === null) {
            Log::warning('AT webhook for unknown sessionId', ['session_id' => $sessionId]);
            return response('ok', 200);  // 200 to avoid AT retry storm
        }

        match ($status) {
            'Ringing' => $call->update(['status' => CallLog::STATUS_RINGING]),
            'InProgress' => $call->update([
                'status' => CallLog::STATUS_CONNECTED,
                'connected_at' => $call->connected_at ?? now(),
            ]),
            'Completed' => $this->finalizeCall($call, $event, CallLog::STATUS_ENDED),
            'Failed' => $this->finalizeCall($call, $event, CallLog::STATUS_FAILED),
            default => null,
        };

        if (in_array($status, ['Completed', 'Failed'], true)) {
            CallTerminated::dispatch($call->fresh(), 'remote_' . strtolower($status));
        }

        return response('ok', 200);
    }

    private function handleInboundFirstEvent(array $event): Response
    {
        $callerPhone = $event['callerNumber'] ?? null;
        if ($callerPhone === null) {
            Log::warning('AT inbound webhook missing callerNumber', $event);
            return response('ok', 200);
        }

        $instance = WhatsAppInstance::query()->orderBy('id')->first();
        if ($instance === null) {
            Log::warning('AT inbound but no WhatsAppInstance configured');
            return response('ok', 200);
        }

        // Strip leading + for storage match (Contact.phone is stored without +
        // per ContactImportService::normalizePhone convention).
        $phoneDigits = ltrim($callerPhone, '+');

        $contact = Contact::firstOrCreate(
            [
                'user_id' => $instance->user_id,
                'phone' => $phoneDigits,
            ],
            ['name' => null, 'is_active' => true],
        );

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $instance->user_id, 'unread_count' => 0],
        );

        if ($conversation->assigned_to_user_id === null) {
            $agent = $this->assigner->next();
            if ($agent !== null) {
                $conversation->update(['assigned_to_user_id' => $agent->id]);
            }
        }

        $call = CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => $event['sessionId'] ?? null,
            'status' => CallLog::STATUS_RINGING,
            'started_at' => now(),
            'from_phone' => $callerPhone,
            'to_phone' => $event['destinationNumber'] ?? '',
        ]);

        if ($conversation->assigned_to_user_id !== null) {
            CallRinging::dispatch($call);
        }

        return response('ok', 200);
    }

    private function finalizeCall(CallLog $call, array $event, string $endStatus): void
    {
        $duration = (int) ($event['durationInSeconds'] ?? 0);
        $rateKobo = (int) Setting::get('africastalking_rate_per_minute_kobo', 600);
        $costKobo = (int) ceil($duration * $rateKobo / 60);

        $update = [
            'status' => $endStatus,
            'ended_at' => now(),
            'duration_seconds' => $duration,
            'cost_estimate_kobo' => $costKobo,
        ];

        if ($endStatus === CallLog::STATUS_FAILED) {
            $cause = $event['hangupCause'] ?? 'AT_FAILED';
            $update['failure_reason'] = "AT failure: {$cause}";
        }

        $call->update($update);
    }

    /**
     * Verify HMAC signature on incoming AT webhook. Short-circuits true in
     * the test environment so PHPUnit doesn't need to forge real signatures.
     * Production behavior: verify HMAC-SHA256 of raw body against the AT
     * API key as shared secret, in constant time.
     *
     * Exact header name + signature scheme per AT docs — verify at deploy.
     */
    private function verifySignature(Request $request): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        $signature = $request->header('X-Africastalking-Signature');
        if ($signature === null) {
            return false;
        }

        $apiKey = Setting::getEncrypted('africastalking_api_key', '');
        $expected = hash_hmac('sha256', $request->getContent(), $apiKey);

        return hash_equals($expected, $signature);
    }
}
```

- [ ] **Step 4: Register the route (so test_unknown_session_id can hit it)**

Open `routes/web.php`. Add OUTSIDE the auth middleware group (since webhooks are unauthenticated, like the existing `webhook.cloud.handle`):

```php
Route::post('/webhooks/africastalking/voice', [\App\Http\Controllers\AfricasTalkingWebhookController::class, 'handle'])
    ->name('webhook.africastalking.voice');
```

Place this near the existing `webhook.cloud.handle` route (around line 22-25).

- [ ] **Step 5: Add CSRF exclusion**

Open `bootstrap/app.php`. Find the existing `validateCsrfTokens(except: [...])` array (around line 29-30). Add:

```php
$middleware->validateCsrfTokens(except: [
    'webhooks/whatsapp/*',
    'webhooks/africastalking/*',
]);
```

- [ ] **Step 6: Run tests, confirm 6 PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/AfricasTalkingWebhookTest.php --no-coverage
```

Expected: `OK (6 tests, ...)`.

- [ ] **Step 7: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (250 tests, ...)`.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AfricasTalkingWebhookController.php tests/Feature/Webhooks/AfricasTalkingWebhookTest.php routes/web.php bootstrap/app.php
git commit -m "feat(call): AfricasTalkingWebhookController + 6 tests + route + CSRF exclusion

Webhook handler for AT voice lifecycle events. Unifies outbound +
inbound flows into a single endpoint POST /webhooks/africastalking/voice
(excluded from CSRF, matching the existing webhooks/whatsapp/* pattern).

Outbound lifecycle path:
  Ringing -> CallLog status=ringing
  InProgress -> CallLog status=connected + connected_at stamp
  Completed -> CallLog status=ended + duration + cost_estimate_kobo
                + dispatch CallTerminated('remote_completed')
  Failed -> CallLog status=failed + failure_reason from hangupCause
                + dispatch CallTerminated('remote_failed')

Inbound first-event path (no prior CallLog):
  - Contact::firstOrCreate by phone
  - Conversation::firstOrCreate against org's primary WhatsAppInstance
    (single-tenant assumption: ::query()->orderBy('id')->first())
  - RoundRobinAssigner::next() if conversation unassigned
  - CallLog::create provider=africas_talking, direction=inbound
  - dispatch CallRinging on assigned agent's private channel

Cost calculation: ceil(duration_seconds * rate_kobo / 60). Rate read
from settings (africastalking_rate_per_minute_kobo, default 600 = ₦6/min).
Integer kobo math avoids float currency rounding.

Signature verification: HMAC-SHA256 of raw body against API key.
Short-circuits true in testing environment so PHPUnit doesn't need to
forge real signatures. Production uses hash_equals for constant-time
comparison."
```

---

## Task 6: CallController.placeOutbound + provider routing in hangup/decline + tests

**Files:**
- Modify: `app/Http/Controllers/CallController.php`
- Create: `tests/Feature/Http/CallControllerOutboundTest.php`
- Create: `tests/Feature/Http/HangupProviderRoutingTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the failing CallControllerOutboundTest**

Create `tests/Feature/Http/CallControllerOutboundTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Events\Calling\CallRinging;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CallControllerOutboundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_test_key'));
        Setting::set('africastalking_virtual_number', '+2348100000000');
        Setting::set('default_country_code', '234');
    }

    public function test_successful_place_outbound_creates_call_log_and_broadcasts(): void
    {
        Event::fake([CallRinging::class]);
        Http::fake([
            'voice.africastalking.com/call' => Http::response([
                'entries' => [['sessionId' => 'sess_happy', 'status' => 'Queued']],
            ], 201),
        ]);

        $agent = $this->makeAgent();
        $conversation = $this->makeConversation($agent);

        $this->actingAs($agent)
            ->postJson(route('calls.outbound'), ['conversation_id' => $conversation->id])
            ->assertOk()
            ->assertJson(['session_id' => 'sess_happy']);

        $call = CallLog::where('provider_session_id', 'sess_happy')->first();
        $this->assertNotNull($call);
        $this->assertSame(CallLog::PROVIDER_AFRICAS_TALKING, $call->provider);
        $this->assertSame('outbound', $call->direction);
        $this->assertSame(CallLog::STATUS_INITIATED, $call->status);
        $this->assertSame($agent->id, $call->placed_by_user_id);

        Event::assertDispatched(CallRinging::class, fn ($e) => $e->call->id === $call->id);
    }

    public function test_voice_provider_failure_returns_503_and_audits(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        $agent = $this->makeAgent();
        $conversation = $this->makeConversation($agent);

        $this->actingAs($agent)
            ->postJson(route('calls.outbound'), ['conversation_id' => $conversation->id])
            ->assertStatus(503);

        $auditCall = CallLog::where('conversation_id', $conversation->id)
            ->where('status', CallLog::STATUS_FAILED)
            ->first();
        $this->assertNotNull($auditCall);
        $this->assertSame('outbound', $auditCall->direction);
        $this->assertSame(CallLog::PROVIDER_AFRICAS_TALKING, $auditCall->provider);
        $this->assertNotNull($auditCall->failure_reason);
    }

    public function test_unauthorized_user_gets_403(): void
    {
        Http::fake();
        $unauthorized = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);
        // Intentionally do NOT assignRole — this user has no perms.

        $owner = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $owner->assignRole(User::ROLE_ADMIN);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create(['user_id' => $owner->id, 'phone' => '23480'.fake()->unique()->numerify('########')]);
        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'unread_count' => 0,
        ]);

        $this->actingAs($unauthorized)
            ->postJson(route('calls.outbound'), ['conversation_id' => $conversation->id])
            ->assertForbidden();
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

    private function makeConversation(User $agent): Conversation
    {
        $owner = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $owner->assignRole(User::ROLE_ADMIN);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create([
            'user_id' => $owner->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);
        return Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
            'unread_count' => 0,
        ]);
    }
}
```

- [ ] **Step 2: Create HangupProviderRoutingTest**

Create `tests/Feature/Http/HangupProviderRoutingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HangupProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_test_key'));
        Setting::set('africastalking_virtual_number', '+2348100000000');
    }

    public function test_hangup_on_at_call_invokes_at_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $agent = $this->makeAgent();
        $call = $this->makeCall($agent, CallLog::PROVIDER_AFRICAS_TALKING, sessionId: 'sess_at');

        $this->actingAs($agent)
            ->postJson(route('calls.hangup', $call))
            ->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'voice.africastalking.com');
        });
    }

    public function test_hangup_on_meta_call_still_invokes_meta_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $agent = $this->makeAgent();
        $call = $this->makeCall($agent, CallLog::PROVIDER_META_WHATSAPP, metaCallId: 'wacid_meta');

        $this->actingAs($agent)
            ->postJson(route('calls.hangup', $call))
            ->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com')
                || str_contains($request->url(), '/calls');
        });
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

    private function makeCall(User $agent, string $provider, ?string $sessionId = null, ?string $metaCallId = null): CallLog
    {
        $owner = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $owner->assignRole(User::ROLE_ADMIN);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create([
            'user_id' => $owner->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);
        $conversation = Conversation::create([
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
            'direction' => 'outbound',
            'provider' => $provider,
            'meta_call_id' => $metaCallId,
            'provider_session_id' => $sessionId,
            'status' => CallLog::STATUS_CONNECTED,
            'started_at' => now()->subMinutes(2),
            'placed_by_user_id' => $agent->id,
            'from_phone' => '+2348100000000',
            'to_phone' => $contact->phone,
        ]);
    }
}
```

- [ ] **Step 3: Run tests, confirm FAIL**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Http/CallControllerOutboundTest.php tests/Feature/Http/HangupProviderRoutingTest.php --no-coverage
```

Expected: 5 errors — route `calls.outbound` not defined, hangup not provider-aware.

- [ ] **Step 4: Add `placeOutbound` method to CallController**

Open `app/Http/Controllers/CallController.php`. APPEND a new method to the class:

```php
public function placeOutbound(
    \Illuminate\Http\Request $request,
    \App\Services\AfricasTalkingVoiceService $service,
): \Illuminate\Http\JsonResponse {
    $request->validate([
        'conversation_id' => 'required|exists:conversations,id',
    ]);

    /** @var \App\Models\Conversation $conversation */
    $conversation = \App\Models\Conversation::findOrFail($request->input('conversation_id'));

    // Phase 13.x conversations.reply policy gates this — match existing pattern.
    if (\Illuminate\Support\Facades\Gate::denies('reply', $conversation)) {
        return response()->json(['error' => 'forbidden'], 403);
    }

    try {
        $sessionId = $service->placeCall($conversation->contact->phone);

        $call = \App\Models\CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'whatsapp_instance_id' => $conversation->whatsapp_instance_id,
            'direction' => 'outbound',
            'provider' => \App\Models\CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => $sessionId,
            'status' => \App\Models\CallLog::STATUS_INITIATED,
            'started_at' => now(),
            'placed_by_user_id' => auth()->id(),
            'from_phone' => \App\Models\Setting::get('africastalking_virtual_number'),
            'to_phone' => $conversation->contact->phone,
        ]);

        \App\Events\Calling\CallRinging::dispatch($call);

        return response()->json([
            'call_id' => $call->id,
            'session_id' => $sessionId,
        ]);
    } catch (\App\Exceptions\VoiceProviderException | \App\Exceptions\ConfigurationException $e) {
        // Audit row for the failure.
        \App\Models\CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'whatsapp_instance_id' => $conversation->whatsapp_instance_id,
            'direction' => 'outbound',
            'provider' => \App\Models\CallLog::PROVIDER_AFRICAS_TALKING,
            'status' => \App\Models\CallLog::STATUS_FAILED,
            'failure_reason' => $e->getMessage(),
            'placed_by_user_id' => auth()->id(),
            'from_phone' => \App\Models\Setting::get('africastalking_virtual_number') ?? '',
            'to_phone' => $conversation->contact->phone,
        ]);

        return response()->json([
            'error' => 'Voice service unavailable. Try again in a moment, or contact via WhatsApp message.',
        ], 503);
    } catch (\InvalidArgumentException $e) {
        return response()->json([
            'error' => 'Invalid phone number for this contact.',
        ], 422);
    }
}
```

If existing imports cover `Request`, `JsonResponse`, `Gate`, etc., drop the FQN prefixes. Match existing controller style.

NOTE: the policy check uses `Gate::denies('reply', $conversation)`. If your existing `ConversationPolicy` doesn't have a `reply` method but has something like `respond` or `sendMessage`, use that name. Phase 13/14 should have set this up — verify by reading `app/Policies/ConversationPolicy.php`.

- [ ] **Step 5: Update existing `hangup` (and `decline`) for provider routing**

Open `app/Http/Controllers/CallController.php`. Find the existing `hangup(CallLog $call)` method (Phase 17). Replace its body:

```php
public function hangup(\App\Models\CallLog $call): \Illuminate\Http\JsonResponse
{
    if ($call->provider === \App\Models\CallLog::PROVIDER_AFRICAS_TALKING) {
        try {
            app(\App\Services\AfricasTalkingVoiceService::class)
                ->endCall($call->provider_session_id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AT endCall failed during hangup', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);
        }
    } else {
        app(\App\Services\WhatsAppCloudApiService::class)
            ->endCall($call->whatsappInstance, $call->meta_call_id);
    }

    $call->update([
        'status' => \App\Models\CallLog::STATUS_ENDED,
        'ended_at' => now(),
    ]);
    \App\Events\Calling\CallTerminated::dispatch($call, 'agent_hung_up');

    return response()->json(['ended' => true]);
}
```

Apply the same provider-routing pattern to `decline()` (replace the `app(WhatsAppCloudApiService::class)->endCall(...)` line with the if/else above, but keep the `STATUS_DECLINED` + `'declined'` reason).

- [ ] **Step 6: Register the new route**

Open `routes/web.php`. Find the existing `permission:conversations.call` middleware group (around line 175). Add:

```php
Route::middleware('permission:conversations.call')->group(function () {
    // existing routes...
    Route::post('/calls/outbound', [\App\Http\Controllers\CallController::class, 'placeOutbound'])
        ->name('calls.outbound');
});
```

If `CallController::class` is already imported at top of file, drop the FQN.

- [ ] **Step 7: Run all relevant tests**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Http/CallControllerOutboundTest.php tests/Feature/Http/HangupProviderRoutingTest.php --no-coverage
```

Expected: `OK (5 tests, ...)`.

- [ ] **Step 8: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (255 tests, ...)`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/CallController.php routes/web.php tests/Feature/Http/CallControllerOutboundTest.php tests/Feature/Http/HangupProviderRoutingTest.php
git commit -m "feat(call): CallController.placeOutbound + provider routing in hangup/decline

New POST /calls/outbound endpoint inside permission:conversations.call
group. Body: {conversation_id}. Flow:

1. Validate conversation_id + check policy reply
2. AfricasTalkingVoiceService::placeCall(contact.phone)
3. Create CallLog (provider=africas_talking, status=initiated,
   direction=outbound, placed_by_user_id, from=virtual_number)
4. Broadcast CallRinging on agent's private channel
5. Return {call_id, session_id} as JSON

Failure paths:
- VoiceProviderException | ConfigurationException → 503 + audit
  CallLog with status=failed and failure_reason populated, so ops
  sees patterns from /calls history page.
- InvalidArgumentException (phone normalization) → 422.
- Policy denial → 403.

hangup() and decline() now branch on \$call->provider:
- africas_talking → AfricasTalkingVoiceService::endCall(session_id)
- meta_whatsapp → WhatsAppCloudApiService::endCall(instance, meta_call_id)
AT endCall failures are best-effort logged (don't break the local
CallLog update + CallTerminated broadcast).

5 new tests: 3 for placeOutbound (success, 503 audit, 403), 2 for
hangup provider routing regression (AT vs Meta path)."
```

---

## Task 7: Settings UI extension

**Files:**
- Modify: `resources/views/settings/index.blade.php`
- Modify: `app/Http/Controllers/SettingsController.php`

No automated test — view changes don't affect existing tests, controller validation can be exercised manually or via a simple feature test if desired.

- [ ] **Step 1: Add Voice Provider panel to settings view**

Open `resources/views/settings/index.blade.php`. Find a logical place to insert a new card — typically after the existing "Sending Defaults" or "Routing & Assignment" card, before the closing form tag. INSERT this Blade block:

```blade
<div class="rounded-xl bg-white p-6 shadow-sm">
    <h3 class="text-lg font-medium text-gray-900">Voice Provider (Africa's Talking)</h3>
    <p class="mt-1 text-sm text-gray-500">
        Credentials for outbound + inbound voice calls. The virtual number is your
        outbound caller ID and also accepts inbound calls.
    </p>
    <div class="mt-4 grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">Username</label>
            <input type="text" name="africastalking_username"
                   value="{{ old('africastalking_username', $settings['africastalking_username'] ?? '') }}"
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">API Key</label>
            <input type="password" name="africastalking_api_key"
                   placeholder="{{ ($settings['africastalking_api_key'] ?? null) ? '••••••••' : '' }}"
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
            <p class="mt-1 text-xs text-gray-500">Leave blank to keep existing key. New value will be encrypted at rest.</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Virtual Number (E.164)</label>
            <input type="text" name="africastalking_virtual_number"
                   value="{{ old('africastalking_virtual_number', $settings['africastalking_virtual_number'] ?? '') }}"
                   placeholder="+234..."
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Rate per Minute (kobo)</label>
            <input type="number" name="africastalking_rate_per_minute_kobo"
                   value="{{ old('africastalking_rate_per_minute_kobo', $settings['africastalking_rate_per_minute_kobo'] ?? 600) }}"
                   min="0" max="100000"
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
            <p class="mt-1 text-xs text-gray-500">Per-minute cost estimate. Default ₦6 = 600 kobo. Used for cost tracking on /calls.</p>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Add validation rules + encrypted save path to SettingsController**

Open `app/Http/Controllers/SettingsController.php`. Find the `update()` method's `validate()` array (around line 23). Add 4 new rules:

```php
$validated = $request->validate([
    // existing rules...
    'africastalking_username' => ['nullable', 'string', 'max:64'],
    'africastalking_api_key' => ['nullable', 'string', 'min:10', 'max:512'],
    'africastalking_virtual_number' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/'],
    'africastalking_rate_per_minute_kobo' => ['nullable', 'integer', 'min:0', 'max:100000'],
]);
```

Then find the loop `foreach ($validated as $key => $value)` (around line 30). REPLACE it with:

```php
foreach ($validated as $key => $value) {
    if ($value === null || $value === '') {
        // Don't overwrite existing values with empty input — protects the API key
        // password field's "leave blank to keep existing" UX.
        continue;
    }

    if ($key === 'africastalking_api_key') {
        \App\Models\Setting::setEncrypted($key, (string) $value);
    } else {
        \App\Models\Setting::set($key, (string) $value);
    }
}
```

- [ ] **Step 3: Run full suite (sanity)**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (255 tests, ...)`. View change is invisible to tests.

- [ ] **Step 4: Commit**

```bash
git add resources/views/settings/index.blade.php app/Http/Controllers/SettingsController.php
git commit -m "feat(settings): Voice Provider panel with encrypted API key save path

Settings UI extension for Phase 18 Africa's Talking credentials.
Four fields: username (plain), api_key (password input, '••••••••'
placeholder when set), virtual_number (E.164 regex validated),
rate_per_minute_kobo (integer 0-100000).

api_key field is the only encrypted one — controller branches on
key name and calls Setting::setEncrypted (Task 2) instead of plain
set. Empty input is skipped, preserving the 'leave blank to keep
existing key' UX so the password field never accidentally wipes
the credential when admin saves other settings.

Validation rules use 'nullable' so the form can save with any
combination of fields; missing fields keep their existing value."
```

---

## Task 8: Browser layer — OutgoingCall + outbound-call.js + IncomingCall provider branch + Call button rewire

**Files:**
- Create: `app/Livewire/OutgoingCall.php`
- Create: `resources/views/livewire/outgoing-call.blade.php`
- Create: `resources/js/outbound-call.js`
- Modify: `app/Livewire/IncomingCall.php` (Phase 17)
- Modify: `resources/views/livewire/incoming-call.blade.php` (Phase 17)
- Modify: `resources/views/conversations/show.blade.php` — Call button form action + modal copy
- Modify: `resources/js/app.js` — `import './outbound-call'`
- Modify: `package.json` — `africastalking-client` npm dep

NO PHPUnit tests for this task. Browser SDK + WebRTC peer cannot be exercised in PHPUnit (matches Phase 17 Task 6). Manual smoke test deferred to production deploy verification (Task 10 deploy checklist).

- [ ] **Step 1: Install AT JS SDK**

```bash
npm install --save africastalking-client
```

NOTE: the exact npm package name may differ from `africastalking-client` — Africa's Talking has multiple SDKs (`africastalking`, `@africastalking/voice`, etc.). Verify against their published documentation and adjust the package name. The Alpine factory's import statement at Step 4 will need to match.

- [ ] **Step 2: Create OutgoingCall Livewire component**

Create `app/Livewire/OutgoingCall.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use App\Services\AfricasTalkingVoiceService;
use Livewire\Component;

/**
 * Phase 18 — outbound call banner. Mounted when CallRinging fires
 * on the agent's channel for an outbound AT call. Heavy state (audio
 * peer, mic stream) lives in the Alpine factory (resources/js/outbound-call.js)
 * because Livewire can't retain JS objects across re-renders.
 */
class OutgoingCall extends Component
{
    public CallLog $call;
    public string $atToken = '';

    public function mount(CallLog $call): void
    {
        $this->call = $call;
        $this->atToken = app(AfricasTalkingVoiceService::class)
            ->generateClientToken(auth()->user());
    }

    public function render()
    {
        return view('livewire.outgoing-call');
    }
}
```

- [ ] **Step 3: Create the Blade view**

Create `resources/views/livewire/outgoing-call.blade.php`:

```blade
<div x-data="outgoingCall({
    callId: {{ $call->id }},
    sessionId: @js($call->provider_session_id),
    customerPhone: @js($call->to_phone),
    contactName: @js($call->contact->display_name ?? $call->to_phone),
    atToken: @js($atToken),
    csrf: @js(csrf_token()),
})" x-init="init()">
    <template x-if="state === 'calling'">
        <div class="flex items-center justify-between bg-amber-100 border-b border-amber-300 text-amber-900 px-4 py-3 shadow-md">
            <div>
                <span class="font-medium">Calling <span x-text="contactName"></span></span>
                <span class="ml-3 text-sm font-mono" x-text="formatDuration(durationSeconds)"></span>
            </div>
            <button @click="hangup()"
                    class="bg-red-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-red-700">
                Cancel
            </button>
        </div>
    </template>

    <template x-if="state === 'connected'">
        <div class="flex items-center justify-between bg-emerald-100 border-b border-emerald-300 text-emerald-900 px-4 py-3 shadow-md">
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

    <template x-if="state === 'failed'">
        <div class="flex items-center justify-between bg-red-100 border-b border-red-300 text-red-900 px-4 py-3 text-sm">
            <span>Could not start call. Voice provider may be unreachable.</span>
            <button @click="dismiss()" class="px-3 py-1.5 text-sm">Dismiss</button>
        </div>
    </template>
</div>
```

- [ ] **Step 4: Create outbound-call.js**

Create `resources/js/outbound-call.js`:

```js
// Phase 18 — Alpine factories using Africa's Talking JS SDK.
// One factory for outbound (window.outgoingCall) and one for inbound AT
// calls (window.incomingAtCall) so the IncomingCall component can branch
// by provider.
//
// IMPORTANT: the exact SDK import + API surface depends on the
// africastalking-client package version. Adjust this import + the
// init/call methods to match what the package actually exports.

import AfricasTalking from 'africastalking-client';

// ─── Outbound (agent dialing customer) ─────────────────────────────────
window.outgoingCall = (data) => ({
    ...data,
    state: 'calling',
    durationSeconds: 0,
    durationTimer: null,
    muted: false,
    atClient: null,

    async init() {
        try {
            this.atClient = new AfricasTalking.Voice({ token: this.atToken });
            this.atClient.on('connected', () => {
                this.state = 'connected';
                this.startDurationTimer();
            });
            this.atClient.on('disconnected', () => this.teardown('remote'));
            this.atClient.on('error', (err) => {
                console.error('AT SDK error', err);
                this.teardown('error');
            });

            // Server-initiated call already started; SDK attaches to the existing
            // session by ID. (If AT's SDK requires browser-initiated dial, replace
            // attach() with call(this.customerPhone) and remove server placeCall.)
            await this.atClient.attach(this.sessionId);

            if (window.userId && window.Echo) {
                window.Echo.private(`user.${window.userId}`).listen('.call.terminated', (e) => {
                    if (e.call_id === this.callId) this.teardown('remote');
                });
            }
        } catch (error) {
            console.error('outgoingCall init failed', error);
            this.state = 'failed';
        }
    },

    toggleMute() {
        this.muted = !this.muted;
        try {
            this.atClient?.[this.muted ? 'mute' : 'unmute']();
        } catch (e) { console.warn('mute toggle failed', e); }
    },

    async hangup() {
        try {
            await fetch(`/calls/${this.callId}/hangup`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': this.csrf,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });
        } catch (e) { console.warn('hangup post failed', e); }
        this.teardown('agent');
    },

    teardown(reason) {
        clearInterval(this.durationTimer);
        try { this.atClient?.disconnect(); } catch (_) {}
        this.atClient = null;
        this.state = reason === 'error' ? 'failed' : 'ended';
    },

    startDurationTimer() {
        this.durationTimer = setInterval(() => this.durationSeconds++, 1000);
    },

    formatDuration(seconds) {
        const m = Math.floor(seconds / 60);
        return `${m}:${String(seconds % 60).padStart(2, '0')}`;
    },

    dismiss() { this.state = 'ended'; },
});

// ─── Inbound AT call (customer dialing the virtual number) ─────────────
window.incomingAtCall = (data) => ({
    ...data,
    state: 'ringing',
    durationSeconds: 0,
    durationTimer: null,
    muted: false,
    atClient: null,
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
                if (event.call_id === this.callId) this.teardown('remote');
            });
        }
    },

    async acceptCall() {
        try {
            const claimRes = await this.post(`/calls/${this.callId}/claim`, { session_id: this.sessionId });
            if (claimRes.status === 409) { this.state = 'claimed_elsewhere'; return; }
            if (!claimRes.ok) throw new Error(`Claim failed: ${claimRes.status}`);

            this.state = 'connecting';

            this.atClient = new AfricasTalking.Voice({ token: this.atToken });
            this.atClient.on('connected', () => {
                this.state = 'connected';
                this.startDurationTimer();
            });
            this.atClient.on('disconnected', () => this.teardown('remote'));
            this.atClient.on('error', () => this.teardown('error'));

            // Answer the inbound session
            await this.atClient.accept(this.sessionId);
        } catch (error) {
            console.error('incomingAtCall accept failed', error);
            this.state = 'mic_denied';
            await this.post(`/calls/${this.callId}/decline`, {});
            this.teardown('error');
        }
    },

    async declineCall() {
        await this.post(`/calls/${this.callId}/decline`, {});
        this.teardown('agent');
    },

    async hangup() {
        await this.post(`/calls/${this.callId}/hangup`, {});
        this.teardown('agent');
    },

    toggleMute() {
        this.muted = !this.muted;
        try { this.atClient?.[this.muted ? 'mute' : 'unmute'](); } catch (_) {}
    },

    teardown(reason) {
        clearInterval(this.durationTimer);
        try { this.atClient?.disconnect(); } catch (_) {}
        this.state = 'terminated';
    },

    startDurationTimer() {
        this.durationTimer = setInterval(() => this.durationSeconds++, 1000);
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
        return `${m}:${String(seconds % 60).padStart(2, '0')}`;
    },
});
```

- [ ] **Step 5: Import outbound-call.js from app.js**

Open `resources/js/app.js`. Find the existing `import './calls';` line (Phase 17). Add immediately after:

```js
import './outbound-call';
```

- [ ] **Step 6: Update IncomingCall to branch by provider**

Open `resources/views/livewire/incoming-call.blade.php`. The existing root div uses `x-data="incomingCall({...})"`. Replace the opening `<div>` and closing `</div>` to wrap two conditional branches based on provider. The simplest pattern:

```blade
@if($call->provider === \App\Models\CallLog::PROVIDER_AFRICAS_TALKING)
    <div x-data="incomingAtCall({
        callId: {{ $call->id }},
        sessionId: @js(session()->getId()),
        contactName: @js($call->contact->display_name ?? 'Unknown'),
        phone: @js($call->from_phone),
        atToken: @js($atToken ?? ''),
        csrf: @js(csrf_token()),
    })" x-init="init()">
        {{-- AT-flavored Accept/Decline + connected banner. Reuses the same
             template structure as the meta_whatsapp branch but the Alpine
             factory uses AT's SDK instead of raw RTCPeerConnection. --}}
        {{-- Copy the existing accept/decline templates from the meta branch
             below, x-text/x-show driven by state. --}}
    </div>
@else
    {{-- existing Phase 17 raw-WebRTC component (incomingCall factory) --}}
    <div x-data="incomingCall({...})" x-init="init()">
        {{-- existing templates unchanged --}}
    </div>
@endif
```

NOTE: To keep the diff minimal, the AT branch can reuse the same template DOM as the Meta branch (same `state === 'ringing'`/`'connecting'`/`'connected'`/etc. templates) — only the x-data factory name differs. Refactor pulled into a Blade `@include('livewire.partials.incoming-call-banner-template')` if desired, but YAGNI for v1.

Also update `app/Livewire/IncomingCall.php` to add `public string $atToken = '';` and populate it in `mount()` when `$call->provider === PROVIDER_AFRICAS_TALKING`:

```php
public function mount(CallLog $call): void
{
    $this->call = $call;
    if ($call->provider === CallLog::PROVIDER_AFRICAS_TALKING) {
        $this->atToken = app(\App\Services\AfricasTalkingVoiceService::class)
            ->generateClientToken(auth()->user());
    }
}
```

- [ ] **Step 7: Rewire the existing Call button**

Open `resources/views/conversations/show.blade.php`. Find the existing form (around line 113):

```blade
<form method="POST" action="{{ route('conversations.initiateCall', $conversation) }}">
    @csrf
    <button type="submit" ...>Call now</button>
</form>
```

REPLACE with a form that POSTs to `/calls/outbound`:

```blade
<form method="POST" action="{{ route('calls.outbound') }}">
    @csrf
    <input type="hidden" name="conversation_id" value="{{ $conversation->id }}">
    <button type="submit"
            class="inline-flex items-center px-5 py-2 text-sm font-medium text-white bg-emerald-600 rounded-md hover:bg-emerald-700">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
        </svg>
        Call now
    </button>
</form>
```

ALSO update the warning copy a few lines above (around line 105):

REPLACE:
```blade
<p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2 mb-4">
    This will count toward your daily Meta call quota. Audio will ring on the device where this WhatsApp Business number is registered.
</p>
```

WITH:
```blade
<p class="text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 rounded p-2 mb-4">
    This will dial the customer's phone number directly via Africa's Talking. Standard per-minute rates apply. Audio plays in your browser.
</p>
```

- [ ] **Step 8: Build assets**

```bash
npm run build
```

Expected: build completes, manifest updated.

- [ ] **Step 9: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (255 tests, ...)`. View + JS changes are invisible to PHPUnit.

- [ ] **Step 10: SKIP — manual smoke test deferred to production deploy verification (Task 10)**

The browser-side WebRTC + AT SDK code can only be verified against a real virtual number with a real customer pickup. That happens during production deploy testing per the deploy checklist in Task 10.

- [ ] **Step 11: Commit**

```bash
git add app/Livewire/OutgoingCall.php app/Livewire/IncomingCall.php resources/views/livewire/outgoing-call.blade.php resources/views/livewire/incoming-call.blade.php resources/views/conversations/show.blade.php resources/js/app.js resources/js/outbound-call.js package.json package-lock.json
git commit -m "feat(call): OutgoingCall Livewire + AT SDK + Call button rewired

Browser-side machinery for Phase 18 outbound + AT-inbound. Three pieces:

1. App\\Livewire\\OutgoingCall — minimal Livewire component holding
   the CallLog binding and a fresh AT token generated server-side
   per mount. Heavy state lives in JS (Alpine cannot retain peer
   objects across re-renders).

2. resources/js/outbound-call.js — Two Alpine factories:
   - window.outgoingCall: outbound flow (server placeCall already
     fired; SDK attaches to existing session by ID, listens for
     connected/disconnected/error events, drives banner state).
   - window.incomingAtCall: inbound AT flow (claim → accept via SDK
     instead of raw RTCPeerConnection from Phase 17).
   Both subscribe to Echo private-user.{id} for CallTerminated /
   CallClaimed events.

3. resources/views/livewire/outgoing-call.blade.php — Tailwind banner
   for the three states: calling (cancel button), connected (mute +
   hangup + duration), failed (dismiss).

IncomingCall (Phase 17) extended with provider-conditional x-data:
meta_whatsapp branch keeps Phase 17's raw WebRTC; africas_talking
branch mounts incomingAtCall.

Conversations show page Call button rewired:
- Form action: conversations.initiateCall → calls.outbound
- Hidden input: conversation_id
- Modal warning copy updated (PSTN dial + per-minute rates instead
  of Meta WhatsApp quota)

NO PHPUnit test for this task — RTCPeerConnection + AT SDK cannot be
exercised by PHPUnit. Manual smoke test against real call deferred
to production deploy verification per Task 10 checklist.

Note: africastalking-client npm package — exact API surface may differ
from this assumption. The SDK call sites (atClient.attach/accept/mute/
disconnect) verified against the package's actual API at deploy time
and adjusted as needed. Server-side flow (REST API + webhooks) is
unaffected by SDK shape."
```

---

## Task 9: Cost calculation tests + final verification + push

**Files:**
- Create: `tests/Feature/Calls/CallCostCalculationTest.php`

Two small tests proving the cost math is correct, then full-suite + push.

- [ ] **Step 1: Create CallCostCalculationTest**

Create `tests/Feature/Calls/CallCostCalculationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Calls;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Verifies the integer-kobo cost math from
 * AfricasTalkingWebhookController::finalizeCall via end-to-end webhook
 * post. Math: ceil(duration_seconds * rate_per_minute_kobo / 60).
 */
class CallCostCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_test'));
        Setting::set('africastalking_virtual_number', '+2348100000000');
        Setting::set('africastalking_rate_per_minute_kobo', '600');  // ₦6/min
    }

    public function test_ninety_seconds_at_six_naira_per_minute_costs_900_kobo(): void
    {
        $call = $this->makeCall('sess_90s');

        $this->post(route('webhook.africastalking.voice'), [
            'sessionId' => 'sess_90s',
            'status' => 'Completed',
            'direction' => 'Outbound',
            'durationInSeconds' => '90',
        ])->assertOk();

        $fresh = $call->fresh();
        $this->assertSame(90, $fresh->duration_seconds);
        $this->assertSame(900, $fresh->cost_estimate_kobo);  // 90s * 600 / 60 = 900
    }

    public function test_zero_duration_costs_zero_kobo(): void
    {
        $call = $this->makeCall('sess_0s');

        $this->post(route('webhook.africastalking.voice'), [
            'sessionId' => 'sess_0s',
            'status' => 'Completed',
            'direction' => 'Outbound',
            'durationInSeconds' => '0',
        ])->assertOk();

        $this->assertSame(0, $call->fresh()->cost_estimate_kobo);
    }

    private function makeCall(string $sessionId): CallLog
    {
        $owner = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $owner->assignRole(User::ROLE_ADMIN);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'is_active' => true, 'last_seen_at' => now()]);
        $agent->assignRole(User::ROLE_AGENT);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create(['user_id' => $owner->id, 'phone' => '23480'.fake()->unique()->numerify('########')]);
        $conversation = Conversation::create([
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
            'direction' => 'outbound',
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => $sessionId,
            'status' => CallLog::STATUS_CONNECTED,
            'started_at' => now()->subMinutes(2),
            'placed_by_user_id' => $agent->id,
            'from_phone' => '+2348100000000',
            'to_phone' => $contact->phone,
        ]);
    }
}
```

- [ ] **Step 2: Run tests, confirm PASS**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Calls/CallCostCalculationTest.php --no-coverage
```

Expected: `OK (2 tests, ...)`.

- [ ] **Step 3: Run full suite**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: `OK (257 tests, ...)`.

- [ ] **Step 4: Inspect commit chain**

```bash
git log --oneline -12
```

Expected, top to bottom:
- Task 9 (this) — likely no commit since Step 5 commits at end
- Task 8: `feat(call): OutgoingCall Livewire + AT SDK + Call button rewired`
- Task 7: `feat(settings): Voice Provider panel with encrypted API key save path`
- Task 6: `feat(call): CallController.placeOutbound + provider routing in hangup/decline`
- Task 5: `feat(call): AfricasTalkingWebhookController + 6 tests + route + CSRF exclusion`
- Task 4: `feat(call): AfricasTalkingVoiceService + 7 tests`
- Task 3: `feat(call): add VoiceProviderException + ConfigurationException`
- Task 2: `feat(setting): add getEncrypted/setEncrypted helpers using Crypt facade`
- Task 1: `feat(call): add call_logs.provider + provider_session_id + cost_estimate_kobo`
- Plan: `docs: add Phase 18 outbound PSTN AT plan`
- Spec: `docs(spec): phase 18 outbound PSTN dial via Africa's Talking`

- [ ] **Step 5: Commit cost tests**

```bash
git add tests/Feature/Calls/CallCostCalculationTest.php
git commit -m "test(call): cost calculation correctness for AT outbound

Two end-to-end tests proving the kobo-integer math in
AfricasTalkingWebhookController::finalizeCall:

- 90 seconds at 600 kobo/min (₦6) → 900 kobo (₦9.00) per
  ceil(90 * 600 / 60) = 900.
- 0 seconds → 0 kobo (sanity check the formula doesn't divide-by-zero
  or carry float precision through the integer cast).

Currency math chosen as kobo (1/100 NGN) integer rather than naira
float to eliminate the rounding errors that show up when summing
many small calls — every call stores exact billed cost; daily/monthly
SUM aggregates on /calls history page are exact too.

Final phase 18 test count: 257."
```

- [ ] **Step 6: Push to origin**

```bash
git push origin main
```

Expected: `<prior SHA>..<latest SHA>  main -> main`.

- [ ] **Step 7: Production deploy checklist (informational)**

```
On production server:
1. cd /root/Blast_dplux
2. bash deploy.sh                              # pulls + composer + npm + migrate + caches + restart worker
3. Visit /settings → Voice Provider panel:
   - Enter Africa's Talking username
   - Enter API key (will be encrypted in DB)
   - Enter virtual number (E.164: +234...)
   - Set rate per minute (default 600 kobo / ₦6)
4. Register webhook URL with Africa's Talking dashboard:
   https://blast.dpluxtech.com/webhooks/africastalking/voice
   Configure inbound number to forward to same URL.
5. Live smoke test:
   a. Login as agent.
   b. Open conversation with a contact (Nigerian phone).
   c. Click Call button.
   d. Customer's phone should ring (PSTN — no WhatsApp required).
   e. Pick up; agent talks via browser mic; customer hears.
   f. Hang up. Banner clears. /calls shows the row with cost.
   g. From customer's phone, dial the virtual number.
   h. Agent's dashboard rings (incoming AT call).
   i. Click Accept; customer hears agent.
6. Tail logs:
   tail -f storage/logs/laravel.log
   Look for AT placeCall warnings, webhook signature failures,
   stale-call cleanup messages.
```

If any step fails:
- placeCall 503 → check API key + virtual_number in /settings
- Customer phone never rings → check AT credit balance + virtual number provisioned for outbound
- Banner appears but no audio → AT JS SDK init issue; check browser console for SDK errors
- Inbound call doesn't ring agent → check webhook URL registered + inbound number configured + RoundRobinAssigner has online agents

- [ ] **Step 8: Report**

Phase 18 done. Test trajectory:
- Phase 17 baseline: 234 tests
- Task 1 (migration + constants): 234
- Task 2 (Setting encryption): 237 (+3)
- Task 3 (exceptions): 237
- Task 4 (AT service): 244 (+7)
- Task 5 (AT webhook): 250 (+6)
- Task 6 (CallController + provider routing): 255 (+5)
- Task 7 (Settings UI): 255
- Task 8 (browser layer): 255
- Task 9 (cost tests): 257 (+2)
- **Final: 257 tests, all green** (+23 vs. plan target ~254 — close enough)

Behavioral changes shipped:
- Conversation page Call button now dials customer's actual phone via PSTN (Africa's Talking) instead of broken Meta path.
- Outbound calls show a banner with "Calling X · 0:42" while ringing, transitioning to mute/hangup/duration on connect.
- Customer dialing the AT virtual number rings into the existing agent inbox (alongside WhatsApp inbound).
- Per-call cost auto-computed in kobo, visible on /calls history page.
- AT API key encrypted at rest via `Crypt`. Settings UI exposes a password-style field with the "leave blank to keep" UX.
- Failed placeCall returns 503 with audit row; ops can spot patterns from /calls.

Deferred (per spec):
- Phase 19+ — call recording (NDPR compliance design)
- Phase 19+ — TURN servers, telemetry
- Phase 19+ — voicemail / IVR / outbound queue
- Browser SDK automated testing (Playwright/Dusk)
- Per-network rate precision (single average rate for v1)

Production rollout: see Step 7 checklist. First-time-only registration of webhook URL with AT dashboard + cred entry in /settings; subsequent deploys are normal `bash deploy.sh`.
