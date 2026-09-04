<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Events\Calling\CallRinging;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CallTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['voice.transfer_enabled' => true, 'voice.at_webhook_secret' => 'test-secret']);
    }

    private function makeAgent(?string $email = null): User
    {
        $agent = User::factory()->create(['email' => $email ?? 'agent-'.uniqid().'@example.com', 'is_active' => true]);
        $agent->assignRole('agent');

        return $agent;
    }

    private function callAssignedTo(User $agent): CallLog
    {
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $agent->id]);

        return CallLog::factory()->create([
            'conversation_id' => $conversation->id,
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => 'sess_'.uniqid(),
            'status' => CallLog::STATUS_CONNECTED,
            'from_phone' => '+2348011112222',
        ]);
    }

    public function test_transfer_to_agent_reassigns_and_rings_the_target(): void
    {
        Event::fake([CallRinging::class]);
        $from = $this->makeAgent();
        $to = $this->makeAgent('target@example.com');
        $call = $this->callAssignedTo($from);

        $this->actingAs($from)
            ->postJson(route('calls.transfer', $call), ['target_type' => 'agent', 'target_user_id' => $to->id])
            ->assertOk();

        $call->refresh();
        $this->assertSame('agent_'.$to->id, $call->transfer_target);
        $this->assertSame($to->id, $call->transferred_to_user_id);
        $this->assertSame($to->id, $call->conversation->assigned_to_user_id);
        Event::assertDispatched(CallRinging::class);
    }

    public function test_transfer_to_a_pstn_number_records_the_target(): void
    {
        $from = $this->makeAgent();
        $call = $this->callAssignedTo($from);

        $this->actingAs($from)
            ->postJson(route('calls.transfer', $call), ['target_type' => 'number', 'target_number' => '+2348099998888'])
            ->assertOk();

        $this->assertSame('+2348099998888', $call->fresh()->transfer_target);
    }

    public function test_transfer_is_blocked_when_disabled(): void
    {
        config(['voice.transfer_enabled' => false]);
        $from = $this->makeAgent();
        $call = $this->callAssignedTo($from);

        $this->actingAs($from)
            ->postJson(route('calls.transfer', $call), ['target_type' => 'number', 'target_number' => '+2348099998888'])
            ->assertForbidden();
    }

    public function test_transfer_requires_access_to_the_call(): void
    {
        $owner = $this->makeAgent();
        $outsider = $this->makeAgent('outsider@example.com'); // agent, but not assigned
        $call = $this->callAssignedTo($owner);

        $this->actingAs($outsider)
            ->postJson(route('calls.transfer', $call), ['target_type' => 'number', 'target_number' => '+2348099998888'])
            ->assertForbidden();
    }

    public function test_transfer_to_number_is_blocked_by_the_outbound_kill_switch(): void
    {
        // #7: the number-transfer path dials a live PSTN number, so it must honour
        // the same kill-switch placeOutbound does — previously it bypassed it.
        config(['voice.outbound_calls_enabled' => false]);
        $from = $this->makeAgent();
        $call = $this->callAssignedTo($from);

        $this->actingAs($from)
            ->postJson(route('calls.transfer', $call), ['target_type' => 'number', 'target_number' => '+2348099998888'])
            ->assertStatus(503);

        $this->assertNull($call->fresh()->transfer_target, 'no target recorded when outbound is disabled');
    }

    public function test_transfer_to_number_rejects_an_invalid_number(): void
    {
        // Previously target_number was stored raw (string|max:32) and dialed verbatim.
        $from = $this->makeAgent();
        $call = $this->callAssignedTo($from);

        $this->actingAs($from)
            ->postJson(route('calls.transfer', $call), ['target_type' => 'number', 'target_number' => '12'])
            ->assertStatus(422);
    }

    public function test_transfer_to_number_normalizes_to_e164(): void
    {
        $from = $this->makeAgent();
        $call = $this->callAssignedTo($from);

        $this->actingAs($from)
            ->postJson(route('calls.transfer', $call), ['target_type' => 'number', 'target_number' => '08099998888'])
            ->assertOk();

        // 0-led national (11 digits) → +234 + subscriber, dialable +E.164.
        $this->assertSame('+2348099998888', $call->fresh()->transfer_target);
    }

    public function test_transfer_to_number_is_rate_limited_per_user(): void
    {
        $from = $this->makeAgent();
        $call = $this->callAssignedTo($from);

        // Exhaust the shared 10/min outbound budget for this user.
        for ($i = 0; $i < 10; $i++) {
            \Illuminate\Support\Facades\RateLimiter::hit('outbound-call:'.$from->id, 60);
        }

        $this->actingAs($from)
            ->postJson(route('calls.transfer', $call), ['target_type' => 'number', 'target_number' => '+2348099998888'])
            ->assertStatus(429);
    }

    public function test_a_completed_blind_transfer_ends_the_call_instead_of_looping(): void
    {
        // #7 (voice): once a transfer's <Dial> was issued (transfer_target cleared,
        // transferred_at stamped), a re-request must Reject — not redial the
        // reassigned target (inbound loop) or bridge back to the transferring agent
        // (outbound).
        $from = $this->makeAgent();
        $call = $this->callAssignedTo($from);
        $call->update(['transferred_at' => now(), 'transfer_target' => null]);

        $response = $this->post(
            route('webhook.africastalking.voice', ['secret' => 'test-secret']),
            [
                'isActive' => '1',
                'sessionId' => $call->provider_session_id,
                'direction' => 'Inbound',
                'callerNumber' => '+2348011112222',
            ],
        );

        $response->assertOk();
        $response->assertSee('<Reject/>', false);
    }

    public function test_pending_transfer_dials_the_target_on_next_call_control(): void
    {
        $from = $this->makeAgent();
        $call = $this->callAssignedTo($from);
        $call->update(['transfer_target' => '+2348099998888']);

        $response = $this->post(
            route('webhook.africastalking.voice', ['secret' => 'test-secret']),
            [
                'isActive' => '1',
                'sessionId' => $call->provider_session_id,
                'direction' => 'Inbound',
                'callerNumber' => '+2348011112222',
            ],
        );

        $response->assertOk();
        $response->assertSee('<Dial phoneNumbers="+2348099998888"', false);
        // Cleared so a re-request doesn't loop.
        $this->assertNull($call->fresh()->transfer_target);
    }
}
