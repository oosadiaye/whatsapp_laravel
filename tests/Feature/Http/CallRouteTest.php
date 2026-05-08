<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Events\Calling\CallClaimed;
use App\Events\Calling\CallTerminated;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CallRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_claim_first_session_wins_second_gets_409(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);

        $first = $this->actingAs($agent)
            ->postJson(route('calls.claim', $call), ['session_id' => 'aaaaaaaa-bbbb-cccc-dddd-111111111111']);
        $first->assertOk()->assertJson(['claimed' => true]);

        $second = $this->actingAs($agent)
            ->postJson(route('calls.claim', $call), ['session_id' => 'aaaaaaaa-bbbb-cccc-dddd-222222222222']);
        $second->assertStatus(409);

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-111111111111', $call->fresh()->answered_by_session_id);
    }

    public function test_claim_same_session_is_idempotent(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);
        $sid = 'aaaaaaaa-bbbb-cccc-dddd-333333333333';

        $this->actingAs($agent)->postJson(route('calls.claim', $call), ['session_id' => $sid])->assertOk();
        $this->actingAs($agent)->postJson(route('calls.claim', $call), ['session_id' => $sid])->assertOk();
    }

    public function test_answer_invokes_accept_call_with_sdp_after_claim(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);
        $sid = 'aaaaaaaa-bbbb-cccc-dddd-444444444444';
        $call->update(['answered_by_session_id' => $sid]);

        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($agent)
            ->postJson(route('calls.answer', $call), [
                'session_id' => $sid,
                'sdp' => 'sdp-answer-blob',
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['action'] ?? null) === 'accept'
                && ($body['session']['sdp'] ?? null) === 'sdp-answer-blob';
        });
        $this->assertSame('sdp-answer-blob', $call->fresh()->sdp_answer);
    }

    public function test_answer_409s_if_session_id_does_not_match_claim(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);
        $call->update(['answered_by_session_id' => 'session-A']);

        $this->actingAs($agent)
            ->postJson(route('calls.answer', $call), [
                'session_id' => 'session-B',
                'sdp' => 'sdp',
            ])
            ->assertStatus(409);
    }

    public function test_decline_invokes_end_call_and_broadcasts_terminated(): void
    {
        Event::fake([CallTerminated::class]);
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);

        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($agent)
            ->postJson(route('calls.decline', $call))
            ->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['action'] ?? null) === 'terminate';
        });
        Event::assertDispatched(CallTerminated::class, function ($event) use ($call) {
            return $event->call->id === $call->id && $event->reason === 'declined';
        });
        $this->assertSame(CallLog::STATUS_DECLINED, $call->fresh()->status);
    }

    public function test_hangup_invokes_end_call_and_broadcasts_terminated(): void
    {
        Event::fake([CallTerminated::class]);
        $agent = $this->makeAgent();
        $call = $this->makeRingingCall($agent);
        $call->update(['status' => CallLog::STATUS_CONNECTED]);

        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($agent)
            ->postJson(route('calls.hangup', $call))
            ->assertOk();

        Event::assertDispatched(CallTerminated::class, function ($event) use ($call) {
            return $event->call->id === $call->id && $event->reason === 'agent_hung_up';
        });
        $this->assertSame(CallLog::STATUS_ENDED, $call->fresh()->status);
    }

    private function makeAgent(): User
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $agent->assignRole(User::ROLE_AGENT);
        return $agent;
    }

    private function makeRingingCall(User $agent): CallLog
    {
        $owner = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $owner->assignRole(User::ROLE_ADMIN);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create(['user_id' => $owner->id, 'phone' => '23480'.fake()->unique()->numerify('########')]);
        $conversation = \App\Models\Conversation::create([
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
            'direction' => 'inbound',
            'meta_call_id' => 'wacid.'.fake()->unique()->numerify('########'),
            'status' => CallLog::STATUS_RINGING,
            'from_phone' => $contact->phone,
            'to_phone' => $instance->phone_number ?? '2348000000000',
            'started_at' => now(),
            'sdp_offer' => 'fake-sdp-offer',
        ]);
    }
}
