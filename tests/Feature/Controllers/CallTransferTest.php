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
