<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\InFlightCall;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Quick Dial "call screen" chain. After placeOutbound records an outbound
 * call, the globally-mounted InFlightCall (layout) must surface it — that is what
 * mounts the outbound softphone banner so the agent can actually talk. Without
 * this the call is placed server-side but the agent sees nothing (the reported
 * "quick calling doesn't work").
 */
class InFlightCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_surfaces_an_agents_own_in_flight_outbound_call(): void
    {
        $agent = $this->agent();
        $call = $this->outboundCall($agent, CallLog::STATUS_INITIATED);

        Livewire::actingAs($agent)
            ->test(InFlightCall::class)
            ->assertViewHas('call', fn ($c) => $c !== null && $c->id === $call->id);
    }

    public function test_the_calls_refresh_event_re_renders_and_finds_the_call(): void
    {
        // Quick Dial fires calls:refresh right after placing the call so the banner
        // mounts immediately instead of waiting for the 3s poll.
        $agent = $this->agent();
        $call = $this->outboundCall($agent, CallLog::STATUS_INITIATED);

        Livewire::actingAs($agent)
            ->test(InFlightCall::class)
            ->dispatch('calls:refresh')
            ->assertViewHas('call', fn ($c) => $c?->id === $call->id);
    }

    public function test_an_agent_does_not_see_another_agents_outbound_call(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $this->outboundCall($other, CallLog::STATUS_INITIATED);

        Livewire::actingAs($agent)
            ->test(InFlightCall::class)
            ->assertViewHas('call', fn ($c) => $c === null);
    }

    public function test_a_stale_call_beyond_the_freshness_window_is_not_surfaced(): void
    {
        $agent = $this->agent();
        $this->outboundCall($agent, CallLog::STATUS_INITIATED, now()->subMinutes(45));

        Livewire::actingAs($agent)
            ->test(InFlightCall::class)
            ->assertViewHas('call', fn ($c) => $c === null);
    }

    private function agent(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('agent');

        return $u;
    }

    private function outboundCall(User $placer, string $status, ?Carbon $createdAt = null): CallLog
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
            'assigned_to_user_id' => $placer->id,
            'unread_count' => 0,
        ]);

        $call = CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => 'sess_'.fake()->unique()->numerify('########'),
            'status' => $status,
            'started_at' => now(),
            'placed_by_user_id' => $placer->id,
            'from_phone' => '+2348100000000',
            'to_phone' => $contact->phone,
        ]);

        // created_at drives the 30-min freshness window; Eloquent stamps it to now()
        // on insert, so set a stale value explicitly when the test needs one.
        if ($createdAt !== null) {
            $call->created_at = $createdAt;
            $call->save();
        }

        return $call;
    }
}
