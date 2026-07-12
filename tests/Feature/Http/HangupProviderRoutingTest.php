<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppInstance;
use App\Jobs\TerminateProviderCall;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_hangup_ends_the_call_immediately_and_queues_provider_terminate(): void
    {
        // The agent-facing state must be consistent right away; the provider
        // hangup is handed to the retried TerminateProviderCall job.
        Bus::fake();
        $agent = $this->makeAgent();
        $call = $this->makeCall($agent, CallLog::PROVIDER_AFRICAS_TALKING, sessionId: 'sess_at');

        $this->actingAs($agent)
            ->postJson(route('calls.hangup', $call))
            ->assertOk();

        $this->assertSame(CallLog::STATUS_ENDED, $call->fresh()->status);
        $this->assertNotNull($call->fresh()->ended_at);
        Bus::assertDispatched(TerminateProviderCall::class);
    }

    private function makeAgent(): User
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $agent->assignRole(User::ROLE_AGENT);
        $agent->givePermissionTo('conversations.call');
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
