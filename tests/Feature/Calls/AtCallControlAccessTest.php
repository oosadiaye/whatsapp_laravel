<?php

declare(strict_types=1);

namespace Tests\Feature\Calls;

use App\Events\Calling\CallTerminated;
use App\Livewire\RealtimePulse;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who sees, claims and declines an incoming Africa's Talking call.
 *
 * An AT call is a single voice leg dialed to ONE agent's browser (quo_call.agent_N).
 * Only that browser can answer it. But the banner query gave every
 * conversations.view_all user (super_admin, admin, manager) a banner for EVERY
 * in-flight call and claim()/decline() allowed them, so in a small team a colleague
 * could Accept a call dialed to someone else: claim() succeeds, there is no voice leg
 * behind it, and the real agent is told it was "claimed elsewhere" while the call
 * dies unanswered. WhatsApp (Meta) calls are a shared pool by design and are
 * deliberately left as they were.
 */
class AtCallControlAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeStaff(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function ringingCall(string $provider, ?User $assignedTo): CallLog
    {
        $owner = $this->makeStaff(User::ROLE_ADMIN);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create([
            'user_id' => $owner->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);
        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $assignedTo?->id,
            'unread_count' => 0,
        ]);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'provider' => $provider,
            'provider_session_id' => $provider === CallLog::PROVIDER_AFRICAS_TALKING ? 'ATVId_'.fake()->unique()->numerify('######') : null,
            'meta_call_id' => $provider === CallLog::PROVIDER_META_WHATSAPP ? 'wacid.'.fake()->unique()->numerify('######') : null,
            'status' => CallLog::STATUS_RINGING,
            'from_phone' => $contact->phone,
            'to_phone' => '2342017001511',
            'started_at' => now(),
        ]);
    }

    // ── Banner ────────────────────────────────────────────────────────────────

    public function test_an_at_call_banner_goes_only_to_the_agent_it_was_dialed_to(): void
    {
        $dialed = $this->makeStaff(User::ROLE_AGENT);
        $admin = $this->makeStaff(User::ROLE_ADMIN);
        $manager = $this->makeStaff(User::ROLE_MANAGER);
        $this->ringingCall(CallLog::PROVIDER_AFRICAS_TALKING, $dialed);

        Livewire::actingAs($dialed)->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 1);

        // Anyone with "view all" used to get a banner too, which they could Accept.
        Livewire::actingAs($admin)->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
        Livewire::actingAs($manager)->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }

    public function test_an_at_call_nobody_was_dialed_for_has_no_banner(): void
    {
        // No agent was available, so the caller was told "all agents are busy": there
        // is no voice leg anywhere, and a banner would be a phantom to every agent.
        $agent = $this->makeStaff(User::ROLE_AGENT);
        $admin = $this->makeStaff(User::ROLE_ADMIN);
        $this->ringingCall(CallLog::PROVIDER_AFRICAS_TALKING, null);

        Livewire::actingAs($agent)->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
        Livewire::actingAs($admin)->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }

    public function test_a_whatsapp_call_keeps_its_shared_pool_visibility(): void
    {
        // Meta calls are answered by whichever agent claims them, so the pool and
        // supervisor visibility are intentional and must not change.
        $agent = $this->makeStaff(User::ROLE_AGENT);
        $admin = $this->makeStaff(User::ROLE_ADMIN);
        $this->ringingCall(CallLog::PROVIDER_META_WHATSAPP, null);

        Livewire::actingAs($agent)->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 1);
        Livewire::actingAs($admin)->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 1);
    }

    // ── Claim / decline ───────────────────────────────────────────────────────

    public function test_only_the_dialed_agent_can_claim_an_at_call(): void
    {
        $dialed = $this->makeStaff(User::ROLE_AGENT);
        $admin = $this->makeStaff(User::ROLE_ADMIN);
        $call = $this->ringingCall(CallLog::PROVIDER_AFRICAS_TALKING, $dialed);

        $this->actingAs($admin)
            ->postJson(route('calls.claim', $call), ['session_id' => 'admin-session-1'])
            ->assertForbidden();
        $this->assertNull($call->fresh()->answered_by_session_id, 'a refused claim must not mark the call answered');

        $this->actingAs($dialed)
            ->postJson(route('calls.claim', $call), ['session_id' => 'agent-session-1'])
            ->assertOk();
        $this->assertSame('agent-session-1', $call->fresh()->answered_by_session_id);
    }

    public function test_only_the_dialed_agent_can_decline_an_at_call(): void
    {
        Event::fake([CallTerminated::class]);
        $dialed = $this->makeStaff(User::ROLE_AGENT);
        $manager = $this->makeStaff(User::ROLE_MANAGER);
        $call = $this->ringingCall(CallLog::PROVIDER_AFRICAS_TALKING, $dialed);

        $this->actingAs($manager)->postJson(route('calls.decline', $call))->assertForbidden();

        $this->assertSame(CallLog::STATUS_RINGING, $call->fresh()->status);
        Event::assertNotDispatched(CallTerminated::class);
    }

    public function test_a_supervisor_can_still_claim_a_whatsapp_call(): void
    {
        $admin = $this->makeStaff(User::ROLE_ADMIN);
        $call = $this->ringingCall(CallLog::PROVIDER_META_WHATSAPP, null);

        $this->actingAs($admin)
            ->postJson(route('calls.claim', $call), ['session_id' => 'admin-session-2'])
            ->assertOk();
    }

    // ── Failure reason reaches the agent ──────────────────────────────────────

    public function test_the_terminated_broadcast_carries_the_failure_reason(): void
    {
        // When AT cannot bridge the customer to the agent's browser the banner used to
        // just vanish. The reason is on the call record; the browser needs it too.
        $dialed = $this->makeStaff(User::ROLE_AGENT);
        $call = $this->ringingCall(CallLog::PROVIDER_AFRICAS_TALKING, $dialed);
        $call->update(['failure_reason' => 'AT bridge: NO_USER_RESPONSE']);

        $payload = (new CallTerminated($call->fresh(), 'remote_completed'))->broadcastWith();

        $this->assertSame('AT bridge: NO_USER_RESPONSE', $payload['failure_reason']);
        $this->assertSame($call->id, $payload['call_id']);
        $this->assertSame('remote_completed', $payload['reason']);
    }
}
