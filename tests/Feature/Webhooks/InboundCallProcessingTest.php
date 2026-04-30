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

    public function test_decline_event_sets_declined_status_with_reason(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $this->processor->processCalls($instance, [
            ['id' => 'wacid.dec', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'connect', 'timestamp' => '1714500000'],
            ['id' => 'wacid.dec', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'reject', 'timestamp' => '1714500003', 'reason' => 'Customer busy'],
        ]);

        $call = CallLog::where('meta_call_id', 'wacid.dec')->first();
        $this->assertSame('declined', $call->status);
        $this->assertSame('Customer busy', $call->failure_reason);
        $this->assertNotNull($call->ended_at);
    }

    public function test_fail_event_sets_failed_status_with_error_message(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $this->processor->processCalls($instance, [
            ['id' => 'wacid.err', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'connect', 'timestamp' => '1714500000'],
            ['id' => 'wacid.err', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'fail', 'timestamp' => '1714500001', 'error' => ['message' => 'Network unreachable']],
        ]);

        $call = CallLog::where('meta_call_id', 'wacid.err')->first();
        $this->assertSame('failed', $call->status);
        $this->assertSame('Network unreachable', $call->failure_reason);
    }

    public function test_distinct_events_for_same_call_append_to_raw_event_log_in_order(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $this->processor->processCalls($instance, [
            ['id' => 'wacid.log', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'connect', 'timestamp' => '1714500000'],
            ['id' => 'wacid.log', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'accept', 'timestamp' => '1714500005'],
            ['id' => 'wacid.log', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'disconnect', 'timestamp' => '1714500035'],
        ]);

        $call = CallLog::where('meta_call_id', 'wacid.log')->first();
        $events = collect($call->raw_event_log)->pluck('event')->all();

        // After the fix, duplicate connect returns early without appending to raw_event_log.
        // Only accept and disconnect are appended.
        $this->assertContains('accept', $events);
        $this->assertContains('disconnect', $events);
        $this->assertSame('disconnect', end($events), 'disconnect should be the last logged event');
    }

    public function test_duplicate_connect_event_does_not_grow_raw_event_log(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $event = ['id' => 'wacid.bloat', 'from' => '234999', 'to' => $instance->business_phone_number, 'event' => 'connect', 'timestamp' => '1714500000'];

        $this->processor->processCalls($instance, [$event]);
        $afterFirst = CallLog::where('meta_call_id', 'wacid.bloat')->first()->raw_event_log ?? [];

        // Replay the same connect event 5 times to simulate webhook retries
        for ($i = 0; $i < 5; $i++) {
            $this->processor->processCalls($instance, [$event]);
        }

        $afterReplays = CallLog::where('meta_call_id', 'wacid.bloat')->first()->raw_event_log ?? [];

        // raw_event_log size should not grow with duplicate connects
        $this->assertSameSize($afterFirst, $afterReplays,
            'Duplicate connect events must not append to raw_event_log on every retry');
    }
}
