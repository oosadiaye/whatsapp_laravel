# Phase 17 — Inbound WhatsApp Call Browser Answer (WebRTC) Design

**Date:** 2026-05-07
**Status:** Approved
**Builds on:** Phase 14.1-14.4 (call infrastructure, presence, round-robin), Phase 15 (team load dashboard)

## Summary

Convert the existing "ringing notification" banner into a fully working WebRTC voice path. When a customer calls the business WhatsApp number, the assigned agent sees Accept/Decline buttons in their dashboard. Clicking Accept activates the microphone, generates an SDP answer through `RTCPeerConnection`, ships it to Meta via `acceptCall`, and the customer↔browser audio peer connects. Clicking Hangup or Decline tears down cleanly.

Signaling rides over Laravel Reverb (~100ms push) instead of the existing 3-second poll. Server fires `pre_accept` immediately on webhook receipt (so Meta knows the business is engaged before the agent even clicks). Single-tab claim via atomic SQL prevents duplicate accepts across multiple agent windows. A periodic stale-call sweep covers the case where Meta's `terminate` webhook never arrives.

This is the most architecturally substantial phase since 14.1 because it introduces:
- A new long-running daemon (Reverb on port 8080)
- Real-time WebSocket push (Laravel Echo on the client)
- Browser-side WebRTC peer connection (`RTCPeerConnection`, `getUserMedia`)
- Atomic claim semantics across multiple browser tabs
- A two-step accept ritual against Meta's calling API

## Goals

1. Convert the ringing banner from "notification + open conversation" to "Accept / Decline call." Agent answers in browser, talks to customer.
2. Ship the smallest viable real-time peer-to-peer audio system end-to-end: signaling, mic capture, SDP exchange, audio rendering, hangup. Quality polish (TURN, telemetry) explicitly defers to Phase 19.
3. Build infrastructure that Phase 18 (outbound browser dial) reuses — same `RTCPeerConnection` plumbing, just inverted offerer/answerer roles and a different trigger surface.
4. Maintain database integrity even when Meta's webhooks are unreliable (the stale-call sweep is the safety net).

## Non-goals (deferred)

- **Phase 18 — outbound browser dial.** The agent clicking the existing Call button still triggers `initiateCall` which routes audio to the Meta-registered phone. Browser-side outbound is the next phase.
- **Phase 19 — TURN server.** STUN-only ICE may fail on restrictive NATs (corporate VPN, double-NAT). Documented limitation; affects a minority of agents.
- **Phase 19 — Call quality telemetry.** No MOS, jitter, packet-loss capture in this phase.
- **Phase 19 — Call recording.** Compliance-dependent; separate design pass.
- **Phase 20+ — Mid-call hold / transfer / conference.** Meta's API doesn't support `re-INVITE` per docs; implementing these requires a separate call-bridging architecture.
- **Audio device picker.** Use OS/browser default. Phase 18 polish.
- **Auto-route on ringing timeout.** If primary assignee doesn't answer in 30s, Meta times out the call. Re-routing to a different agent is a Phase 18+ feature.
- **Preemptive mic permission on page load.** We ask just-in-time on Accept click — better browser-trust signaling.

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Customer calls → Meta delivers webhook                       │
│    POST /webhooks/whatsapp { calls: [{event: connect, session:  │
│      {sdp, sdp_type: 'offer'}}] }                               │
└─────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. InboundCallProcessor::processCalls()                         │
│    - findOrCreateConversation (existing, Phase 14.2)            │
│    - assigns agent via RoundRobinAssigner (existing, Phase 14.2)│
│    - persists CallLog with sdp_offer column populated           │
│    - calls WhatsAppCloudApiService::preAcceptCall (no SDP)      │
│    - broadcasts CallRinging event on private-user.{id} channel  │
└─────────────────────────────────────────────────────────────────┘
                            │ Reverb WebSocket push (~100ms)
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. Agent's browser: Echo subscribes to private-user.{id}        │
│    receives CallRinging payload                                 │
│    Livewire IncomingCall component renders Accept/Decline       │
│    Alpine factory holds the SDP offer in state, awaits click    │
└─────────────────────────────────────────────────────────────────┘
                            │ Agent clicks Accept
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. Browser-side accept flow (in resources/js/calls.js)          │
│    a. POST /calls/{id}/claim with session_id                    │
│       - Server: atomic UPDATE answered_by_session_id WHERE NULL │
│       - First wins; broadcast CallClaimed to dismiss other tabs │
│    b. getUserMedia({audio: true}) — browser permission prompt   │
│    c. new RTCPeerConnection({iceServers: [stun:stun.l.google]}) │
│    d. peer.addTrack(micTrack, micStream)                        │
│    e. peer.setRemoteDescription({type: 'offer', sdp: offer})    │
│    f. answer = await peer.createAnswer()                        │
│    g. peer.setLocalDescription(answer)                          │
│    h. POST /calls/{id}/answer { sdp: answer.sdp }               │
└─────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. CallController::answer()                                     │
│    WhatsAppCloudApiService::acceptCall($call_id, $sdp_answer)   │
│    POST /{phone_number_id}/calls action=accept                  │
│    Meta initiates ICE candidate exchange + DTLS handshake       │
│    Audio peer established between browser and Meta              │
└─────────────────────────────────────────────────────────────────┘
                            │ Meta sends webhook: status=connected
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 6. Active call — peer.ontrack fires in browser                  │
│    document.getElementById('remote-audio').srcObject = stream   │
│    Audio plays. Agent talks via mic, customer hears.            │
│    Banner shows mute/hangup + duration timer.                   │
└─────────────────────────────────────────────────────────────────┘
                            │ Hangup or customer disconnect
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 7. Teardown                                                     │
│    - peer.close() in browser, mic tracks stopped                │
│    - WhatsAppCloudApiService::endCall (existing terminate)      │
│    - CallTerminated broadcast clears banner                     │
│    - CallLog updated to status=ended                            │
└─────────────────────────────────────────────────────────────────┘
```

## Database

### Migration A — claim tracking and SDP storage on `call_logs`

```php
public function up(): void
{
    Schema::table('call_logs', function (Blueprint $table) {
        // The browser session UUID that claimed this call. Atomic UPDATE
        // WHERE answered_by_session_id IS NULL ensures first-tab-wins
        // semantics with zero application-layer races. NULL = not yet
        // claimed (still ringing).
        $table->string('answered_by_session_id', 64)->nullable()
            ->after('placed_by_user_id');
        $table->index('answered_by_session_id');

        // SDP offer received from Meta in the connect webhook. Stored so
        // a browser tab loading mid-ring (e.g., agent navigates to inbox
        // while a call is already ringing) can fetch the offer via the
        // Livewire mount, not just from the live Reverb broadcast.
        // Erased after CallTerminated to avoid keeping per-session SDP
        // around in DB longer than necessary.
        $table->text('sdp_offer')->nullable()->after('answered_by_session_id');

        // SDP answer the agent's browser generated. Audit aid only —
        // never re-used after the call ends.
        $table->text('sdp_answer')->nullable()->after('sdp_offer');
    });
}
```

### Migration B — mic permission state on `users`

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // pending  = never asked (default, set on first user creation)
        // granted  = browser said yes (subsequent calls skip the prompt)
        // denied   = browser said no (show "grant mic" banner)
        // The browser's own permission persistence is the source of truth;
        // this column is for our UI to know whether to show a hint banner.
        $table->string('mic_permission_state', 16)
            ->default('pending')
            ->after('presence_status_set_at');
    });
}
```

No new tables. No new index on `mic_permission_state` (only read for the user's own UI hint).

### User model addition

Add three constants and update `casts()` array:

```php
public const MIC_PENDING = 'pending';
public const MIC_GRANTED = 'granted';
public const MIC_DENIED  = 'denied';
```

## Reverb infrastructure

`composer require laravel/reverb`. Configuration in `.env`:

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=blastiq
REVERB_APP_KEY=<random>
REVERB_APP_SECRET=<random>
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="blast.dpluxtech.com"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

`config/broadcasting.php` already supports the `reverb` driver in Laravel 12; no manual config changes needed.

### Supervisor program

New file `deploy/supervisor-reverb.conf` (matches the worker template pattern):

```ini
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

Bootstrap script `deploy/install-reverb.sh` mirrors `install-supervisor.sh`: substitutes `__PROJECT_PATH__` and `__RUN_AS_USER__`, installs to `/etc/supervisord.d/blastiq-reverb.ini`, supervisorctl reread + update + start.

### Nginx WebSocket proxy

Append to `deploy/nginx.conf` inside the existing `server {}` block:

```nginx
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

Port 8080 is local-only (bound to 127.0.0.1). Public WebSocket traffic enters via HTTPS on port 443 at `/app`.

### Channel authorization

`routes/channels.php` adds:

```php
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

Each user only authorizes for their own channel. Echo connects with the authenticated session cookie; broadcast auth uses the standard Laravel session guard.

## Broadcast events

Three events under `App\Events\Calling`:

### `CallRinging`

```php
class CallRinging implements ShouldBroadcast
{
    public function __construct(public CallLog $call) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.' . $this->call->assigned_to_user_id);
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->call->id,
            'meta_call_id' => $this->call->meta_call_id,
            'contact_name' => $this->call->contact->display_name,
            'phone' => $this->call->from_phone,
            'sdp_offer' => $this->call->sdp_offer,
        ];
    }
}
```

### `CallClaimed`

Fired the moment a tab successfully POSTs `/calls/{id}/claim`. Payload includes the session ID that won so other tabs can verify they didn't win and dismiss.

```php
public function broadcastWith(): array
{
    return [
        'call_id' => $this->call->id,
        'claimed_by_session_id' => $this->call->answered_by_session_id,
    ];
}
```

### `CallTerminated`

Fired when:
- Meta sends a `terminate` / `ended` / `missed` webhook
- Agent clicks Decline or Hangup
- Stale-call cleanup command runs

```php
public function broadcastWith(): array
{
    return [
        'call_id' => $this->call->id,
        'reason' => $this->reason, // 'customer_hung_up' | 'agent_hung_up' | 'declined' | 'stale_cleanup' | etc.
    ];
}
```

## Service additions — `WhatsAppCloudApiService`

### `preAcceptCall(WhatsAppInstance $instance, string $metaCallId, ?string $sdpAnswer = null): void`

```php
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
        // Pre-accept is OPTIONAL — failure does NOT abort the call.
        // We lose the no-clipping benefit but agent can still answer.
        $this->logHttp('preAcceptCall', $instance, $response->status(), $response->body());
        Log::warning('preAcceptCall failed; continuing without pre-accept benefit', [
            'meta_call_id' => $metaCallId,
            'status' => $response->status(),
        ]);
    }
}
```

### `acceptCall(WhatsAppInstance $instance, string $metaCallId, string $sdpAnswer): void`

```php
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

### Existing `endCall` is unchanged

The Decline and Hangup paths both reuse the existing `endCall(WhatsAppInstance, string $metaCallId)` method.

## Service change — `InboundCallProcessor`

The existing webhook handler gains three responsibilities. After the existing `findOrCreateConversation` + `auto-assign` logic, before returning:

```php
$callLog->update(['sdp_offer' => $payload['session']['sdp']]);

// Tell Meta we're engaging — synchronous, fast (~100ms). Failure is
// non-fatal; the call still rings on agent's screen even without pre-accept.
$this->whatsAppCloudApiService->preAcceptCall(
    $instance,
    $callLog->meta_call_id,
);

// Push the SDP offer to the assigned agent's browser via Reverb.
broadcast(new CallRinging($callLog));
```

## Routes

Four new routes inside the existing `auth` + `permission:conversations.reply` middleware group:

```php
Route::post('/calls/{call}/claim', [CallController::class, 'claim'])
    ->name('calls.claim');
Route::post('/calls/{call}/answer', [CallController::class, 'answer'])
    ->name('calls.answer');
Route::post('/calls/{call}/decline', [CallController::class, 'decline'])
    ->name('calls.decline');
Route::post('/calls/{call}/hangup', [CallController::class, 'hangup'])
    ->name('calls.hangup');
```

Existing `conversations.endCall` route handles end-from-conversation-page; the new `/calls/{call}/hangup` is the in-flight banner's hangup button.

## Controller — `CallController` (extending existing class)

```php
public function claim(Request $request, CallLog $call): JsonResponse
{
    $sessionId = $request->input('session_id');
    if (!is_string($sessionId) || strlen($sessionId) !== 36) {
        return response()->json(['error' => 'invalid session_id'], 422);
    }

    // Atomic claim. UPDATE only fires if column is NULL (or already claimed by us — idempotent).
    $rowsAffected = DB::table('call_logs')
        ->where('id', $call->id)
        ->where(function ($q) use ($sessionId) {
            $q->whereNull('answered_by_session_id')
              ->orWhere('answered_by_session_id', $sessionId);
        })
        ->update(['answered_by_session_id' => $sessionId]);

    if ($rowsAffected === 0) {
        return response()->json(['error' => 'already claimed elsewhere'], 409);
    }

    $call->refresh();
    broadcast(new CallClaimed($call))->toOthers();

    return response()->json(['claimed' => true]);
}

public function answer(Request $request, CallLog $call): JsonResponse
{
    $sdpAnswer = $request->input('sdp');
    abort_if(
        $call->answered_by_session_id !== $request->input('session_id'),
        409,
        'must claim before answering'
    );

    $this->whatsAppCloudApiService->acceptCall(
        $call->whatsappInstance,
        $call->meta_call_id,
        $sdpAnswer,
    );
    $call->update(['sdp_answer' => $sdpAnswer]);

    return response()->json(['accepted' => true]);
}

public function decline(CallLog $call): JsonResponse
{
    $this->whatsAppCloudApiService->endCall(
        $call->whatsappInstance,
        $call->meta_call_id,
    );
    $call->update(['status' => 'declined', 'ended_at' => now()]);
    broadcast(new CallTerminated($call, 'declined'));

    return response()->json(['declined' => true]);
}

public function hangup(CallLog $call): JsonResponse
{
    $this->whatsAppCloudApiService->endCall(
        $call->whatsappInstance,
        $call->meta_call_id,
    );
    $call->update(['status' => 'ended', 'ended_at' => now()]);
    broadcast(new CallTerminated($call, 'agent_hung_up'));

    return response()->json(['ended' => true]);
}
```

## Livewire component — `App\Livewire\IncomingCall`

Replaces the existing simple "Open conversation" button in the call banner. Lives inside the existing RealtimePulse banner via `<livewire:incoming-call :call="$call" />`.

The Livewire side is minimal; most logic is in the Alpine factory:

```php
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

The Blade view mounts the Alpine factory:

```blade
<div x-data="incomingCall({
    callId: {{ $call->id }},
    metaCallId: @js($call->meta_call_id),
    sdpOffer: @js($call->sdp_offer),
    sessionId: @js(session()->getId()),
    contactName: @js($call->contact->display_name),
    phone: @js($call->from_phone),
    csrf: @js(csrf_token()),
})" x-init="init()">
    <template x-if="state === 'ringing'">
        <div class="flex items-center gap-3 bg-emerald-600 text-white px-4 py-3">
            <span x-text="`Incoming call from ${contactName}`"></span>
            <button @click="acceptCall()" class="bg-white text-emerald-700 px-4 py-2 rounded">Accept</button>
            <button @click="declineCall()" class="bg-red-600 text-white px-4 py-2 rounded">Decline</button>
        </div>
    </template>

    <template x-if="state === 'connecting'">
        <div class="bg-amber-100 px-4 py-3">
            <span>Connecting...</span>
        </div>
    </template>

    <template x-if="state === 'connected'">
        <div class="bg-emerald-100 px-4 py-3 flex items-center justify-between">
            <span x-text="`On call: ${contactName} · ${formatDuration(durationSeconds)}`"></span>
            <button @click="toggleMute()" x-text="muted ? 'Unmute' : 'Mute'"></button>
            <button @click="hangup()" class="bg-red-600 text-white px-4 py-2 rounded">Hang up</button>
        </div>
    </template>

    <template x-if="state === 'mic_denied'">
        <div class="bg-red-100 px-4 py-3">
            <span>Microphone access required to answer calls. Please grant in browser settings.</span>
        </div>
    </template>

    <audio id="remote-audio" autoplay></audio>
</div>
```

## JS module — `resources/js/calls.js`

New file, registered as a window-level Alpine factory. Holds the entire WebRTC peer connection lifecycle. Key methods:

```js
window.incomingCall = (data) => ({
    ...data,
    state: 'ringing',
    peer: null,
    micStream: null,
    muted: false,
    durationSeconds: 0,
    durationTimer: null,

    init() {
        // Subscribe to Echo for CallClaimed (other tab won) + CallTerminated.
        Echo.private(`user.${window.userId}`)
            .listen('.call.claimed', (event) => {
                if (event.call_id === this.callId && event.claimed_by_session_id !== this.sessionId) {
                    this.state = 'claimed_elsewhere';
                }
            })
            .listen('.call.terminated', (event) => {
                if (event.call_id === this.callId) this.teardown();
            });
    },

    async acceptCall() {
        try {
            // 1. Atomic claim
            const claimRes = await this.post(`/calls/${this.callId}/claim`, { session_id: this.sessionId });
            if (claimRes.status === 409) { this.state = 'claimed_elsewhere'; return; }

            this.state = 'connecting';

            // 2. Mic permission (just-in-time per Q3)
            this.micStream = await navigator.mediaDevices.getUserMedia({ audio: true });

            // 3. Build RTCPeerConnection
            this.peer = new RTCPeerConnection({
                iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
            });

            // 4. Wire remote audio rendering
            this.peer.ontrack = (event) => {
                document.getElementById('remote-audio').srcObject = event.streams[0];
            };

            // 5. Add mic track outbound
            this.micStream.getAudioTracks().forEach(track => {
                this.peer.addTrack(track, this.micStream);
            });

            // 6. SDP exchange
            await this.peer.setRemoteDescription({ type: 'offer', sdp: this.sdpOffer });
            const answer = await this.peer.createAnswer();
            await this.peer.setLocalDescription(answer);

            // 7. Send answer to Meta via our server
            await this.post(`/calls/${this.callId}/answer`, {
                session_id: this.sessionId,
                sdp: answer.sdp,
            });

            this.state = 'connected';
            this.startDurationTimer();
        } catch (error) {
            if (error.name === 'NotAllowedError') {
                this.state = 'mic_denied';
                await this.post(`/calls/${this.callId}/decline`, {});
            } else {
                console.error('acceptCall failed', error);
                await this.post(`/calls/${this.callId}/decline`, {});
                this.teardown();
            }
        }
    },

    async declineCall() {
        await this.post(`/calls/${this.callId}/decline`, {});
        this.teardown();
    },

    async hangup() {
        await this.post(`/calls/${this.callId}/hangup`, {});
        this.teardown();
    },

    toggleMute() {
        this.muted = !this.muted;
        this.micStream?.getAudioTracks().forEach(t => t.enabled = !this.muted);
    },

    startDurationTimer() {
        this.durationTimer = setInterval(() => this.durationSeconds++, 1000);
    },

    teardown() {
        clearInterval(this.durationTimer);
        this.peer?.close();
        this.micStream?.getTracks().forEach(t => t.stop());
        this.state = 'terminated';
    },

    async post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrf,
            },
            body: JSON.stringify(body),
        });
    },

    formatDuration(seconds) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${m}:${s.toString().padStart(2, '0')}`;
    },
});
```

## Stale-call cleanup

`app/Console/Commands/CleanupStaleCalls.php`:

```php
class CleanupStaleCalls extends Command
{
    protected $signature = 'calls:cleanup-stale';
    protected $description = 'Mark calls stale if Meta terminate webhook never arrived';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(30);

        $stale = CallLog::query()
            ->whereIn('status', ['ringing', 'connected'])
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stale as $call) {
            $newStatus = $call->status === 'ringing' ? 'missed' : 'ended';
            $call->update([
                'status' => $newStatus,
                'ended_at' => now(),
                'failure_reason' => 'stale - no terminate webhook received',
            ]);
            broadcast(new CallTerminated($call, 'stale_cleanup'));
        }

        $this->info(sprintf('Cleaned up %d stale call(s)', $stale->count()));
        return self::SUCCESS;
    }
}
```

`routes/console.php`:

```php
Schedule::command('calls:cleanup-stale')
    ->everyMinute()
    ->withoutOverlapping();
```

## Error handling

| Failure mode | Behavior | Justification |
|---|---|---|
| `preAcceptCall` 4xx | Log warning, continue ringing | Pre-accept is optional — call still works without it, just with audio clipping risk |
| `acceptCall` 4xx | Throw → catch in browser → POST decline → teardown | Without accept there is no audio path, so the call cannot continue |
| Browser denies mic | Catch `NotAllowedError` → state=mic_denied → POST decline | Without mic, agent cannot talk; equivalent to manual decline |
| Reverb daemon down | Echo fails to connect → existing 3s poll still shows ringing banner (no SDP, no Accept button) | Polled fallback at least keeps state consistent — agent sees something is ringing but can't answer until Reverb recovers |
| Two tabs race claim | Atomic SQL `WHERE answered_by_session_id IS NULL` → first wins, second 409 → second's UI shows "claimed elsewhere" | DB-level guarantee, not application logic |
| ICE candidate gathering fails | RTCPeerConnection error → catch → POST decline → teardown | Modern browsers behind reasonable NATs almost never fail |
| `terminate` webhook never arrives | Stale cleanup at next-minute scheduler run picks it up after 30 min | Two layers of defense (Meta natural timeout + our cleanup) |
| Customer hangs up before answer | Meta sends `terminate` webhook → InboundCallProcessor updates status, broadcasts CallTerminated | Existing path — no change needed |

## Testing

Approximately 18 new PHPUnit tests across 4 files. **The browser-side WebRTC peer connection is NOT covered** by PHPUnit; verifying actual audio flow requires Playwright/Dusk and is deferred to manual smoke testing for Phase 17. Phase 19's telemetry phase is the natural home for browser-side automation.

### `tests/Feature/Services/WhatsAppCloudApiCallingTest.php` — 5 tests

1. `preAcceptCall` POSTs correct payload with no SDP
2. `preAcceptCall` POSTs with SDP when provided
3. `preAcceptCall` does NOT throw on 4xx (warns and returns void)
4. `acceptCall` POSTs SDP answer correctly
5. `acceptCall` throws `WhatsAppApiException` on 4xx

### `tests/Feature/Webhooks/InboundCallProcessingTest.php` — 3 new tests appended

6. New ringing call dispatches `CallRinging` event (via `Event::fake`)
7. New ringing call invokes `preAcceptCall` (via `Http::fake` assertion)
8. New ringing call persists `sdp_offer` from webhook payload to CallLog row

### `tests/Feature/Http/CallRouteTest.php` — 6 tests

9. `claim` first session wins, second gets 409
10. `claim` same session re-claims is idempotent (200)
11. `answer` invokes `acceptCall` with SDP, requires prior claim by same session
12. `answer` 409s if attempted by a different session_id than claimed
13. `decline` invokes `endCall` + broadcasts `CallTerminated`
14. `hangup` invokes `endCall` + broadcasts `CallTerminated`

### `tests/Feature/Console/CleanupStaleCallsTest.php` — 4 tests

15. 30-minute stale ringing → status=missed, failure_reason set
16. 30-minute stale connected → status=ended, failure_reason set
17. Recent ringing/connected calls untouched
18. Stale calls trigger CallTerminated broadcast

### Test trajectory

- Phase 15.1 baseline: 216 tests
- After Phase 17: **234 tests** (+18)

No changes to existing tests (the InboundCallProcessor extension is purely additive).

## File structure

### Files to create (~13)

| File | Responsibility |
|---|---|
| `database/migrations/<ts>_add_claim_columns_to_call_logs.php` | New columns: answered_by_session_id, sdp_offer, sdp_answer |
| `database/migrations/<ts>_add_mic_permission_state_to_users.php` | New column: mic_permission_state |
| `app/Events/Calling/CallRinging.php` | Broadcast on private-user.{id} when webhook arrives |
| `app/Events/Calling/CallClaimed.php` | Broadcast when one tab wins claim, others dismiss |
| `app/Events/Calling/CallTerminated.php` | Broadcast on any call-end cause |
| `app/Console/Commands/CleanupStaleCalls.php` | Periodic stale-call sweep |
| `app/Livewire/IncomingCall.php` | Hosts the Alpine peer-connection state |
| `resources/views/livewire/incoming-call.blade.php` | Accept/Decline/in-call banner UI |
| `resources/js/calls.js` | Alpine factory + RTCPeerConnection lifecycle |
| `deploy/supervisor-reverb.conf` | Reverb daemon supervisor program |
| `deploy/install-reverb.sh` | Bootstrap script for the daemon |
| `tests/Feature/Services/WhatsAppCloudApiCallingTest.php` | 5 service tests |
| `tests/Feature/Http/CallRouteTest.php` | 6 controller route tests |
| `tests/Feature/Console/CleanupStaleCallsTest.php` | 4 cleanup tests |

### Files to modify (~10)

| File | Change |
|---|---|
| `app/Models/User.php` | Add MIC_PENDING/GRANTED/DENIED constants |
| `app/Services/WhatsAppCloudApiService.php` | Add `preAcceptCall` and `acceptCall` methods |
| `app/Services/InboundCallProcessor.php` | Persist sdp_offer, fire preAcceptCall, broadcast CallRinging |
| `app/Http/Controllers/CallController.php` | Add `claim`, `answer`, `decline`, `hangup` methods |
| `routes/web.php` | 4 new routes inside conversations.reply group |
| `routes/console.php` | Schedule `calls:cleanup-stale` everyMinute |
| `routes/channels.php` | Authorize `user.{id}` private channel |
| `resources/views/livewire/realtime-pulse.blade.php` | Mount `<livewire:incoming-call :call="..." />` inside the existing call banner |
| `resources/js/app.js` | Initialize Echo + import calls.js Alpine factory |
| `composer.json` | Add laravel/reverb dependency (composer require) |
| `package.json` | Add laravel-echo + pusher-js dependencies (npm install) |
| `deploy/nginx.conf` | WebSocket upgrade location block at /app |
| `deploy.sh` | Step to call install-reverb.sh once on first deploy |
| `.env.example` | REVERB_* + VITE_REVERB_* entries |
| `tests/Feature/Webhooks/InboundCallProcessingTest.php` | 3 appended tests |

(Thirteen new files, fifteen modified.)

## Operational notes

- **First deploy needs Reverb installed.** `composer install` brings the package; `bash deploy/install-reverb.sh` registers the supervisor program; `sudo supervisorctl status` should show `blastiq-reverb` running.
- **WebSocket port 8080 is local-only.** Public access via HTTPS at `/app` through nginx — no new firewall opening needed.
- **Browser autoplay**: `<audio autoplay>` for the remote stream is permitted because user clicked Accept (a user gesture) before the audio element receives a stream.
- **HTTPS is mandatory for `getUserMedia`.** Production already serves over HTTPS.
- **Channel authorization uses Laravel session.** No new auth tokens. Echo passes the session cookie automatically when the page is same-origin (which it is — `/app` is on the same host).

## Known limitations / risks

- **The empty-SDP `pre_accept` path may not work** if Meta's current API requires the SDP answer in pre_accept too. If so, implementation will need to fall back to the alternative flow: server waits for agent's SDP answer, then fires pre_accept and accept back-to-back. This causes the audio-clipping issue Meta documents but the call still works.
- **STUN-only ICE may fail on restrictive corporate NATs.** Most home and office networks work fine. Phase 19 adds TURN.
- **Reverb is a new operational dependency.** Crashes are auto-restarted by supervisor; total outage means real-time signaling stops. Phase 19 should add a healthcheck.
- **No automated browser test of actual audio.** PHPUnit verifies the state machine + service calls + broadcast events. Manual smoke test required: ring a real number, answer in browser, talk, verify both directions.
- **Multiple-tab claim has a tiny race**: if both tabs POST `/claim` within the same database transaction window, atomic SQL guarantees one wins. But if a network blip retries the request, the same tab might "double-claim" (idempotent via the `OR session_id` check, no harm). Edge case documented for future maintainers.
- **Single-instance Reverb assumption.** Production runs one Reverb daemon. If the team scales to multiple app servers, Reverb supports horizontal scaling but the deploy story for that is Phase 20+.
- **Mic permission state column is advisory only.** The browser's permission API is the source of truth; our column is just a hint for UI. If the user revokes permission in browser settings, our column won't update until the next call attempt fails.

## Open follow-ups (not blockers)

- Real-time browser-side ICE candidate exchange might be needed if Meta's WebRTC doesn't bundle all candidates in the SDP answer. If so, we add a fourth event `CallIceCandidate` and a corresponding POST endpoint. Implementation will discover this on first prod call.
- The `mic_permission_state` column is currently advisory. A "Denied" banner on the dashboard helps users recover ("Click here to grant microphone access"); this is a small enhancement that fits in Phase 18 polish.
