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
        // Intentionally do NOT assignRole — this user has no permissions.

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
        $agent->givePermissionTo('conversations.call');
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
