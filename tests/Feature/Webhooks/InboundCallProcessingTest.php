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
