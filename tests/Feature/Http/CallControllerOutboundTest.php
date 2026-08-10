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
use Illuminate\Support\Facades\RateLimiter;
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

    public function test_agent_cannot_dial_a_conversation_assigned_to_another_agent(): void
    {
        // IDOR: an agent with conversations.call + view_assigned must not be able
        // to PSTN-dial a conversation assigned to a DIFFERENT agent by guessing
        // its id. Access = view_all OR (view_assigned AND assigned to me).
        Http::fake();
        $agentA = $this->makeAgent();
        $agentB = $this->makeAgent();
        $conversationOfB = $this->makeConversation($agentB);

        $this->actingAs($agentA)
            ->postJson(route('calls.outbound'), ['conversation_id' => $conversationOfB->id])
            ->assertForbidden();

        $this->assertSame(0, CallLog::count());
    }

    public function test_outbound_is_rate_limited_per_user_after_ten_attempts(): void
    {
        Http::fake([
            'voice.africastalking.com/call' => Http::response(['entries' => [['sessionId' => 's', 'status' => 'Queued']]], 201),
        ]);
        $agent = $this->makeAgent();
        $conversation = $this->makeConversation($agent);

        // Exhaust the 10/60s bucket for THIS user only.
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('outbound-call:'.$agent->id, 60);
        }

        $this->actingAs($agent)
            ->postJson(route('calls.outbound'), ['conversation_id' => $conversation->id])
            ->assertStatus(429);

        // A different user is NOT locked out by the first user's activity.
        $other = $this->makeAgent();
        $otherConversation = $this->makeConversation($other);
        $this->actingAs($other)
            ->postJson(route('calls.outbound'), ['conversation_id' => $otherConversation->id])
            ->assertOk();
    }

    public function test_outbound_calling_can_be_disabled_by_kill_switch(): void
    {
        // Operational OFF-switch: when disabled, placeOutbound short-circuits with
        // a 503 BEFORE touching the provider or writing any CallLog.
        config(['voice.outbound_calls_enabled' => false]);
        Http::fake();

        $agent = $this->makeAgent();
        $conversation = $this->makeConversation($agent);

        $this->actingAs($agent)
            ->postJson(route('calls.outbound'), ['conversation_id' => $conversation->id])
            ->assertStatus(503)
            ->assertJson(['error' => 'Outbound calling is temporarily disabled.']);

        Http::assertNothingSent();
        $this->assertSame(0, CallLog::count());
    }

    public function test_missing_at_username_fails_fast_with_503_and_audit(): void
    {
        // Clear the username the base setUp seeded: placeCall must fail fast with a
        // ConfigurationException (→ 503 + audit row) BEFORE hitting AT, instead of
        // sending an empty username and earning an opaque provider rejection.
        Setting::set('africastalking_username', '');
        Http::fake([
            'voice.africastalking.com/call' => Http::response(['entries' => [['sessionId' => 's', 'status' => 'Queued']]], 201),
        ]);

        $agent = $this->makeAgent();
        $conversation = $this->makeConversation($agent);

        $this->actingAs($agent)
            ->postJson(route('calls.outbound'), ['conversation_id' => $conversation->id])
            ->assertStatus(503);

        Http::assertNothingSent();
        $this->assertNotNull(
            CallLog::where('conversation_id', $conversation->id)
                ->where('status', CallLog::STATUS_FAILED)
                ->first()
        );
    }

    public function test_provider_connection_failure_returns_503_and_audits(): void
    {
        // A DNS/refused/timeout ConnectionException from the HTTP client is a
        // THROWN exception, not an error *response* — it must still surface as the
        // graceful 503 + audit row, never a generic 500 with no trace on /calls.
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $agent = $this->makeAgent();
        $conversation = $this->makeConversation($agent);

        $this->actingAs($agent)
            ->postJson(route('calls.outbound'), ['conversation_id' => $conversation->id])
            ->assertStatus(503);

        $this->assertNotNull(
            CallLog::where('conversation_id', $conversation->id)
                ->where('status', CallLog::STATUS_FAILED)
                ->first()
        );
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
