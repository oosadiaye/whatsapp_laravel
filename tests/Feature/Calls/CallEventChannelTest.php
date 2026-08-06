<?php

declare(strict_types=1);

namespace Tests\Feature\Calls;

use App\Events\Calling\CallClaimed;
use App\Events\Calling\CallRinging;
use App\Events\Calling\CallTerminated;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallEventChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcasts_to_the_assigned_agent_when_assigned(): void
    {
        $agent = User::factory()->create();
        $conv = Conversation::factory()->create(['assigned_to_user_id' => $agent->id]);
        $call = CallLog::factory()->create(['conversation_id' => $conv->id]);

        $this->assertSame('private-user.'.$agent->id, (new CallRinging($call))->broadcastOn()->name);
        $this->assertSame('private-user.'.$agent->id, (new CallClaimed($call))->broadcastOn()->name);
        $this->assertSame('private-user.'.$agent->id, (new CallTerminated($call, 'x'))->broadcastOn()->name);
    }

    public function test_falls_back_to_the_placer_for_an_unassigned_outbound_call(): void
    {
        $placer = User::factory()->create();
        $conv = Conversation::factory()->create(['assigned_to_user_id' => null]);
        $call = CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'placed_by_user_id' => $placer->id,
        ]);

        // Previously this produced the malformed "private-user." (null) channel.
        $this->assertSame('private-user.'.$placer->id, (new CallRinging($call))->broadcastOn()->name);
        $this->assertSame('private-user.'.$placer->id, (new CallTerminated($call, 'x'))->broadcastOn()->name);
    }

    public function test_call_ringing_payload_carries_direction_for_consumer_gating(): void
    {
        // The client uses payload direction to decide whether to ring/notify:
        // an agent who PLACED an outbound call must not hear "Incoming call"
        // for their own dial, so outbound events must be distinguishable.
        $agent = User::factory()->create();
        $conv = Conversation::factory()->create(['assigned_to_user_id' => $agent->id]);
        $inbound = CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'direction' => CallLog::DIRECTION_INBOUND,
        ]);
        $outbound = CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'direction' => CallLog::DIRECTION_OUTBOUND,
        ]);

        $this->assertSame(CallLog::DIRECTION_INBOUND, (new CallRinging($inbound))->broadcastWith()['direction']);
        $this->assertSame(CallLog::DIRECTION_OUTBOUND, (new CallRinging($outbound))->broadcastWith()['direction']);
        $this->assertSame($inbound->id, (new CallRinging($inbound))->broadcastWith()['call_id']);
    }
}
