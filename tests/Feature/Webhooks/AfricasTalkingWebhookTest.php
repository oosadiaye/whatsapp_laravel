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
            'durationInSeconds' => '90',
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
