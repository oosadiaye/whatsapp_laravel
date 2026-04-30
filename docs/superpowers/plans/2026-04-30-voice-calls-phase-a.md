# Voice Calls Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add voice-call logging + click-to-call to BlastIQ via Meta's WhatsApp Cloud Calling API. Webhooks capture inbound/outbound call events into a new `call_logs` table; a green call button in the chat header initiates outbound calls via Meta's API; an in-flight banner tracks live call state; a dedicated `/calls` page lists all calls across conversations.

**Architecture:** Mirrors Phase 12 exactly. `InboundCallProcessor` (parallel to `InboundMessageProcessor`) consumes the `calls` webhook field; `OutboundCallService` (parallel to `WhatsAppMessenger`) wraps the outbound API. Calls always link to a `Conversation` row (NOT NULL FK) so the existing inbox visibility/assignment rules apply for free. New `conversations.call` permission gates outbound dialing for cost control.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL/SQLite, Tailwind CSS, Alpine.js, Livewire 4 (existing wire:poll pattern), spatie/laravel-permission (Phase 11), PHPUnit 11.

---

## Spec reference

See `docs/superpowers/specs/2026-04-30-voice-calls-design.md` for the full design rationale.

## File structure

### Files to create (12)

| File | Responsibility |
|---|---|
| `database/migrations/2026_04_30_140000_create_call_logs_table.php` | Schema for call_logs |
| `app/Models/CallLog.php` | Eloquent model with relations + status state machine |
| `database/factories/CallLogFactory.php` | Test factory |
| `app/Services/InboundCallProcessor.php` | Parses `calls` webhook events, upserts call_logs |
| `app/Services/OutboundCallService.php` | Wraps Meta API for outbound calls + creates call_log row |
| `app/Http/Controllers/CallController.php` | Renders /calls cross-conversation page |
| `resources/views/calls/index.blade.php` | /calls page list view |
| `resources/views/conversations/_call_card.blade.php` | Inline call card partial in conversation thread |
| `app/Livewire/InFlightCall.blade.php` | Sticky banner that polls call status |
| `app/Livewire/InFlightCall.php` | Livewire component for the banner |
| `tests/Feature/Webhooks/InboundCallProcessingTest.php` | Webhook capture tests |
| `tests/Feature/Controllers/OutboundCallTest.php` | Click-to-call + endCall + permission tests |
| `tests/Feature/Controllers/CallsPageTest.php` | /calls page visibility + filter tests |

### Files to modify (5)

| File | Change |
|---|---|
| `database/seeders/RolesAndPermissionsSeeder.php` | Add `conversations.call` permission, grant to admin/manager/super_admin |
| `app/Services/WhatsAppCloudApiService.php` | Add `initiateCall()` and `endCall()` methods |
| `app/Http/Controllers/CloudWebhookController.php` | Dispatch `calls` field to InboundCallProcessor |
| `app/Http/Controllers/ConversationController.php` | Add `initiateCall()` and `endCall()` actions |
| `app/Models/Conversation.php` | Add `callLogs()` HasMany relation |
| `routes/web.php` | New routes for call actions and /calls page |
| `resources/views/conversations/show.blade.php` | Call button + modal + in-flight banner + inline call cards |
| `resources/views/layouts/navigation.blade.php` | Sidebar Calls link |

---

# Tasks

## Task 1: Add `conversations.call` permission

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php` (lines: insert into permissions array + grants)

- [ ] **Step 1: Add permission to seeder**

Open `database/seeders/RolesAndPermissionsSeeder.php`. Find the section that lists conversation permissions (around the `'conversations.view_all'` line). Add `'conversations.call'` to the permissions array:

```php
// Conversations / chat
'conversations.view_all',
'conversations.view_assigned',
'conversations.reply',
'conversations.assign',
'conversations.call',  // ADD THIS LINE
```

Then find the role grants section. Add `conversations.call` to the lists for super_admin, admin, and manager. **Do NOT add it to the agent role.**

- [ ] **Step 2: Re-run the seeder**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
```

Expected output: `INFO Seeding database. Database\Seeders\RolesAndPermissionsSeeder ............................. RUNNING / DONE`

- [ ] **Step 3: Verify permission exists and is granted correctly**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker <<'EOF'
$perm = Spatie\Permission\Models\Permission::where('name', 'conversations.call')->first();
echo "Permission exists: " . ($perm ? 'YES' : 'NO') . PHP_EOL;
foreach (['super_admin', 'admin', 'manager', 'agent'] as $role) {
    $r = Spatie\Permission\Models\Role::findByName($role);
    echo "  {$role}: " . ($r->hasPermissionTo('conversations.call') ? 'GRANTED' : 'not granted') . PHP_EOL;
}
EOF
```

Expected:
```
Permission exists: YES
  super_admin: GRANTED
  admin: GRANTED
  manager: GRANTED
  agent: not granted
```

- [ ] **Step 4: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php
git commit -m "feat(voice): add conversations.call permission for outbound calls"
```

---

## Task 2: Create `call_logs` migration

**Files:**
- Create: `database/migrations/2026_04_30_140000_create_call_logs_table.php`

- [ ] **Step 1: Create the migration file**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan make:migration create_call_logs_table
```

Then **rename** the generated file to `2026_04_30_140000_create_call_logs_table.php` (the timestamp matters for ordering).

- [ ] **Step 2: Write the schema**

Replace the migration file contents with:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * call_logs — one row per call event series, updated as Meta's webhook
 * delivers state changes (connect → accept → disconnect).
 *
 * Always linked to a Conversation (NOT NULL FK by design — see spec Q4).
 * Uses meta_call_id for idempotency: webhook retries find the same row
 * and update it instead of creating duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();
            $table->foreignId('contact_id')
                ->constrained('contacts')
                ->cascadeOnDelete();
            $table->foreignId('whatsapp_instance_id')
                ->constrained('whatsapp_instances')
                ->cascadeOnDelete();

            $table->enum('direction', ['inbound', 'outbound']);

            // Meta's call ID. Unique. Nullable for outbound calls between
            // API request and Meta's response coming back.
            $table->string('meta_call_id')->nullable()->unique();

            $table->enum('status', [
                'initiated',  // outbound: API fired, awaiting Meta confirmation
                'ringing',    // ring received on customer side
                'connected',  // accepted by either party
                'ended',      // normal hang-up after connection
                'missed',     // ringing timeout, no answer
                'declined',   // explicit reject
                'failed',     // API/network error before ringing
            ])->default('initiated');

            $table->string('from_phone', 20);
            $table->string('to_phone', 20);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->text('failure_reason')->nullable();

            // Outbound only: who clicked the call button. NULL for inbound.
            $table->foreignId('placed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Append-only event log: every webhook payload Meta sent for this
            // call, in chronological order. Used for debugging the timeline view.
            $table->json('raw_event_log')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['whatsapp_instance_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
```

- [ ] **Step 3: Run the migration**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan migrate
```

Expected: `2026_04_30_140000_create_call_logs_table ........................... DONE`

- [ ] **Step 4: Verify table structure**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute="echo collect(DB::select('SHOW COLUMNS FROM call_logs'))->pluck('Field')->implode(', ');"
```

Expected: comma-separated list including `id, conversation_id, contact_id, whatsapp_instance_id, direction, meta_call_id, status, from_phone, to_phone, started_at, connected_at, ended_at, duration_seconds, failure_reason, placed_by_user_id, raw_event_log, created_at, updated_at`

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_30_140000_create_call_logs_table.php
git commit -m "feat(voice): create call_logs table with state machine columns"
```

---

## Task 3: Create `CallLog` model + factory

**Files:**
- Create: `app/Models/CallLog.php`
- Create: `database/factories/CallLogFactory.php`

- [ ] **Step 1: Write the model**

Create `app/Models/CallLog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CallLog extends Model
{
    use HasFactory;

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_INITIATED = 'initiated';
    public const STATUS_RINGING = 'ringing';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ENDED = 'ended';
    public const STATUS_MISSED = 'missed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_FAILED = 'failed';

    public const STATUSES_IN_FLIGHT = [
        self::STATUS_INITIATED,
        self::STATUS_RINGING,
        self::STATUS_CONNECTED,
    ];

    public const STATUSES_TERMINAL = [
        self::STATUS_ENDED,
        self::STATUS_MISSED,
        self::STATUS_DECLINED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'conversation_id',
        'contact_id',
        'whatsapp_instance_id',
        'direction',
        'meta_call_id',
        'status',
        'from_phone',
        'to_phone',
        'started_at',
        'connected_at',
        'ended_at',
        'duration_seconds',
        'failure_reason',
        'placed_by_user_id',
        'raw_event_log',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'connected_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'raw_event_log' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function whatsappInstance(): BelongsTo
    {
        return $this->belongsTo(WhatsAppInstance::class, 'whatsapp_instance_id');
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, self::STATUSES_IN_FLIGHT, true);
    }

    /**
     * Append one webhook event payload to raw_event_log without overwriting
     * earlier events. Stores [{event, timestamp, payload}, ...].
     *
     * @param  array<string, mixed>  $payload  the raw webhook event body
     */
    public function appendRawEvent(string $event, array $payload): void
    {
        $log = $this->raw_event_log ?? [];
        $log[] = [
            'event' => $event,
            'timestamp' => Carbon::now()->toIso8601String(),
            'payload' => $payload,
        ];
        $this->raw_event_log = $log;
    }
}
```

- [ ] **Step 2: Write the factory**

Create `database/factories/CallLogFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallLog>
 */
class CallLogFactory extends Factory
{
    protected $model = CallLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'conversation_id' => Conversation::factory(),
            'contact_id' => Contact::factory()->state(['user_id' => $user]),
            'whatsapp_instance_id' => WhatsAppInstance::factory()->state(['user_id' => $user]),
            'direction' => CallLog::DIRECTION_INBOUND,
            'meta_call_id' => 'wacid.'.$this->faker->unique()->bothify('???###??'),
            'status' => CallLog::STATUS_ENDED,
            'from_phone' => $this->faker->numerify('234##########'),
            'to_phone' => $this->faker->numerify('234##########'),
            'started_at' => now()->subMinutes(5),
            'connected_at' => now()->subMinutes(4),
            'ended_at' => now()->subMinutes(2),
            'duration_seconds' => 120,
            'failure_reason' => null,
            'placed_by_user_id' => null,
            'raw_event_log' => [],
        ];
    }

    public function inFlight(): self
    {
        return $this->state([
            'status' => CallLog::STATUS_RINGING,
            'connected_at' => null,
            'ended_at' => null,
            'duration_seconds' => null,
        ]);
    }

    public function outbound(?User $placedBy = null): self
    {
        return $this->state([
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'placed_by_user_id' => $placedBy?->id ?? User::factory(),
        ]);
    }

    public function missed(): self
    {
        return $this->state([
            'status' => CallLog::STATUS_MISSED,
            'connected_at' => null,
            'duration_seconds' => 0,
            'ended_at' => now(),
        ]);
    }
}
```

- [ ] **Step 3: Smoke test the model**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker <<'EOF'
$c = App\Models\CallLog::factory()->make();
echo "direction: {$c->direction}, status: {$c->status}, duration: {$c->duration_seconds}s" . PHP_EOL;
echo "isInFlight: " . ($c->isInFlight() ? 'true' : 'false') . PHP_EOL;
EOF
```

Expected: `direction: inbound, status: ended, duration: 120s` and `isInFlight: false`

- [ ] **Step 4: Commit**

```bash
git add app/Models/CallLog.php database/factories/CallLogFactory.php
git commit -m "feat(voice): CallLog model + factory with state machine helpers"
```

---

## Task 4: Add `callLogs()` relation to Conversation

**Files:**
- Modify: `app/Models/Conversation.php` (add new relation method)

- [ ] **Step 1: Write the failing test**

Create or open `tests/Feature/Models/ConversationCallLogsRelationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\CallLog;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationCallLogsRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_exposes_call_logs_relation(): void
    {
        $conv = Conversation::factory()->create();
        CallLog::factory()->count(3)->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
        ]);

        $this->assertCount(3, $conv->callLogs);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Models/ConversationCallLogsRelationTest.php --no-coverage
```

Expected: FAIL with `Call to undefined method ... callLogs()` or similar.

- [ ] **Step 3: Add the relation**

Open `app/Models/Conversation.php`. Find the existing `messages()` relation method. Right after it, add:

```php
public function callLogs(): HasMany
{
    return $this->hasMany(CallLog::class)->orderBy('created_at');
}
```

- [ ] **Step 4: Run the test to confirm it passes**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Models/ConversationCallLogsRelationTest.php --no-coverage
```

Expected: `OK (1 test, 1 assertion)`

- [ ] **Step 5: Commit**

```bash
git add app/Models/Conversation.php tests/Feature/Models/ConversationCallLogsRelationTest.php
git commit -m "feat(voice): Conversation->callLogs() relation"
```

---

## Task 5: Create `InboundCallProcessor` service

**Files:**
- Create: `app/Services/InboundCallProcessor.php`
- Create: `tests/Feature/Webhooks/InboundCallProcessingTest.php`

- [ ] **Step 1: Write the failing test (covers all major event flows)**

Create `tests/Feature/Webhooks/InboundCallProcessingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsAppInstance;
use App\Services\InboundCallProcessor;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundCallProcessingTest extends TestCase
{
    use RefreshDatabase;

    private InboundCallProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new InboundCallProcessor(
            $this->app->make(WhatsAppCloudApiService::class),
        );
    }

    public function test_first_inbound_call_creates_contact_conversation_and_log(): void
    {
        $instance = WhatsAppInstance::factory()->create();

        $this->processor->processCalls($instance, [
            [
                'id' => 'wacid.first_call',
                'from' => '2348011111111',
                'to' => $instance->business_phone_number,
                'event' => 'connect',
                'timestamp' => '1714500000',
            ],
        ]);

        $contact = Contact::where('phone', '2348011111111')->first();
        $this->assertNotNull($contact, 'Contact should be created');
        $this->assertSame($instance->user_id, $contact->user_id);

        $conv = Conversation::where('contact_id', $contact->id)->first();
        $this->assertNotNull($conv, 'Conversation should be created');

        $call = CallLog::where('meta_call_id', 'wacid.first_call')->first();
        $this->assertNotNull($call);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame('ringing', $call->status);
        $this->assertSame($conv->id, $call->conversation_id);
    }

    public function test_duplicate_connect_event_is_idempotent(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $event = [
            'id' => 'wacid.dup',
            'from' => '2348011111111',
            'to' => $instance->business_phone_number,
            'event' => 'connect',
            'timestamp' => '1714500000',
        ];

        $this->processor->processCalls($instance, [$event]);
        $this->processor->processCalls($instance, [$event]);

        $this->assertSame(1, CallLog::count(), 'Webhook retries must not create duplicate rows');
    }

    public function test_accept_event_transitions_to_connected(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $this->processor->processCalls($instance, [
            ['id' => 'wacid.x', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'connect', 'timestamp' => '1714500000'],
        ]);

        $this->processor->processCalls($instance, [
            ['id' => 'wacid.x', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'accept', 'timestamp' => '1714500005'],
        ]);

        $call = CallLog::where('meta_call_id', 'wacid.x')->first();
        $this->assertSame('connected', $call->status);
        $this->assertNotNull($call->connected_at);
    }

    public function test_disconnect_event_calculates_duration(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $this->processor->processCalls($instance, [
            ['id' => 'wacid.y', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'connect', 'timestamp' => '1714500000'],
            ['id' => 'wacid.y', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'accept', 'timestamp' => '1714500010'],
            ['id' => 'wacid.y', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'disconnect', 'timestamp' => '1714500070'],
        ]);

        $call = CallLog::where('meta_call_id', 'wacid.y')->first();
        $this->assertSame('ended', $call->status);
        $this->assertNotNull($call->ended_at);
        $this->assertSame(60, $call->duration_seconds);
    }

    public function test_missed_event_sets_status_without_connected_at(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $this->processor->processCalls($instance, [
            ['id' => 'wacid.miss', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'missed', 'timestamp' => '1714500000'],
        ]);

        $call = CallLog::where('meta_call_id', 'wacid.miss')->first();
        $this->assertSame('missed', $call->status);
        $this->assertNull($call->connected_at);
    }

    public function test_existing_contact_reused(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $existing = Contact::create([
            'user_id' => $instance->user_id,
            'phone' => '2348099999999',
            'name' => 'Already Known',
        ]);

        $this->processor->processCalls($instance, [
            ['id' => 'wacid.known', 'from' => '2348099999999', 'to' => $instance->business_phone_number, 'event' => 'connect', 'timestamp' => '1714500000'],
        ]);

        $this->assertSame(1, Contact::count());
        $this->assertSame('Already Known', $existing->fresh()->name);
    }

    public function test_event_without_id_is_dropped(): void
    {
        $instance = WhatsAppInstance::factory()->create();

        $this->processor->processCalls($instance, [
            ['from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'connect'],
        ]);

        $this->assertSame(0, CallLog::count());
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/InboundCallProcessingTest.php --no-coverage
```

Expected: All 7 tests fail with `Class "App\Services\InboundCallProcessor" not found`

- [ ] **Step 3: Implement the service**

Create `app/Services/InboundCallProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsAppInstance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parses Meta's `calls` webhook field into local call_log rows.
 *
 * Mirrors {@see InboundMessageProcessor}'s structure. Each call event from
 * Meta updates the same call_log row (looked up by meta_call_id), so
 * webhook retries are idempotent.
 *
 * Event lifecycle:
 *   connect    → status=ringing, started_at set
 *   accept     → status=connected, connected_at set
 *   disconnect → status=ended, ended_at set, duration_seconds calculated
 *   missed     → status=missed, ended_at set (no connected_at)
 *   declined   → status=declined, ended_at set
 *   fail/error → status=failed, ended_at set
 *
 * Unknown events are appended to raw_event_log without changing status,
 * so future Meta event types don't break anything — they just log silently
 * for later inspection.
 */
class InboundCallProcessor
{
    public function __construct(
        private readonly WhatsAppCloudApiService $cloudApi,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $calls  from value.calls[]
     * @param  array<int, array<string, mixed>>  $contactsBlock  from value.contacts[]
     */
    public function processCalls(
        WhatsAppInstance $instance,
        array $calls,
        array $contactsBlock = [],
    ): void {
        $nameByPhone = [];
        foreach ($contactsBlock as $c) {
            $waId = (string) ($c['wa_id'] ?? '');
            $name = (string) ($c['profile']['name'] ?? '');
            if ($waId !== '' && $name !== '') {
                $nameByPhone[$waId] = $name;
            }
        }

        foreach ($calls as $event) {
            try {
                $this->processOne($instance, $event, $nameByPhone);
            } catch (Throwable $e) {
                Log::error('Inbound call event processing failed', [
                    'instance_id' => $instance->id,
                    'wacid' => $event['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, string>  $nameByPhone
     */
    private function processOne(WhatsAppInstance $instance, array $event, array $nameByPhone): void
    {
        $callId = (string) ($event['id'] ?? '');
        if ($callId === '') {
            return;
        }

        $eventName = strtolower((string) ($event['event'] ?? ''));
        $fromPhone = (string) ($event['from'] ?? '');
        $toPhone = (string) ($event['to'] ?? '');

        $callLog = CallLog::where('meta_call_id', $callId)->first();

        if ($callLog === null) {
            // First time seeing this call. Find/create contact + conversation.
            if ($fromPhone === '') {
                return;  // Can't infer contact without a phone
            }

            $contact = $this->findOrCreateContact($instance, $fromPhone, $nameByPhone[$fromPhone] ?? null);
            $conversation = $this->findOrCreateConversation($instance, $contact);

            $callLog = CallLog::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'whatsapp_instance_id' => $instance->id,
                'direction' => CallLog::DIRECTION_INBOUND,
                'meta_call_id' => $callId,
                'status' => CallLog::STATUS_RINGING,
                'from_phone' => $fromPhone,
                'to_phone' => $toPhone,
                'started_at' => $this->eventTime($event),
            ]);
        }

        // Apply the state transition for the new event
        $this->applyEvent($callLog, $eventName, $event);
    }

    private function applyEvent(CallLog $callLog, string $eventName, array $payload): void
    {
        $eventTime = $this->eventTime($payload);
        $callLog->appendRawEvent($eventName, $payload);

        switch ($eventName) {
            case 'connect':
                // First-event case is handled by processOne above.
                // If the row was already created, this is a duplicate connect — no-op.
                break;

            case 'accept':
            case 'connect_complete':
                $callLog->status = CallLog::STATUS_CONNECTED;
                $callLog->connected_at = $eventTime;
                break;

            case 'disconnect':
                $callLog->status = CallLog::STATUS_ENDED;
                $callLog->ended_at = $eventTime;
                if ($callLog->connected_at !== null) {
                    $callLog->duration_seconds = $eventTime->diffInSeconds($callLog->connected_at);
                }
                break;

            case 'missed':
            case 'no_answer':
                $callLog->status = CallLog::STATUS_MISSED;
                $callLog->ended_at = $eventTime;
                break;

            case 'reject':
            case 'declined':
                $callLog->status = CallLog::STATUS_DECLINED;
                $callLog->ended_at = $eventTime;
                $callLog->failure_reason = (string) ($payload['reason'] ?? 'Declined by recipient');
                break;

            case 'fail':
            case 'error':
                $callLog->status = CallLog::STATUS_FAILED;
                $callLog->ended_at = $eventTime;
                $callLog->failure_reason = (string) ($payload['error']['message'] ?? 'Call failed');
                break;

            default:
                // Unknown events: log already appended above, no status change.
                Log::warning('Unknown call event from Meta', [
                    'event' => $eventName,
                    'wacid' => $callLog->meta_call_id,
                ]);
        }

        $callLog->save();
    }

    private function eventTime(array $event): Carbon
    {
        return isset($event['timestamp'])
            ? Carbon::createFromTimestamp((int) $event['timestamp'])
            : Carbon::now();
    }

    private function findOrCreateContact(
        WhatsAppInstance $instance,
        string $phone,
        ?string $whatsappProfileName,
    ): Contact {
        return Contact::firstOrCreate(
            ['user_id' => $instance->user_id, 'phone' => $phone],
            ['name' => $whatsappProfileName ?? $phone, 'is_active' => true],
        );
    }

    private function findOrCreateConversation(WhatsAppInstance $instance, Contact $contact): Conversation
    {
        return Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $instance->user_id, 'unread_count' => 0],
        );
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/InboundCallProcessingTest.php --no-coverage
```

Expected: `OK (7 tests, X assertions)`

- [ ] **Step 5: Commit**

```bash
git add app/Services/InboundCallProcessor.php tests/Feature/Webhooks/InboundCallProcessingTest.php
git commit -m "feat(voice): InboundCallProcessor service with event lifecycle"
```

---

## Task 6: Wire `InboundCallProcessor` into `CloudWebhookController`

**Files:**
- Modify: `app/Http/Controllers/CloudWebhookController.php`

- [ ] **Step 1: Write the failing integration test**

Append to `tests/Feature/Webhooks/InboundCallProcessingTest.php`:

```php
public function test_webhook_with_calls_field_dispatches_to_processor(): void
{
    $instance = WhatsAppInstance::factory()->create(['app_secret' => 'TEST_SECRET']);

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA',
            'changes' => [[
                'field' => 'calls',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['phone_number_id' => $instance->phone_number_id],
                    'calls' => [[
                        'id' => 'wacid.via_webhook',
                        'from' => '2348012345678',
                        'to' => $instance->business_phone_number,
                        'event' => 'connect',
                        'timestamp' => '1714500000',
                    ]],
                ],
            ]],
        ]],
    ];

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'TEST_SECRET');

    $this->call(
        'POST',
        route('webhook.cloud.handle', $instance),
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature],
        $body,
    )->assertOk();

    $this->assertSame(1, \App\Models\CallLog::count());
}
```

- [ ] **Step 2: Run the new test to confirm it fails**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/InboundCallProcessingTest.php::InboundCallProcessingTest::test_webhook_with_calls_field_dispatches_to_processor --no-coverage
```

Expected: FAIL — `assertSame failed: 0 vs 1` because the webhook controller doesn't dispatch the calls field yet.

- [ ] **Step 3: Modify the controller**

Open `app/Http/Controllers/CloudWebhookController.php`. Find the `__construct` method:

```php
public function __construct(
    private readonly InboundMessageProcessor $inboundProcessor,
) {}
```

Change it to:

```php
public function __construct(
    private readonly InboundMessageProcessor $inboundProcessor,
    private readonly InboundCallProcessor $inboundCallProcessor,
) {}
```

Add the import at the top of the file:

```php
use App\Services\InboundCallProcessor;
```

Then find the loop in `handle()` that processes `value`. Currently it has:

```php
foreach ((array) ($entry['changes'] ?? []) as $change) {
    if (($change['field'] ?? '') !== 'messages') {
        continue;
    }

    $value = (array) ($change['value'] ?? []);
    $this->processStatuses($value['statuses'] ?? []);

    // Inbound messages from contacts → conversation thread.
    $this->inboundProcessor->processMessages(
        $instance,
        (array) ($value['messages'] ?? []),
        (array) ($value['contacts'] ?? []),
    );
}
```

Replace it with:

```php
foreach ((array) ($entry['changes'] ?? []) as $change) {
    $field = $change['field'] ?? '';
    $value = (array) ($change['value'] ?? []);

    match ($field) {
        'messages' => $this->handleMessagesField($value),
        'calls' => $this->inboundCallProcessor->processCalls(
            $instance,
            (array) ($value['calls'] ?? []),
            (array) ($value['contacts'] ?? []),
        ),
        default => null,  // unknown field — log silently
    };
}
```

Then add a private helper method right before `processStatuses()`:

```php
/**
 * @param  array<string, mixed>  $value
 */
private function handleMessagesField(array $value): void
{
    $this->processStatuses($value['statuses'] ?? []);
    $this->inboundProcessor->processMessages(
        // The instance variable is closure-captured from handle() — pull it via a property
        // or change handleMessagesField signature. For minimal diff, see Step 4 alternative.
        WhatsAppInstance::find(request()->route('instance')->id ?? null),
        (array) ($value['messages'] ?? []),
        (array) ($value['contacts'] ?? []),
    );
}
```

**Wait — that's awkward.** Let me revise to keep the instance scoped.

- [ ] **Step 4: Better approach — use a private helper that takes the instance**

Discard Step 3's helper. Instead, refactor the loop to keep the instance in scope:

```php
foreach ((array) ($entry['changes'] ?? []) as $change) {
    $field = $change['field'] ?? '';
    $value = (array) ($change['value'] ?? []);

    if ($field === 'messages') {
        $this->processStatuses($value['statuses'] ?? []);
        $this->inboundProcessor->processMessages(
            $instance,
            (array) ($value['messages'] ?? []),
            (array) ($value['contacts'] ?? []),
        );
    } elseif ($field === 'calls') {
        $this->inboundCallProcessor->processCalls(
            $instance,
            (array) ($value['calls'] ?? []),
            (array) ($value['contacts'] ?? []),
        );
    }
    // Unknown fields are silently ignored
}
```

- [ ] **Step 5: Run the new test to confirm it passes**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/InboundCallProcessingTest.php --no-coverage
```

Expected: All 8 tests pass.

- [ ] **Step 6: Run full webhook test suite to ensure nothing else broke**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Webhooks/ --no-coverage
```

Expected: existing CloudWebhookTest's tests + InboundMessageProcessingTest's tests all still pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/CloudWebhookController.php tests/Feature/Webhooks/InboundCallProcessingTest.php
git commit -m "feat(voice): wire calls webhook field into CloudWebhookController"
```

---

## Task 7: Add `WhatsAppCloudApiService::initiateCall()` method

**Files:**
- Modify: `app/Services/WhatsAppCloudApiService.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Services/WhatsAppCloudApiServiceTest.php`:

```php
public function test_initiate_call_posts_to_calls_endpoint_with_phone(): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'calls' => [['id' => 'wacid.outbound_xyz']],
        ], 200),
    ]);

    $result = $this->service->initiateCall($this->instance, '2348012345678');

    $this->assertSame('wacid.outbound_xyz', $result);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/v20.0/PHONE_ID_FAKE/calls')
            && $request->header('Authorization')[0] === 'Bearer ACCESS_TOKEN_FAKE'
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '2348012345678';
    });
}

public function test_initiate_call_throws_on_meta_error(): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Number not registered'],
        ], 400),
    ]);

    $this->expectException(WhatsAppApiException::class);
    $this->expectExceptionMessageMatches('/initiate call/i');

    $this->service->initiateCall($this->instance, '234');
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/WhatsAppCloudApiServiceTest.php --no-coverage
```

Expected: 2 failing tests with `Call to undefined method ... initiateCall()`.

- [ ] **Step 3: Implement the method**

Open `app/Services/WhatsAppCloudApiService.php`. After `sendTemplate()` and before the existing `markAsRead()` method, add:

```php
/**
 * Initiate an outbound call to the given phone number via Meta's Cloud
 * Calling API. The call will ring on the customer's WhatsApp; audio
 * terminates wherever the WhatsApp Business app is registered for this
 * phone_number_id.
 *
 * Endpoint: POST /v20.0/{phone_number_id}/calls
 *
 * @return string  Meta's call ID (wacid.xxx) — store on call_log for webhook correlation
 *
 * @throws WhatsAppApiException
 */
public function initiateCall(WhatsAppInstance $instance, string $phone): string
{
    $response = $this->client($instance)->post(
        $this->url("{$instance->phone_number_id}/calls"),
        [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizePhone($phone),
        ],
    );

    if ($response->failed()) {
        $this->logHttp('initiateCall', $instance, $response->status(), $response->body());

        throw new WhatsAppApiException(
            "Failed to initiate call: {$response->status()} - {$response->body()}"
        );
    }

    $body = $response->json();

    return (string) ($body['calls'][0]['id'] ?? throw new WhatsAppApiException(
        'Meta accepted the call request but returned no call ID — cannot correlate with future webhooks.'
    ));
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/WhatsAppCloudApiServiceTest.php --no-coverage
```

Expected: all tests pass (existing 9 + 2 new).

- [ ] **Step 5: Commit**

```bash
git add app/Services/WhatsAppCloudApiService.php tests/Feature/Services/WhatsAppCloudApiServiceTest.php
git commit -m "feat(voice): WhatsAppCloudApiService::initiateCall for outbound dialing"
```

---

## Task 8: Create `OutboundCallService`

**Files:**
- Create: `app/Services/OutboundCallService.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/OutboundCallServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use App\Services\OutboundCallService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundCallServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiate_creates_call_log_with_meta_id_and_user_attribution(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'calls' => [['id' => 'wacid.outbound_test']],
        ], 200)]);

        $user = User::factory()->create();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        $contact = Contact::factory()->create(['user_id' => $user->id, 'phone' => '2348011122233']);
        $conv = Conversation::factory()->create([
            'user_id' => $user->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        $service = new OutboundCallService($this->app->make(WhatsAppCloudApiService::class));

        $callLog = $service->initiate($conv, $user);

        $this->assertNotNull($callLog->id);
        $this->assertSame('outbound', $callLog->direction);
        $this->assertSame('initiated', $callLog->status);
        $this->assertSame('wacid.outbound_test', $callLog->meta_call_id);
        $this->assertSame($user->id, $callLog->placed_by_user_id);
        $this->assertSame($conv->id, $callLog->conversation_id);
        $this->assertSame($contact->phone, $callLog->to_phone);
    }
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/OutboundCallServiceTest.php --no-coverage
```

Expected: FAIL — `Class "App\Services\OutboundCallService" not found`.

- [ ] **Step 3: Implement the service**

Create `app/Services/OutboundCallService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;

/**
 * Wraps {@see WhatsAppCloudApiService} for outbound call orchestration:
 *  - call Meta's API to initiate the dial
 *  - create a corresponding call_log row with the returned Meta call ID
 *
 * Subsequent webhook events from Meta update the same call_log via
 * {@see InboundCallProcessor}, identified by meta_call_id.
 */
class OutboundCallService
{
    public function __construct(
        private readonly WhatsAppCloudApiService $cloudApi,
    ) {
    }

    /**
     * @throws WhatsAppApiException  if Meta rejects the call request
     */
    public function initiate(Conversation $conversation, User $placedBy): CallLog
    {
        $instance = $conversation->whatsappInstance;
        $contact = $conversation->contact;

        $metaCallId = $this->cloudApi->initiateCall($instance, $contact->phone);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'meta_call_id' => $metaCallId,
            'status' => CallLog::STATUS_INITIATED,
            'from_phone' => (string) ($instance->business_phone_number ?? $instance->phone_number_id),
            'to_phone' => $contact->phone,
            'started_at' => now(),
            'placed_by_user_id' => $placedBy->id,
            'raw_event_log' => [],
        ]);
    }
}
```

- [ ] **Step 4: Run test to confirm it passes**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/OutboundCallServiceTest.php --no-coverage
```

Expected: `OK (1 test, X assertions)`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/OutboundCallService.php tests/Feature/Services/OutboundCallServiceTest.php
git commit -m "feat(voice): OutboundCallService creates call_log on Meta-accepted dial"
```

---

## Task 9: Add `ConversationController::initiateCall` action + route

**Files:**
- Modify: `app/Http/Controllers/ConversationController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Controllers/OutboundCallTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Controllers/OutboundCallTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_initiate_call(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'calls' => [['id' => 'wacid.test']],
        ], 200)]);

        $this->actingAs($admin)
            ->post(route('conversations.initiateCall', $conv))
            ->assertRedirect(route('conversations.show', $conv))
            ->assertSessionHas('success');

        $this->assertSame(1, CallLog::count());
        $call = CallLog::first();
        $this->assertSame('outbound', $call->direction);
        $this->assertSame($admin->id, $call->placed_by_user_id);
    }

    public function test_agent_without_call_permission_gets_403(): void
    {
        $agent = $this->makeUser('agent');
        $admin = $this->makeUser('admin', 'admin@example.com');
        $conv = Conversation::factory()->assignedTo($agent)->create(['user_id' => $admin->id]);

        Http::fake();

        $this->actingAs($agent)
            ->post(route('conversations.initiateCall', $conv))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, CallLog::count());
    }

    public function test_cross_account_call_is_forbidden(): void
    {
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $convOfB = Conversation::factory()->create(['user_id' => $userB->id]);

        Http::fake();

        $this->actingAs($userA)
            ->post(route('conversations.initiateCall', $convOfB))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_meta_failure_does_not_create_orphan_call_log(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Customer not callable'],
        ], 400)]);

        $this->actingAs($admin)
            ->post(route('conversations.initiateCall', $conv))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, CallLog::count());
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

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/OutboundCallTest.php --no-coverage
```

Expected: FAIL — `Route [conversations.initiateCall] not defined.`

- [ ] **Step 3: Add the controller action**

Open `app/Http/Controllers/ConversationController.php`. Add the import at the top:

```php
use App\Services\OutboundCallService;
use App\Exceptions\WhatsAppApiException;
```

Update the constructor to inject `OutboundCallService`:

```php
public function __construct(
    private readonly WhatsAppMessenger $messenger,
    private readonly OutboundCallService $outboundCalls,
) {
}
```

After the existing `assign()` method, add:

```php
/**
 * Place an outbound call from this conversation's instance to its contact.
 * Permission gated via route middleware (`conversations.call`).
 *
 * @throws WhatsAppApiException  if Meta rejects the call (caught and surfaced
 *                                as a flash error, no call_log row created)
 */
public function initiateCall(Request $request, Conversation $conversation): RedirectResponse
{
    $this->authorizeConversationAccess($request, $conversation);

    try {
        $this->outboundCalls->initiate($conversation, $request->user());
    } catch (WhatsAppApiException $e) {
        return redirect()
            ->route('conversations.show', $conversation)
            ->with('error', "Could not place call: {$e->getMessage()}");
    }

    return redirect()
        ->route('conversations.show', $conversation)
        ->with('success', "Calling {$conversation->contact->name}...");
}
```

- [ ] **Step 4: Add the route**

Open `routes/web.php`. Find the existing conversations.assign route group:

```php
Route::middleware('permission:conversations.assign')->group(function () {
    Route::post('/conversations/{conversation}/assign', ...);
});
```

Right after that group, add a new permission-gated group:

```php
Route::middleware('permission:conversations.call')->group(function () {
    Route::post('/conversations/{conversation}/call', [ConversationController::class, 'initiateCall'])->name('conversations.initiateCall');
});
```

- [ ] **Step 5: Run tests to confirm they pass**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/OutboundCallTest.php --no-coverage
```

Expected: `OK (4 tests, X assertions)`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ConversationController.php routes/web.php tests/Feature/Controllers/OutboundCallTest.php
git commit -m "feat(voice): POST /conversations/{c}/call action with permission gate"
```

---

## Task 10: Add `WhatsAppCloudApiService::endCall()` + `OutboundCallService::end()`

**Files:**
- Modify: `app/Services/WhatsAppCloudApiService.php`
- Modify: `app/Services/OutboundCallService.php`

- [ ] **Step 1: Write the failing test (cloud service level)**

Append to `tests/Feature/Services/WhatsAppCloudApiServiceTest.php`:

```php
public function test_end_call_posts_to_terminate_endpoint(): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $this->service->endCall($this->instance, 'wacid.live_call');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/v20.0/PHONE_ID_FAKE/calls')
            && $request['messaging_product'] === 'whatsapp'
            && $request['call_id'] === 'wacid.live_call'
            && $request['action'] === 'terminate';
    });
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/WhatsAppCloudApiServiceTest.php --no-coverage --filter test_end_call
```

Expected: FAIL — `Call to undefined method ... endCall()`.

- [ ] **Step 3: Add `endCall()` to WhatsAppCloudApiService**

In `app/Services/WhatsAppCloudApiService.php`, right after `initiateCall()`, add:

```php
/**
 * Hang up an in-flight outbound call.
 *
 * Endpoint pattern based on Meta's Calling API conventions: POST /calls
 * with action=terminate and call_id. Exact endpoint may differ — verify
 * during implementation against Meta's published Calling API docs.
 *
 * @throws WhatsAppApiException
 */
public function endCall(WhatsAppInstance $instance, string $metaCallId): void
{
    $response = $this->client($instance)->post(
        $this->url("{$instance->phone_number_id}/calls"),
        [
            'messaging_product' => 'whatsapp',
            'call_id' => $metaCallId,
            'action' => 'terminate',
        ],
    );

    if ($response->failed()) {
        $this->logHttp('endCall', $instance, $response->status(), $response->body());

        throw new WhatsAppApiException(
            "Failed to end call: {$response->status()} - {$response->body()}"
        );
    }
}
```

- [ ] **Step 4: Add `OutboundCallService::end()` and write its test**

Append to `tests/Feature/Services/OutboundCallServiceTest.php`:

```php
public function test_end_marks_call_log_as_ended_optimistically(): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    $callLog = CallLog::factory()->inFlight()->create([
        'meta_call_id' => 'wacid.live',
    ]);

    $service = new OutboundCallService($this->app->make(WhatsAppCloudApiService::class));
    $service->end($callLog);

    $callLog->refresh();
    $this->assertSame('ended', $callLog->status);
    $this->assertNotNull($callLog->ended_at);
}
```

Run it to confirm it fails:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/OutboundCallServiceTest.php --no-coverage --filter test_end
```

Expected: `Call to undefined method ... end()`.

Then add the method to `app/Services/OutboundCallService.php` after `initiate()`:

```php
/**
 * Hang up an in-flight call. Updates the call_log optimistically to
 * status=ended; if Meta's API rejects, the call is still on at Meta's
 * side — caller can retry or wait for the natural disconnect webhook.
 *
 * @throws WhatsAppApiException
 */
public function end(CallLog $callLog): void
{
    if ($callLog->meta_call_id === null) {
        throw new WhatsAppApiException(
            'Cannot end call without meta_call_id (was the call ever initiated successfully?)'
        );
    }

    $this->cloudApi->endCall($callLog->whatsappInstance, $callLog->meta_call_id);

    $callLog->update([
        'status' => CallLog::STATUS_ENDED,
        'ended_at' => now(),
    ]);
}
```

- [ ] **Step 5: Run all related tests**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Services/ --no-coverage
```

Expected: all service tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WhatsAppCloudApiService.php app/Services/OutboundCallService.php tests/Feature/Services/
git commit -m "feat(voice): endCall (Cloud API + OutboundCallService) for hang-up"
```

---

## Task 11: Add `ConversationController::endCall` action + route

**Files:**
- Modify: `app/Http/Controllers/ConversationController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Controllers/OutboundCallTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Controllers/OutboundCallTest.php`:

```php
public function test_admin_can_end_in_flight_call(): void
{
    $admin = $this->makeUser('admin');
    $conv = Conversation::factory()->create(['user_id' => $admin->id]);
    $callLog = \App\Models\CallLog::factory()->inFlight()->outbound($admin)->create([
        'conversation_id' => $conv->id,
        'contact_id' => $conv->contact_id,
        'whatsapp_instance_id' => $conv->whatsapp_instance_id,
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    $this->actingAs($admin)
        ->post(route('conversations.endCall', ['conversation' => $conv, 'call' => $callLog]))
        ->assertRedirect();

    $callLog->refresh();
    $this->assertSame('ended', $callLog->status);
}

public function test_end_call_for_other_account_is_forbidden(): void
{
    $userA = $this->makeUser('admin');
    $userB = $this->makeUser('admin', 'b@example.com');
    $convB = Conversation::factory()->create(['user_id' => $userB->id]);
    $callB = \App\Models\CallLog::factory()->inFlight()->outbound($userB)->create([
        'conversation_id' => $convB->id,
        'contact_id' => $convB->contact_id,
        'whatsapp_instance_id' => $convB->whatsapp_instance_id,
    ]);

    Http::fake();

    $this->actingAs($userA)
        ->post(route('conversations.endCall', ['conversation' => $convB, 'call' => $callB]))
        ->assertForbidden();

    Http::assertNothingSent();
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/OutboundCallTest.php --no-coverage --filter end_call
```

Expected: FAIL — route not defined.

- [ ] **Step 3: Add the action**

In `app/Http/Controllers/ConversationController.php`, add `use App\Models\CallLog;` at the top, then add this method right after `initiateCall()`:

```php
/**
 * End an in-flight outbound call. Mirrors initiateCall's permission
 * checks (same route middleware, same access guard).
 */
public function endCall(Request $request, Conversation $conversation, CallLog $call): RedirectResponse
{
    $this->authorizeConversationAccess($request, $conversation);

    if ($call->conversation_id !== $conversation->id) {
        abort(404);
    }

    if (! $call->isInFlight()) {
        return redirect()->route('conversations.show', $conversation)
            ->with('warning', 'Call is no longer in flight; nothing to end.');
    }

    try {
        $this->outboundCalls->end($call);
    } catch (WhatsAppApiException $e) {
        return redirect()->route('conversations.show', $conversation)
            ->with('error', "Could not end call: {$e->getMessage()}");
    }

    return redirect()->route('conversations.show', $conversation)
        ->with('success', 'Call ended.');
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the same `permission:conversations.call` group as `initiateCall`, add:

```php
Route::post('/conversations/{conversation}/calls/{call}/end',
    [ConversationController::class, 'endCall'])
    ->name('conversations.endCall');
```

- [ ] **Step 5: Run tests to confirm they pass**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/OutboundCallTest.php --no-coverage
```

Expected: all 6 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ConversationController.php routes/web.php tests/Feature/Controllers/OutboundCallTest.php
git commit -m "feat(voice): POST /conversations/{c}/calls/{call}/end for hang-up"
```

---

## Task 12: Inline call cards in conversation thread

**Files:**
- Create: `resources/views/conversations/_call_card.blade.php`
- Modify: `resources/views/conversations/show.blade.php`
- Modify: `app/Http/Controllers/ConversationController.php` (load callLogs on show)

- [ ] **Step 1: Update the controller to eager-load call_logs**

In `app/Http/Controllers/ConversationController.php`, find the `show()` method. After `$messages = $conversation->messages()->with('sentBy')->get();`, add:

```php
$callLogs = $conversation->callLogs()->with('placedBy')->get();

// Merge messages and call_logs into one chronological timeline
$timeline = $messages->concat($callLogs)->sortBy('created_at')->values();
```

Then update the view return so the timeline is passed:

```php
return view('conversations.show', [
    'conversation' => $conversation->load(['contact', 'whatsappInstance', 'assignedTo']),
    'messages' => $messages,
    'callLogs' => $callLogs,
    'timeline' => $timeline,
    'templates' => $templates,
    'assignableStaff' => $assignableStaff,
]);
```

- [ ] **Step 2: Create the call card partial**

Create `resources/views/conversations/_call_card.blade.php`:

```blade
@php
    /** @var \App\Models\CallLog $call */

    $isInbound = $call->isInbound();
    $borderClass = match($call->status) {
        'connected', 'ended' => 'border-emerald-200 bg-emerald-50',
        'missed' => 'border-amber-200 bg-amber-50',
        'declined', 'failed' => 'border-red-200 bg-red-50',
        default => 'border-blue-200 bg-blue-50',
    };
    $iconClass = match($call->status) {
        'connected', 'ended' => 'text-emerald-700',
        'missed' => 'text-amber-700',
        'declined', 'failed' => 'text-red-700',
        default => 'text-blue-700',
    };
    $directionLabel = $isInbound ? 'Inbound call' : 'Outbound call';
    $statusLabel = match($call->status) {
        'ended' => $call->duration_seconds > 0
            ? gmdate('i:s', $call->duration_seconds)
            : 'Ended',
        'missed' => 'No answer',
        'declined' => 'Declined',
        'failed' => 'Failed',
        'ringing' => 'Ringing...',
        'connected' => 'Connected · live',
        'initiated' => 'Connecting...',
        default => $call->status,
    };
@endphp

<div class="flex justify-center my-2">
    <div class="rounded-lg border {{ $borderClass }} px-4 py-2.5 text-sm max-w-md">
        <div class="flex items-center gap-3">
            <svg class="w-4 h-4 flex-shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                @if($isInbound)
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                @else
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                @endif
            </svg>
            <span class="font-medium text-gray-800">{{ $directionLabel }}</span>
            <span class="{{ $iconClass }}">·</span>
            <span class="text-gray-700">{{ $statusLabel }}</span>
            <span class="text-xs text-gray-500 ml-auto">{{ $call->created_at->format('H:i') }}</span>
        </div>

        @if($call->placedBy && ! $isInbound)
            <p class="mt-1 text-xs text-gray-500">Placed by {{ $call->placedBy->name }}</p>
        @endif

        @if($call->failure_reason)
            <p class="mt-1 text-xs text-red-700">{{ $call->failure_reason }}</p>
        @endif
    </div>
</div>
```

- [ ] **Step 3: Update show.blade.php to render the timeline**

Open `resources/views/conversations/show.blade.php`. Find the message-thread loop (search for `@foreach($messages as $message)`). Replace it with:

```blade
@forelse($timeline as $item)
    @if($item instanceof \App\Models\CallLog)
        @include('conversations._call_card', ['call' => $item])
    @else
        @php /** @var \App\Models\ConversationMessage $message */ $message = $item; @endphp
        {{-- existing message bubble rendering — KEEP whatever was inside the original @foreach loop --}}
        <div class="flex {{ $message->isInbound() ? 'justify-start' : 'justify-end' }}">
            <div class="max-w-md rounded-lg p-3 shadow {{ $message->isInbound() ? 'bg-white' : 'bg-[#dcf8c6]' }}">
                {{-- existing media rendering preserved as-is --}}
                @if($message->hasMedia())
                    @php $mime = $message->media_mime ?? ''; @endphp
                    @if(str_starts_with($mime, 'image/'))
                        <a href="{{ route('conversations.media', $message) }}" target="_blank" class="block mb-2">
                            <img src="{{ route('conversations.media', $message) }}" alt="" class="rounded max-w-full max-h-64">
                        </a>
                    @elseif(str_starts_with($mime, 'audio/'))
                        <audio controls class="w-full mb-2">
                            <source src="{{ route('conversations.media', $message) }}" type="{{ $mime }}">
                        </audio>
                    @elseif(str_starts_with($mime, 'video/'))
                        <video controls class="w-full max-h-64 mb-2 rounded">
                            <source src="{{ route('conversations.media', $message) }}" type="{{ $mime }}">
                        </video>
                    @else
                        <a href="{{ route('conversations.media', $message) }}" target="_blank"
                           class="flex items-center gap-2 text-sm text-blue-600 hover:underline mb-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            {{ __('Download') }} ({{ round(($message->media_size_bytes ?? 0) / 1024) }}KB)
                        </a>
                    @endif
                @endif

                @if($message->body)
                    <p class="text-sm text-gray-800 whitespace-pre-wrap break-words">{{ $message->body }}</p>
                @endif

                <div class="flex items-center justify-end gap-1 mt-1 text-xs text-gray-500">
                    @if(! $message->isInbound() && $message->sentBy)
                        <span class="text-gray-400">{{ $message->sentBy->name }} ·</span>
                    @endif
                    <span>{{ $message->created_at->format('H:i') }}</span>
                </div>
            </div>
        </div>
    @endif
@empty
    <p class="text-center text-gray-500 text-sm py-12">{{ __('No messages or calls yet.') }}</p>
@endforelse
```

- [ ] **Step 4: Manual smoke test**

Open the local dev server (start it if not running per `.claude/launch.json`):

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 -S 127.0.0.1:8000 -t public public/index.php &
```

Login at http://127.0.0.1:8000/login as admin@blastiq.com / password.

Open tinker and seed a sample conversation with both messages and calls:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker <<'EOF'
$user = App\Models\User::first();
$inst = App\Models\WhatsAppInstance::factory()->create(['user_id' => $user->id]);
$contact = App\Models\Contact::factory()->create(['user_id' => $user->id, 'name' => 'Test Caller']);
$conv = App\Models\Conversation::factory()->create([
    'user_id' => $user->id,
    'contact_id' => $contact->id,
    'whatsapp_instance_id' => $inst->id,
]);
App\Models\ConversationMessage::create([
    'conversation_id' => $conv->id,
    'direction' => 'inbound',
    'whatsapp_message_id' => 'wamid.test1',
    'type' => 'text',
    'body' => 'Hi, can you help?',
    'received_at' => now()->subMinutes(10),
]);
App\Models\CallLog::factory()->create([
    'conversation_id' => $conv->id,
    'contact_id' => $contact->id,
    'whatsapp_instance_id' => $inst->id,
    'status' => 'ended',
    'created_at' => now()->subMinutes(8),
    'ended_at' => now()->subMinutes(8),
    'duration_seconds' => 180,
]);
App\Models\ConversationMessage::create([
    'conversation_id' => $conv->id,
    'direction' => 'outbound',
    'whatsapp_message_id' => 'wamid.test2',
    'type' => 'text',
    'body' => 'Did the call resolve it?',
    'sent_by_user_id' => $user->id,
    'received_at' => now()->subMinutes(5),
]);
echo "Conversation: " . route('conversations.show', $conv) . PHP_EOL;
EOF
```

Visit the printed URL. Verify call card appears between the two messages, sorted chronologically.

- [ ] **Step 5: Run full conversation tests to ensure nothing regressed**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/ConversationControllerTest.php --no-coverage
```

Expected: all existing conversation tests still pass.

- [ ] **Step 6: Commit**

```bash
git add resources/views/conversations/_call_card.blade.php resources/views/conversations/show.blade.php app/Http/Controllers/ConversationController.php
git commit -m "feat(voice): inline call cards mixed chronologically with messages"
```

---

## Task 13: Add call button + confirmation modal

**Files:**
- Modify: `resources/views/conversations/show.blade.php`

- [ ] **Step 1: Add the green call button to the chat header**

In `resources/views/conversations/show.blade.php`, find the page header (`<x-slot name="header">`). Inside it, after the contact name display, add (the button is gated by the `conversations.call` permission so users without it never see it):

```blade
@can('conversations.call')
    <button type="button"
            x-data="{ open: false }"
            @click="open = true"
            class="ml-auto inline-flex items-center justify-center w-10 h-10 rounded-full bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition"
            title="Call {{ $conversation->contact->name }}">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
        </svg>

        {{-- Confirmation modal — Alpine x-data scope continues here --}}
        <template x-teleport="body">
            <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 @click.self="open = false">
                <div class="absolute inset-0 bg-black/50"></div>

                <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Call {{ $conversation->contact->name }}?</h3>
                    <dl class="text-sm space-y-1 mb-4">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Number:</dt>
                            <dd class="text-gray-900 font-mono">{{ $conversation->contact->phone }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">From:</dt>
                            <dd class="text-gray-900">{{ $conversation->whatsappInstance->display_name ?? $conversation->whatsappInstance->instance_name }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2 mb-4">
                        This will count toward your daily Meta call quota. Audio will ring on the device where this WhatsApp Business number is registered.
                    </p>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="open = false"
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <form method="POST" action="{{ route('conversations.initiateCall', $conversation) }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center px-5 py-2 text-sm font-medium text-white bg-emerald-600 rounded-md hover:bg-emerald-700">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                                </svg>
                                Call now
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </button>
@endcan
```

- [ ] **Step 2: Manual smoke test**

Reload the conversation show page from Task 12's smoke test URL. Verify:
- Green phone button appears in top right when logged in as admin/manager (have `conversations.call`)
- Click button → modal appears with contact name + number
- Click "Cancel" → modal closes
- Login as a fresh user with role=agent (without `conversations.call`) → button does NOT appear

- [ ] **Step 3: Verify existing tests still pass**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/ConversationControllerTest.php --no-coverage
```

Expected: all existing tests pass.

- [ ] **Step 4: Commit**

```bash
git add resources/views/conversations/show.blade.php
git commit -m "feat(voice): call button + confirmation modal in chat header"
```

---

## Task 14: Add in-flight banner

**Files:**
- Create: `app/Livewire/InFlightCall.php`
- Create: `resources/views/livewire/in-flight-call.blade.php`
- Modify: `resources/views/conversations/show.blade.php`

- [ ] **Step 1: Create the Livewire component**

Create `app/Livewire/InFlightCall.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use App\Models\Conversation;
use Livewire\Component;

class InFlightCall extends Component
{
    public int $conversationId;

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    public function render()
    {
        // Most-recent call_log for this conversation, only if still in-flight
        // and started within the last 30 minutes (so old hung calls don't
        // appear forever).
        $call = CallLog::query()
            ->where('conversation_id', $this->conversationId)
            ->whereIn('status', CallLog::STATUSES_IN_FLIGHT)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->latest()
            ->first();

        return view('livewire.in-flight-call', [
            'call' => $call,
        ]);
    }
}
```

- [ ] **Step 2: Create the view template**

Create `resources/views/livewire/in-flight-call.blade.php`:

```blade
<div wire:poll.3s>
    @if($call)
        @php
            $bannerClass = match($call->status) {
                'connected' => 'bg-emerald-100 border-emerald-300 text-emerald-900',
                default => 'bg-amber-100 border-amber-300 text-amber-900',
            };
            $statusText = match($call->status) {
                'initiated' => 'Connecting to Meta...',
                'ringing' => 'Calling ' . ($call->contact->name ?? $call->to_phone) . '...',
                'connected' => 'Call connected · ' . gmdate('i:s', max(0, now()->diffInSeconds($call->connected_at, false) * -1)),
                default => $call->status,
            };
        @endphp

        <div class="sticky top-0 z-10 border-b {{ $bannerClass }} px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                @if($call->status !== 'connected')
                    <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                @else
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                    </svg>
                @endif
                <span class="font-medium">{{ $statusText }}</span>
            </div>

            @if($call->isInFlight())
                <form method="POST" action="{{ route('conversations.endCall', ['conversation' => $call->conversation_id, 'call' => $call->id]) }}">
                    @csrf
                    <button type="submit" class="text-sm font-medium hover:underline">
                        End call
                    </button>
                </form>
            @endif
        </div>
    @endif
</div>
```

- [ ] **Step 3: Mount the component in the conversation show view**

Open `resources/views/conversations/show.blade.php`. After the page header slot (`</x-slot>`) and before the chat thread container, add:

```blade
@livewire('in-flight-call', ['conversationId' => $conversation->id])
```

- [ ] **Step 4: Manual smoke test**

Visit the conversation show URL from Task 12. From tinker, simulate an in-flight call:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute="
\$conv = App\Models\Conversation::first();
App\Models\CallLog::factory()->inFlight()->create([
    'conversation_id' => \$conv->id,
    'contact_id' => \$conv->contact_id,
    'whatsapp_instance_id' => \$conv->whatsapp_instance_id,
    'status' => 'ringing',
]);
echo 'Created in-flight call. Refresh the conversation page.' . PHP_EOL;
"
```

Refresh the conversation page. Verify:
- Amber banner appears at the top with "Calling [Name]..."
- Spinner animates
- "End call" button visible

After ~3 seconds Livewire should re-poll. Update the call log to status='connected' in tinker:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute="App\Models\CallLog::latest()->first()->update(['status' => 'connected', 'connected_at' => now()]);"
```

Wait 3 seconds; banner should turn green and show timer.

- [ ] **Step 5: Run all tests to ensure nothing regressed**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: all 119+ tests pass plus the new ones added in earlier tasks.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/InFlightCall.php resources/views/livewire/in-flight-call.blade.php resources/views/conversations/show.blade.php
git commit -m "feat(voice): in-flight call banner with poll-driven status updates"
```

---

## Task 15: Create `CallController::index` + `/calls` page view

**Files:**
- Create: `app/Http/Controllers/CallController.php`
- Create: `resources/views/calls/index.blade.php`
- Create: `tests/Feature/Controllers/CallsPageTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Controllers/CallsPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_sees_all_calls_in_account(): void
    {
        $admin = $this->makeUser('admin');
        $convs = Conversation::factory()->count(3)->create(['user_id' => $admin->id]);
        foreach ($convs as $c) {
            CallLog::factory()->create([
                'conversation_id' => $c->id,
                'contact_id' => $c->contact_id,
                'whatsapp_instance_id' => $c->whatsapp_instance_id,
            ]);
        }

        $response = $this->actingAs($admin)->get(route('calls.index'));

        $response->assertOk();
        $this->assertCount(3, $response->viewData('calls'));
    }

    public function test_admin_does_not_see_calls_from_other_accounts(): void
    {
        $admin = $this->makeUser('admin');
        $other = $this->makeUser('admin', 'other@example.com');

        $myConv = Conversation::factory()->create(['user_id' => $admin->id]);
        CallLog::factory()->create([
            'conversation_id' => $myConv->id,
            'contact_id' => $myConv->contact_id,
            'whatsapp_instance_id' => $myConv->whatsapp_instance_id,
        ]);

        $otherConv = Conversation::factory()->create(['user_id' => $other->id]);
        CallLog::factory()->count(5)->create([
            'conversation_id' => $otherConv->id,
            'contact_id' => $otherConv->contact_id,
            'whatsapp_instance_id' => $otherConv->whatsapp_instance_id,
        ]);

        $this->actingAs($admin)->get(route('calls.index'))
            ->assertViewHas('calls', fn ($calls) => count($calls) === 1);
    }

    public function test_agent_sees_only_calls_in_assigned_conversations(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');

        $assigned = Conversation::factory()->assignedTo($agent)->create(['user_id' => $admin->id]);
        $unassigned = Conversation::factory()->count(2)->create(['user_id' => $admin->id]);

        CallLog::factory()->create([
            'conversation_id' => $assigned->id,
            'contact_id' => $assigned->contact_id,
            'whatsapp_instance_id' => $assigned->whatsapp_instance_id,
        ]);
        foreach ($unassigned as $c) {
            CallLog::factory()->create([
                'conversation_id' => $c->id,
                'contact_id' => $c->contact_id,
                'whatsapp_instance_id' => $c->whatsapp_instance_id,
            ]);
        }

        $response = $this->actingAs($agent)->get(route('calls.index'));

        $response->assertOk();
        $this->assertCount(1, $response->viewData('calls'));
    }

    public function test_user_with_no_chat_permissions_gets_403(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get(route('calls.index'))->assertForbidden();
    }

    public function test_filter_by_direction_works(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);
        CallLog::factory()->count(2)->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
            'direction' => 'inbound',
        ]);
        CallLog::factory()->outbound($admin)->count(3)->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
        ]);

        $this->actingAs($admin)->get(route('calls.index', ['direction' => 'outbound']))
            ->assertViewHas('calls', fn ($calls) => count($calls) === 3);
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

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/CallsPageTest.php --no-coverage
```

Expected: FAIL — `Route [calls.index] not defined.`

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/CallController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CallLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cross-conversation call feed (page at /calls).
 *
 * Visibility mirrors the inbox:
 *   - users with conversations.view_all see all calls in their account
 *   - users with conversations.view_assigned see calls only in conversations
 *     assigned to them
 *
 * Filterable by direction, status, and date range via query params.
 */
class CallController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = CallLog::query()->with(['contact', 'conversation', 'whatsappInstance', 'placedBy']);

        if ($user->can('conversations.view_all')) {
            // Account-wide visibility — restrict to calls whose conversation
            // belongs to the current user's account.
            $query->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id));
        } else {
            // Agent visibility — only conversations assigned to me
            $query->whereHas('conversation', fn ($q) => $q->where('assigned_to_user_id', $user->id));
        }

        if ($direction = $request->query('direction')) {
            if (in_array($direction, ['inbound', 'outbound'], true)) {
                $query->where('direction', $direction);
            }
        }

        if ($status = $request->query('status')) {
            if (in_array($status, ['ended', 'missed', 'declined', 'failed'], true)) {
                $query->where('status', $status);
            }
        }

        $calls = $query->latest()->paginate(50);

        return view('calls.index', [
            'calls' => $calls,
            'currentDirection' => $request->query('direction'),
            'currentStatus' => $request->query('status'),
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, find the conversations.* routes group and right before the call-action group, add:

```php
Route::middleware('role_or_permission:conversations.view_all|conversations.view_assigned')
    ->group(function () {
        Route::get('/calls', [\App\Http\Controllers\CallController::class, 'index'])
            ->name('calls.index');
    });
```

- [ ] **Step 5: Create the view**

Create `resources/views/calls/index.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Calls') }}</h2>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto sm:px-6 lg:px-8">

        {{-- Filter chips --}}
        <div class="bg-white shadow-sm rounded-lg p-4 mb-4 flex flex-wrap items-center gap-2">
            <a href="{{ route('calls.index') }}"
               class="px-3 py-1 rounded-full text-sm
                      {{ ! $currentDirection && ! $currentStatus
                         ? 'bg-emerald-100 text-emerald-800 font-semibold'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ __('All calls') }}
            </a>
            <a href="{{ route('calls.index', ['direction' => 'inbound']) }}"
               class="px-3 py-1 rounded-full text-sm
                      {{ $currentDirection === 'inbound'
                         ? 'bg-emerald-100 text-emerald-800 font-semibold'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ __('Inbound') }}
            </a>
            <a href="{{ route('calls.index', ['direction' => 'outbound']) }}"
               class="px-3 py-1 rounded-full text-sm
                      {{ $currentDirection === 'outbound'
                         ? 'bg-emerald-100 text-emerald-800 font-semibold'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ __('Outbound') }}
            </a>
            <span class="text-gray-300">|</span>
            <a href="{{ route('calls.index', ['status' => 'missed']) }}"
               class="px-3 py-1 rounded-full text-sm
                      {{ $currentStatus === 'missed'
                         ? 'bg-amber-100 text-amber-800 font-semibold'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ __('Missed') }}
            </a>
            <a href="{{ route('calls.index', ['status' => 'failed']) }}"
               class="px-3 py-1 rounded-full text-sm
                      {{ $currentStatus === 'failed'
                         ? 'bg-red-100 text-red-800 font-semibold'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ __('Failed') }}
            </a>
        </div>

        {{-- Calls list --}}
        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('When') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Direction') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Contact') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Duration') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Instance') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($calls as $call)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-700 whitespace-nowrap">
                                {{ $call->created_at->format('M d, H:i') }}
                            </td>
                            <td class="px-6 py-3 text-sm">
                                @if($call->isInbound())
                                    <span class="inline-flex items-center gap-1 text-emerald-700">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                                        Inbound
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-blue-700">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                                        Outbound
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-sm">
                                <div class="font-medium text-gray-900">{{ $call->contact->name ?? $call->from_phone }}</div>
                                <div class="text-xs text-gray-500 font-mono">{{ $call->isInbound() ? $call->from_phone : $call->to_phone }}</div>
                            </td>
                            <td class="px-6 py-3 text-sm">
                                @php
                                    $statusClass = match($call->status) {
                                        'connected', 'ended' => 'bg-emerald-100 text-emerald-800',
                                        'missed' => 'bg-amber-100 text-amber-800',
                                        'declined', 'failed' => 'bg-red-100 text-red-800',
                                        default => 'bg-blue-100 text-blue-800',
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">
                                    {{ ucfirst($call->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-700 whitespace-nowrap">
                                {{ $call->duration_seconds ? gmdate('i:s', $call->duration_seconds) : '—' }}
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-600">
                                {{ $call->whatsappInstance->display_name ?? $call->whatsappInstance->instance_name }}
                            </td>
                            <td class="px-6 py-3 text-right">
                                <a href="{{ route('conversations.show', $call->conversation_id) }}"
                                   class="text-sm font-medium text-emerald-700 hover:text-emerald-900">
                                    Open conversation →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-12 text-gray-400">
                                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                                </svg>
                                <p>{{ __('No calls match this filter.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if($calls->hasPages())
                <div class="px-6 py-3 border-t border-gray-100">{{ $calls->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
```

- [ ] **Step 6: Run tests to confirm they pass**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/Controllers/CallsPageTest.php --no-coverage
```

Expected: `OK (5 tests, X assertions)`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/CallController.php resources/views/calls/index.blade.php tests/Feature/Controllers/CallsPageTest.php routes/web.php
git commit -m "feat(voice): /calls page with filter chips + permission scoping"
```

---

## Task 16: Add Calls link to sidebar navigation

**Files:**
- Modify: `resources/views/layouts/navigation.blade.php`

- [ ] **Step 1: Find the existing Inbox link**

Open `resources/views/layouts/navigation.blade.php`. Search for `route('conversations.index')` — that's the Inbox link in the Overview section.

- [ ] **Step 2: Add the Calls link right after Inbox**

Within the same `@canany` block as Inbox (since both share the same visibility rules), or as a separate block right after, add:

```blade
@canany(['conversations.view_all', 'conversations.view_assigned'])
    <x-sidebar-link :href="route('calls.index')" :active="request()->routeIs('calls.*')">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
        </svg>
        {{ __('Calls') }}
    </x-sidebar-link>
@endcanany
```

- [ ] **Step 3: Manual smoke test**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan view:clear
```

Refresh the dashboard. Verify in the left sidebar (Overview section):
- Dashboard
- Inbox
- **Calls** ← new
- (other Overview items as before)

Click "Calls" → /calls page renders.

- [ ] **Step 4: Run full test suite to confirm zero regressions**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: 119 (existing) + ~22 (new) = ~141 tests pass, 0 failures.

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/navigation.blade.php
git commit -m "feat(voice): Calls link in sidebar Overview section"
```

---

## Final verification

- [ ] **Step 1: Full test suite green**

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 vendor/bin/phpunit --no-coverage
```

Expected: ~141 tests pass.

- [ ] **Step 2: Push to GitHub**

```bash
git push origin main
```

- [ ] **Step 3: Deploy on production**

On the production server:

```bash
cd /home/oosadiaye/Blast_dplux

git pull origin main
composer install --no-dev --optimize-autoloader
npm install && npm run build
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
php artisan view:clear && php artisan view:cache
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
```

- [ ] **Step 4: Manual smoke test on production**

1. Login as admin@blastiq.com
2. Navigate to /calls — page renders, empty state visible
3. Open a conversation thread — green call button appears in top right
4. Click button → confirmation modal appears with contact info
5. (Optional) Click "Call now" — call_log row created, in-flight banner appears (audio rings on the device where Business app is registered)
6. Receive a call from a real Nigerian phone to your Business number — verify webhook captures it, inline call card appears in conversation, /calls page shows it

If any step fails, capture the exact error from `tail -30 storage/logs/laravel.log` and adjust the implementation.

---

## Acceptance criteria recap

- [x] Migration adds `call_logs` table with all columns + indexes
- [x] `RolesAndPermissionsSeeder` includes `conversations.call`, granted to super_admin/admin/manager
- [x] Inbound call webhook from Meta creates a call_log row, updates as events arrive
- [x] User with `conversations.call` can place an outbound call from chat header
- [x] User without `conversations.call` does NOT see the call button
- [x] Confirmation modal shows before call is placed
- [x] In-flight banner appears immediately after call is placed and updates as status changes
- [x] Inline call cards appear in conversation thread, mixed chronologically with messages
- [x] /calls page lists all calls with filter chips, respects view_all vs view_assigned visibility
- [x] "End call" button hangs up an in-progress call; status updates to `ended`
- [x] Meta API failure during outbound call shows user-facing error; no orphan call_log row created
- [x] All tests pass; full suite remains green

Once all boxes are checked, Phase A is complete. Phase B (scheduling + calendar) gets its own brainstorm + spec + plan when prioritized.
