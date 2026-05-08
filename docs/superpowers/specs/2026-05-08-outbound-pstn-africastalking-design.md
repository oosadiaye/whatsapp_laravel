# Phase 18 — Outbound PSTN Dial + Inbound Virtual Number via Africa's Talking Design

**Date:** 2026-05-08
**Status:** Approved
**Builds on:** Phase 14.x (call infrastructure) + Phase 17 (inbound WhatsApp browser answer)

## Summary

Replace the existing (broken) Meta WhatsApp outbound calling path with Africa's Talking PSTN dialing. When an agent clicks the Call button on a conversation, the server normalizes the customer's phone to E.164, hits Africa's Talking's REST API (`POST /call`) using the org-wide virtual number as caller ID, and Africa's Talking dials the customer's phone over PSTN. When the customer picks up, audio flows browser↔Africa's Talking via their JavaScript Voice SDK. Same mute/hangup/duration UX as Phase 17 inbound.

The same virtual number also accepts inbound calls — customers calling back the number ring through to the existing `RoundRobinAssigner` + `IncomingCall` flow, indistinguishable from a WhatsApp inbound call from the agent's perspective.

**Single-tenant constraint** is baked in: one Africa's Talking account, one virtual number, credentials in the existing `settings` table. No multi-tenant isolation work.

## Goals

1. Outbound voice calling that actually reaches customers — without Meta's permission-grant friction, daily caps, or audio-routing-to-mobile-app problem.
2. Reuse Phase 17's data model, broadcast events, and UX shell. Add Africa's Talking as a second `provider` value rather than a parallel system.
3. Single inbox: WhatsApp inbound + AT virtual number inbound + outbound all flow through one `IncomingCall`/`OutgoingCall` UI.
4. Tight scope: ~12 new files, ~20 PHPUnit tests, ships in days vs. weeks.

## Non-goals (deferred)

- **Recording** → Phase 19+ when NDPR compliance + policy is designed
- **Real-time cost meter on banner** → wrong incentive (rushes agents); never planned
- **Per-network rate precision** (MTN vs Airtel vs Glo separate rates) → single average rate for v1
- **Voicemail / IVR for missed calls** → Phase 19+ if needed
- **Outbound queue / scheduled callbacks** → Phase 19+ feature
- **Multi-tenant isolation** → never (single-tenant assumption baked in)
- **Provider failover** (AT → Meta → Twilio) → too complex; explicit "refuse with 503" path chosen
- **Browser-side automated testing (Playwright/Dusk)** → Phase 19+
- **Re-enabling Meta outbound** with full permission UX → far future if cost ever motivates

## Brainstorming decisions reference

| Q | Decision |
|---|---|
| 1 Configuration scope | Org-wide single account in `settings` table |
| 2 Trigger UX | Replace existing Call button; Meta `initiateCall` stays in service for future |
| 3 Browser audio | Africa's Talking JS SDK (not raw WebRTC) |
| 4 Recording | None in Phase 18 |
| 5 Cost visibility | Duration in banner, cost on call-history page |
| 6 Fallback | Refuse with 503 + status=failed audit row |
| 7 Caller ID | Single org-wide virtual number |
| 8 Inbound to virtual number | Yes — routes through existing flow |
| 9 API key storage | Encrypted via `Crypt` in `settings` table |
| 10 Phone normalization | Inside `AfricasTalkingVoiceService::placeCall()` |
| 11 Test strategy | Server-side comprehensive (PHPUnit), browser manual |

## Architecture

```
OUTBOUND
┌─────────────────────────────────────────────────────────────────┐
│ 1. Agent clicks Call on conversation page                       │
│    POST /calls/outbound { conversation_id }                     │
└─────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. CallController::placeOutbound()                              │
│    - AfricasTalkingVoiceService::placeCall($contact->phone)     │
│      • normalize to E164 internally                             │
│      • POST AT /call from=virtualNumber to=normalized           │
│      • returns sessionId                                        │
│    - persist CallLog (provider=africas_talking,                 │
│      provider_session_id=sessionId,                             │
│      status=initiated, direction=outbound,                      │
│      placed_by_user_id=agent.id)                                │
│    - broadcast CallRinging on private-user.{agent.id}           │
└─────────────────────────────────────────────────────────────────┘
                            │ Reverb push
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. Agent's browser: OutgoingCall component receives event       │
│    Mounts window.outgoingCall Alpine factory                    │
│    Factory inits Africa's Talking JS SDK with auth token        │
│    SDK opens WebRTC peer to AT gateway                          │
│    Banner: "Calling +234... · 0:03 · [Mute] [Hang up]"          │
└─────────────────────────────────────────────────────────────────┘
                            │ Customer picks up
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. AT webhook: POST /webhooks/africastalking/voice              │
│    AfricasTalkingWebhookController::handle()                    │
│    - signature verification (HMAC-SHA256 with API key)          │
│    - status=Ringing/InProgress/Completed/Failed branch          │
│    - update CallLog status, connected_at, duration_seconds      │
│    - on Completed: compute cost_estimate_kobo                   │
│    - broadcast CallTerminated on call end                       │
└─────────────────────────────────────────────────────────────────┘

INBOUND (parallel to Phase 17, runs alongside it)
┌─────────────────────────────────────────────────────────────────┐
│ Customer dials AT virtual number                                │
│ → AT webhook arrives with direction=Inbound, no prior sessionId │
│ → AfricasTalkingWebhookController::handle()                     │
│   • Contact::firstOrCreate by phone                             │
│   • findOrCreateConversation against the org's primary instance │
│   • RoundRobinAssigner::next() → assigned agent                 │
│   • CallLog::create(provider=africas_talking, direction=inbound)│
│   • broadcast CallRinging                                       │
│ → IncomingCall component on agent's browser                     │
│ → Blade conditionally mounts incomingAtCall factory             │
│   (because $call->provider === 'africas_talking')               │
│ → Agent clicks Accept; AT SDK answers; audio flows              │
└─────────────────────────────────────────────────────────────────┘
```

## Database

### Migration — three new columns on `call_logs`

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
        // Mutually exclusive with meta_call_id in practice (each row uses
        // exactly one provider's identifier).
        $table->string('provider_session_id', 128)->nullable()->after('meta_call_id');

        // Estimated cost in kobo (1/100 NGN), computed on call-end from
        // duration * africastalking_rate_per_minute_kobo / 60. Integer to
        // avoid float rounding on currency. Nullable — Meta calls stay null
        // (free, not metered).
        $table->unsignedInteger('cost_estimate_kobo')->nullable()->after('duration_seconds');
    });
}
```

Existing rows backfill to `provider='meta_whatsapp'` automatically. Phase 14.x + 17 paths continue using `meta_call_id`.

### Settings — four new keys

| Key | Storage | Notes |
|---|---|---|
| `africastalking_username` | plain text | AT account username |
| `africastalking_api_key` | `Crypt::encryptString(...)` | high-value secret; encrypted at rest |
| `africastalking_virtual_number` | plain text E.164 (`+234...`) | the registered number for caller ID |
| `africastalking_rate_per_minute_kobo` | plain text integer (e.g. `'600'`) | per-minute rate; default ₦6/min |

### Setting model addition

A small accessor pattern to handle the encrypted key transparently. Add a static helper or a new `EncryptedSetting` accessor — implementation chooses the smaller diff:

```php
// On Setting model:
public static function getEncrypted(string $key, ?string $default = null): ?string
{
    $raw = static::get($key);
    if ($raw === null) return $default;
    try { return Crypt::decryptString($raw); }
    catch (\Throwable $e) { return $default; }
}

public static function setEncrypted(string $key, string $value): static
{
    return static::set($key, Crypt::encryptString($value));
}
```

The existing `Setting::get($key)` continues to work for plain-text rows. New methods are explicit when encryption is required — caller has to opt in.

## Service layer — `App\Services\AfricasTalkingVoiceService`

```php
class AfricasTalkingVoiceService
{
    public function __construct(
        private readonly ContactImportService $normalizer,
    ) {}

    /**
     * Initiate an outbound PSTN call. Returns AT sessionId. Throws
     * VoiceProviderException on API failure (caller wraps for 503 response).
     */
    public function placeCall(string $toCustomer): string
    {
        $virtual = Setting::get('africastalking_virtual_number')
            ?? throw new ConfigurationException('Virtual number not configured');

        $normalized = $this->toE164($toCustomer);

        $response = $this->client()->asForm()->post('https://voice.africastalking.com/call', [
            'username' => Setting::get('africastalking_username'),
            'from' => $virtual,
            'to' => $normalized,
        ]);

        if ($response->failed()) {
            \Log::error('AT placeCall HTTP failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new VoiceProviderException("placeCall HTTP {$response->status()}");
        }

        $body = $response->json();
        $entry = $body['entries'][0] ?? null;
        if (!$entry || ($entry['status'] ?? null) !== 'Queued') {
            throw new VoiceProviderException("AT rejected call: " . json_encode($body));
        }

        return (string) $entry['sessionId'];
    }

    /**
     * Hang up an in-progress call by session ID. Fire-and-forget on 4xx —
     * call may have already ended naturally; we log but don't throw.
     */
    public function endCall(string $sessionId): void { /* parallel structure */ }

    /**
     * Generate a short-lived auth token for the JS SDK. Token scopes
     * the current authenticated user, valid ~60 minutes per AT defaults.
     */
    public function generateClientToken(User $user): string { /* per AT docs */ }

    /**
     * Convert input phone to E.164 (with leading '+'). Reuses the existing
     * ContactImportService::normalizePhone (returns digits without '+');
     * we prepend '+' here for AT's API requirement.
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

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'apiKey' => Setting::getEncrypted('africastalking_api_key'),
            'Accept' => 'application/json',
        ]);
    }
}
```

**Why reuse `ContactImportService::normalizePhone`**: it already exists, already handles Nigerian-specific input cleaning (leading `0`, no plus), already used in production for contact import. Don't duplicate. Output gets a `+` prefix for AT's E.164 requirement.

## Two new exception classes

```php
namespace App\Exceptions;

class VoiceProviderException extends \RuntimeException {}
class ConfigurationException extends \RuntimeException {}
```

Both extend `RuntimeException`. `VoiceProviderException` for transient API failures (5xx, network). `ConfigurationException` for missing creds / virtual number — surfaces immediately so admin sees "configure /settings before placing calls."

## Routes

```php
// Inside the existing permission:conversations.call middleware group:
Route::post('/calls/outbound', [CallController::class, 'placeOutbound'])
    ->name('calls.outbound');

// Public AT webhook (no auth — uses HMAC signature verification):
Route::post('/webhooks/africastalking/voice', [AfricasTalkingWebhookController::class, 'handle'])
    ->name('webhook.africastalking.voice');
```

`POST /webhooks/africastalking/voice` is excluded from CSRF in `bootstrap/app.php` (matching the existing exclusion pattern for `/webhooks/whatsapp/*`).

The Phase 17 routes (`/calls/{call}/claim|answer|decline|hangup`) require no signature change — they continue to handle both providers via the new `provider` column branching.

## CallController extension

### `placeOutbound()` — new method

```php
public function placeOutbound(
    Request $request,
    AfricasTalkingVoiceService $service,
): JsonResponse {
    $request->validate([
        'conversation_id' => 'required|exists:conversations,id',
    ]);

    $conversation = Conversation::findOrFail($request->input('conversation_id'));
    $this->authorize('reply', $conversation);  // existing policy

    try {
        $sessionId = $service->placeCall($conversation->contact->phone);

        $call = CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'whatsapp_instance_id' => $conversation->whatsapp_instance_id,
            'direction' => 'outbound',
            'provider' => 'africas_talking',
            'provider_session_id' => $sessionId,
            'status' => CallLog::STATUS_INITIATED,
            'started_at' => now(),
            'placed_by_user_id' => auth()->id(),
            'from_phone' => Setting::get('africastalking_virtual_number'),
            'to_phone' => $conversation->contact->phone,
        ]);

        broadcast(new CallRinging($call));

        return response()->json([
            'call_id' => $call->id,
            'session_id' => $sessionId,
        ]);
    } catch (VoiceProviderException | ConfigurationException $e) {
        // Audit row for the failure.
        CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'whatsapp_instance_id' => $conversation->whatsapp_instance_id,
            'direction' => 'outbound',
            'provider' => 'africas_talking',
            'status' => CallLog::STATUS_FAILED,
            'failure_reason' => $e->getMessage(),
            'placed_by_user_id' => auth()->id(),
            'from_phone' => Setting::get('africastalking_virtual_number') ?? '',
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

### `hangup()` — provider routing

```php
public function hangup(CallLog $call): JsonResponse
{
    if ($call->provider === 'africas_talking') {
        try {
            app(AfricasTalkingVoiceService::class)->endCall($call->provider_session_id);
        } catch (\Throwable $e) {
            // Log but don't throw — local CallLog update + broadcast still happen.
            \Log::warning('AT endCall failed during hangup', ['call_id' => $call->id]);
        }
    } else {
        app(WhatsAppCloudApiService::class)->endCall(
            $call->whatsappInstance,
            $call->meta_call_id,
        );
    }

    $call->update([
        'status' => CallLog::STATUS_ENDED,
        'ended_at' => now(),
    ]);
    broadcast(new CallTerminated($call, 'agent_hung_up'));

    return response()->json(['ended' => true]);
}
```

`decline()` gets the same provider-branching pattern.

## Webhook layer — `AfricasTalkingWebhookController`

```php
class AfricasTalkingWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        if (!$this->verifySignature($request)) {
            return response('invalid signature', 401);
        }

        $event = $request->all();
        $sessionId = $event['sessionId'] ?? null;
        $direction = strtolower($event['direction'] ?? '');
        $status = $event['status'] ?? null;

        if ($direction === 'inbound' && $sessionId === null) {
            return $this->handleInboundFirstEvent($event);
        }

        $call = CallLog::where('provider_session_id', $sessionId)->first();
        if (!$call) {
            \Log::warning('AT webhook for unknown sessionId', ['session_id' => $sessionId]);
            return response('ok', 200);  // avoid AT retry storm
        }

        match ($status) {
            'Ringing' => $call->update(['status' => CallLog::STATUS_RINGING]),
            'InProgress' => $call->update([
                'status' => CallLog::STATUS_CONNECTED,
                'connected_at' => now(),
            ]),
            'Completed' => $this->finalizeCall($call, $event, CallLog::STATUS_ENDED),
            'Failed' => $this->finalizeCall($call, $event, CallLog::STATUS_FAILED),
            default => null,
        };

        if (in_array($status, ['Completed', 'Failed'], true)) {
            broadcast(new CallTerminated($call, 'remote_' . strtolower($status)));
        }

        return response('ok', 200);
    }

    private function handleInboundFirstEvent(array $event): Response
    {
        // 1. Contact::firstOrCreate by from_phone (the customer).
        // 2. Resolve org's primary WhatsApp instance (single-tenant: SELECT * LIMIT 1).
        // 3. findOrCreateConversation pattern (Phase 14.x existing).
        // 4. Auto-assign via RoundRobinAssigner::next().
        // 5. Create CallLog provider=africas_talking, direction=inbound, status=ringing.
        // 6. Persist sessionId from webhook payload onto CallLog for subsequent events.
        // 7. Broadcast CallRinging on assigned_to_user_id's private channel.
        // Returns 200.
    }

    private function finalizeCall(CallLog $call, array $event, string $endStatus): void
    {
        $duration = (int) ($event['durationInSeconds'] ?? 0);
        $rateKobo = (int) Setting::get('africastalking_rate_per_minute_kobo', 600);
        $costKobo = (int) ceil($duration * $rateKobo / 60);

        $call->update([
            'status' => $endStatus,
            'ended_at' => now(),
            'duration_seconds' => $duration,
            'cost_estimate_kobo' => $costKobo,
            'failure_reason' => $endStatus === CallLog::STATUS_FAILED
                ? ($event['hangupCause'] ?? 'AT failed')
                : null,
        ]);
    }

    private function verifySignature(Request $request): bool
    {
        // HMAC-SHA256 of raw body using AT API key as shared secret.
        // Compare against header 'X-Africastalking-Signature' (or whatever AT uses;
        // confirm against their docs at implementation time).
        // Constant-time comparison via hash_equals().
    }
}
```

## Browser layer

### Outbound — new files

**`app/Livewire/OutgoingCall.php`** — Livewire shell, similar shape to `IncomingCall`:

```php
class OutgoingCall extends Component
{
    public CallLog $call;
    public string $atToken;

    public function mount(CallLog $call): void
    {
        $this->call = $call;
        $this->atToken = app(AfricasTalkingVoiceService::class)->generateClientToken(auth()->user());
    }

    public function render()
    {
        return view('livewire.outgoing-call');
    }
}
```

**`resources/views/livewire/outgoing-call.blade.php`** — banner mounting the Alpine factory:

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
        <div class="flex items-center justify-between bg-amber-100 ...">
            <span>Calling <span x-text="contactName"></span> · <span x-text="formatDuration(durationSeconds)"></span></span>
            <button @click="hangup()" class="bg-red-600 ...">Cancel</button>
        </div>
    </template>
    <template x-if="state === 'connected'">
        <!-- Same banner as Phase 17 connected state (mute + hangup + duration) -->
    </template>
    <template x-if="state === 'failed'">
        <div class="bg-red-100 ...">Could not start call. <button @click="dismiss()">Dismiss</button></div>
    </template>
</div>
```

**`resources/js/outbound-call.js`** — Alpine factory wrapping the AT JS SDK:

```js
import AfricasTalking from 'africastalking-client';

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

            // The actual customer dial happens server-side (placeOutbound already
            // fired). Here we just attach the SDK to the existing session by ID
            // so audio can flow when AT bridges. Exact SDK call signature varies
            // per AT version — confirm against their docs at impl time.
            await this.atClient.attach(this.sessionId);

            // Subscribe to Echo for server-side terminate (cleanup / manager kill).
            if (window.userId && window.Echo) {
                window.Echo.private(`user.${window.userId}`)
                    .listen('.call.terminated', (e) => {
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
        this.atClient?.[this.muted ? 'mute' : 'unmute']();
    },

    async hangup() {
        await fetch(`/calls/${this.callId}/hangup`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
            credentials: 'same-origin',
        });
        this.teardown('agent');
    },

    teardown(reason) {
        clearInterval(this.durationTimer);
        try { this.atClient?.disconnect(); } catch (_) {}
        this.state = 'ended';
    },

    startDurationTimer() {
        this.durationTimer = setInterval(() => this.durationSeconds++, 1000);
    },

    formatDuration(s) {
        const m = Math.floor(s / 60);
        return `${m}:${String(s % 60).padStart(2, '0')}`;
    },

    dismiss() { /* hide the failed banner */ },
});
```

### Inbound — extend Phase 17's component

`resources/views/livewire/incoming-call.blade.php` (Phase 17) gets a provider-conditional x-data:

```blade
@if($call->provider === \App\Models\CallLog::PROVIDER_AFRICAS_TALKING)
    <div x-data="incomingAtCall({...})" x-init="init()">
        {{-- AT-flavor accept/decline using AT SDK --}}
    </div>
@else
    <div x-data="incomingCall({...})" x-init="init()">
        {{-- Phase 17 raw-WebRTC accept/decline against Meta --}}
    </div>
@endif
```

`window.incomingAtCall` is a new factory (added to `outbound-call.js` for cohesion) that uses AT's SDK to answer instead of building an `RTCPeerConnection` manually. State machine identical (ringing → claiming → connecting → connected → terminated); only the peer mechanics differ.

A new constant on `CallLog` model:
```php
public const PROVIDER_META_WHATSAPP = 'meta_whatsapp';
public const PROVIDER_AFRICAS_TALKING = 'africas_talking';
```

## Trigger UX — replace existing Call button

The existing conversation page has a "Call" button (Phase 14.x) that currently fires Meta's `initiateCall`. The button stays visually identical. The click handler now POSTs to `/calls/outbound` instead of the prior Meta route. One Blade edit + one JS handler swap.

The Meta `initiateCall` and `endCall` methods stay in `WhatsAppCloudApiService` (untouched) — reachable for future "WhatsApp call" feature if ever desired, but not wired to UI as of Phase 18.

## Settings UI

`resources/views/settings/index.blade.php` gains a new "Voice Provider" panel (Tailwind card, matches existing visual pattern):

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
                   value="{{ $settings['africastalking_username'] ?? '' }}" ... />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">API Key</label>
            <input type="password" name="africastalking_api_key"
                   placeholder="{{ ($settings['africastalking_api_key'] ?? null) ? '••••••••' : '' }}" ... />
            <p class="mt-1 text-xs text-gray-500">Leave blank to keep existing key. New value will be encrypted.</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Virtual Number (E.164)</label>
            <input type="text" name="africastalking_virtual_number" placeholder="+234..." ... />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Rate per Minute (kobo)</label>
            <input type="number" name="africastalking_rate_per_minute_kobo"
                   value="{{ $settings['africastalking_rate_per_minute_kobo'] ?? 600 }}" min="0" ... />
            <p class="mt-1 text-xs text-gray-500">Per-minute estimate. Default ₦6 = 600 kobo. Used for cost tracking on /calls.</p>
        </div>
    </div>
</div>
```

`SettingsController::update()` adds four validation rules:

```php
'africastalking_username' => ['nullable', 'string', 'max:64'],
'africastalking_api_key' => ['nullable', 'string', 'min:10', 'max:512'],
'africastalking_virtual_number' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/'],
'africastalking_rate_per_minute_kobo' => ['nullable', 'integer', 'min:0', 'max:100000'],
```

The api_key save path in the controller calls `Setting::setEncrypted('africastalking_api_key', $value)` instead of the plain `Setting::set` used for the other rows.

## Cost calculation + history page

When the AT webhook arrives with `status=Completed`, `finalizeCall()` computes:

```
cost_kobo = ceil(duration_seconds * rate_per_minute_kobo / 60)
```

And persists it on the CallLog. Past CallLog rows keep their historical cost (no retroactive recompute when rate changes).

`/calls` history page (existing from Phase 14.x — extended) gains:
- New "Cost" column rendering `cost_estimate_kobo / 100` formatted as `₦X.XX`. Empty for Meta calls.
- Top-of-page widget: "₦X spent on calls today / ₦Y this month" — a `SUM(cost_estimate_kobo) WHERE created_at >= today/month-start`.

## Data flow scenarios

| Scenario | Sequence |
|---|---|
| Happy path outbound | Click Call → server placeCall → CallLog initiated → CallRinging → browser SDK init → AT call rings customer → customer picks up → AT webhook InProgress → CallLog connected → talk → hangup → AT webhook Completed → cost computed → CallTerminated → banner clears |
| AT API down on placeCall | Click Call → service throws VoiceProviderException → catch → audit CallLog status=failed → 503 to client → "Voice service unavailable" banner |
| Customer doesn't pick up | AT webhook eventually sends Failed with hangupCause "no answer" → CallLog status=failed, failure_reason="no answer" → CallTerminated → banner clears |
| Inbound to virtual number | AT webhook direction=Inbound → handleInboundFirstEvent → Contact firstOrCreate + Conversation + auto-assign + CallLog + broadcast CallRinging → IncomingCall mounts incomingAtCall factory → Accept → SDK answers |
| Hangup during outbound | Click Hangup → POST /calls/{id}/hangup → CallController.hangup detects provider=africas_talking → AT endCall → CallLog status=ended → CallTerminated |
| AT webhook arrives for unknown session | Log warn, return 200 (avoid retry storm) |
| Webhook signature invalid | Return 401, AT will retry; logged for security audit |

## Error handling matrix

| Failure | Behavior | Why |
|---|---|---|
| AT API 5xx during placeCall | 503 to agent + audit row status=failed | Voice service issue, not user error; surface clearly |
| AT API 4xx during placeCall | Same as 5xx (treat as service issue) | 4xx with body could be auth/config; admin investigates |
| AT API 4xx during endCall | Log warn, swallow | Call may have ended naturally, no harm in best-effort |
| Webhook signature fails | 401 + log | Possible attacker; AT will retry valid signature |
| Webhook for unknown sessionId | 200 + log warn | Avoid AT retry storm; safe to drop |
| Inbound but no agent online | CallLog status=missed, no broadcast | Mirrors Phase 17 behavior; manager handles via Unassigned filter |
| Browser SDK init fails | state=failed banner with "Could not start call" + POST hangup | Server cleans up CallLog + broadcasts CallTerminated |
| Phone normalization fails | 422 + "Invalid phone number" | User error; agent corrects contact info |
| Encrypted key decrypt fails | Setting::getEncrypted returns null → ConfigurationException → 503 with "configure /settings" message | Likely APP_KEY changed without re-encrypting; admin must rotate |
| AT virtual number not configured | placeCall throws ConfigurationException → 503 | First-time setup prompt |

## Testing

~20 new PHPUnit tests across 4 files. Browser SDK integration is NOT tested (matches Phase 17's gap; Phase 19 adds Playwright).

### `tests/Feature/Services/AfricasTalkingVoiceServiceTest.php` (~7 tests)

1. `placeCall` POSTs correct payload (form-encoded, Authorization header) to AT `/call`
2. `placeCall` normalizes Nigerian local format `08012345678` to `+2348012345678` before sending
3. `placeCall` returns sessionId from `entries[0].sessionId` on success
4. `placeCall` throws VoiceProviderException on HTTP 4xx/5xx
5. `placeCall` throws VoiceProviderException when `entries[0].status != 'Queued'` (200 OK but rejected)
6. `endCall` POSTs to AT terminate endpoint
7. `endCall` swallows 4xx (logs warning, no throw)

### `tests/Feature/Webhooks/AfricasTalkingWebhookTest.php` (~6 tests)

8. Outbound `Ringing` webhook updates CallLog status=ringing
9. `Completed` webhook computes cost_estimate_kobo from duration × rate
10. `Failed` webhook persists hangupCause to failure_reason
11. Inbound first event creates Contact + Conversation + CallLog with provider=africas_talking
12. Inbound first event invokes RoundRobinAssigner::next + dispatches CallRinging
13. Invalid HMAC signature returns 401

### `tests/Feature/Http/CallControllerOutboundTest.php` (~3 tests)

14. Successful placeOutbound creates CallLog (provider=AT, status=initiated) + broadcasts CallRinging
15. Service exception → 503 + audit CallLog status=failed
16. Permission denied (no `conversations.call`) → 403

### `tests/Feature/Http/HangupProviderRoutingTest.php` (~2 tests)

17. Hangup on AT call invokes AfricasTalkingVoiceService::endCall
18. Hangup on Meta call invokes WhatsAppCloudApiService::endCall (regression check)

### `tests/Feature/Calls/CallCostCalculationTest.php` (~2 tests)

19. Duration 90s × ₦6/min rate = 900 kobo (₦9.00)
20. Duration 0s = 0 kobo

**Test trajectory:** 234 baseline → **~254 final**.

## Files

### Files to create (~12)

| File | Responsibility |
|---|---|
| `database/migrations/<ts>_add_provider_columns_to_call_logs.php` | provider, provider_session_id, cost_estimate_kobo |
| `app/Services/AfricasTalkingVoiceService.php` | placeCall, endCall, generateClientToken, toE164 |
| `app/Exceptions/VoiceProviderException.php` | API failure surface |
| `app/Exceptions/ConfigurationException.php` | missing creds surface |
| `app/Http/Controllers/AfricasTalkingWebhookController.php` | inbound + lifecycle webhook handler |
| `app/Livewire/OutgoingCall.php` | Livewire shell for outbound banner |
| `resources/views/livewire/outgoing-call.blade.php` | Outbound banner UI |
| `resources/js/outbound-call.js` | Alpine factory + AT SDK lifecycle (also defines incomingAtCall for inbound AT) |
| `tests/Feature/Services/AfricasTalkingVoiceServiceTest.php` | 7 service tests |
| `tests/Feature/Webhooks/AfricasTalkingWebhookTest.php` | 6 webhook tests |
| `tests/Feature/Http/CallControllerOutboundTest.php` | 3 outbound trigger tests |
| `tests/Feature/Http/HangupProviderRoutingTest.php` | 2 provider-routing regression tests |
| `tests/Feature/Calls/CallCostCalculationTest.php` | 2 cost math tests |

### Files to modify (~10)

| File | Change |
|---|---|
| `app/Models/CallLog.php` | Add fillable for new columns; constants `PROVIDER_META_WHATSAPP`, `PROVIDER_AFRICAS_TALKING` |
| `app/Models/Setting.php` | Add `getEncrypted` / `setEncrypted` static methods |
| `app/Http/Controllers/CallController.php` | New `placeOutbound()`; provider-routing in `decline()` + `hangup()` |
| `app/Livewire/IncomingCall.php` (Phase 17) | Pass `$call->provider` through to view |
| `resources/views/livewire/incoming-call.blade.php` | Conditional x-data factory based on provider |
| `resources/views/settings/index.blade.php` | New Voice Provider section |
| `app/Http/Controllers/SettingsController.php` | 4 new validation rules; encrypted save path for api_key |
| `resources/views/conversations/show.blade.php` (or wherever Call button lives) | Swap click handler to POST `/calls/outbound` |
| `routes/web.php` | New `/calls/outbound` route + AT webhook route |
| `bootstrap/app.php` | Add AT webhook to CSRF exclusion list |
| `resources/js/app.js` | `import './outbound-call'` |
| `package.json` | `africastalking-client` npm dep |
| `.env.example` | Document AT credential keys (AT username, etc.) |

## Operational notes

- **First-time setup**: composer install + npm install + run migration. Then admin visits `/settings` → "Voice Provider" section → enters AT credentials. Place a test call immediately.
- **Webhook URL** registered with Africa's Talking dashboard: `https://blast.dpluxtech.com/webhooks/africastalking/voice`. Configure inbound number to forward to same URL.
- **Cost monitoring**: per-call cost in `call_logs.cost_estimate_kobo`; aggregate widgets on `/calls` history page.
- **Rate updates**: when AT changes per-minute rate, update `africastalking_rate_per_minute_kobo` in `/settings`. Past rows keep historical cost.
- **API key rotation**: `/settings` → type new key → Save. Encrypted in DB. In-flight calls keep their connection (no re-auth needed).
- **Single-tenant assumption**: all references to "the org's primary WhatsApp instance" select `WhatsAppInstance::query()->first()` (or oldest by id). Do NOT add multi-tenant scoping.

## Known limitations

- **AT JS SDK exact API** — variable `attach(sessionId)` vs `call(toNumber)` semantics may differ from this spec. Implementation will discover the right SDK shape and adapt the Alpine factory accordingly. The server-side flow is unaffected.
- **Browser-side automated testing gap** — same as Phase 17. PHPUnit covers the state machine + service + webhook layer; the SDK call itself requires manual verification on first deploy.
- **Per-network rate precision** — single average rate covers Nigerian mobile (~₦5-7/min) and landlines (~₦20+/min) imprecisely. Materially fine for v1 cost estimation; refine when reports need precision.
- **`attach(sessionId)` vs `call(number)`** — one open question is whether AT's SDK pattern is "server initiates the call, browser attaches to the session" (cleaner architecture, what this spec assumes) or "browser places the call directly via SDK" (more provider-locked). Implementation will verify against AT's docs and adapt without changing the Livewire/server layers.
- **No call recording** — explicit defer. Phase 19+.
- **No outbound queue** — failures bubble immediately to the agent. Phase 19+ if demand surfaces.
- **Inbound to virtual number assumes one primary WhatsApp instance** — fine for single-tenant. If multi-tenant is ever introduced, the inbound handler will need a different scoping rule.

## Open follow-ups (not blockers)

- **AT JS SDK install path**: `africastalking-client` may have additional setup steps (e.g., a separate `init({apiKey})` call, or a different package name like `@africastalking/voice`). Verify on `npm install` during Task 2 of the implementation plan.
- **Token generation API**: AT may require a server-side OAuth-style flow to mint tokens, or simple HMAC over username + timestamp. Implementation will follow their published docs.
- **Inbound caller ID display**: customer's phone number on inbound. AT webhook payload will include `from` — display logic identical to Phase 17 inbound (contact lookup + display_name fallback).
