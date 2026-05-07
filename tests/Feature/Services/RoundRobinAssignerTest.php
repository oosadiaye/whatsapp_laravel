<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\RoundRobinAssigner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoundRobinAssignerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_returns_null_when_no_agents_exist(): void
    {
        $assigner = new RoundRobinAssigner();

        $this->assertNull($assigner->next());
    }

    public function test_returns_null_when_no_agents_are_online(): void
    {
        $offline = $this->makeAgent(lastSeenAt: now()->subMinutes(5));

        $assigner = new RoundRobinAssigner();

        $this->assertNull($assigner->next());
    }

    public function test_picks_only_user_with_agent_role(): void
    {
        // An online admin (NOT in the pool) and an online agent.
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $admin->assignRole(User::ROLE_ADMIN);

        $agent = $this->makeAgent(lastSeenAt: now());

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame($agent->id, $picked->id);
    }

    public function test_excludes_inactive_agents(): void
    {
        $inactive = $this->makeAgent(
            lastSeenAt: now(),
            isActive: false,
        );

        $assigner = new RoundRobinAssigner();

        $this->assertNull($assigner->next());
    }

    public function test_excludes_agents_offline_more_than_2_minutes(): void
    {
        // Boundary: 121 seconds ago is OFFLINE (window is 2 min = 120s)
        $offline = $this->makeAgent(lastSeenAt: now()->subSeconds(121));
        // 119 seconds ago is ONLINE
        $online = $this->makeAgent(
            email: 'b@example.com',
            lastSeenAt: now()->subSeconds(119),
        );

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame($online->id, $picked->id);
    }

    public function test_picks_agent_with_null_last_assigned_at_first(): void
    {
        // One agent has been assigned recently; another is brand new (NULL)
        $oldStamped = $this->makeAgent(
            lastSeenAt: now(),
            lastAssignedAt: now()->subMinutes(1),
        );
        $brandNew = $this->makeAgent(
            email: 'b@example.com',
            lastSeenAt: now(),
            lastAssignedAt: null,
        );

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame(
            $brandNew->id,
            $picked->id,
            'Agent with NULL last_assigned_at must be picked first (NULLS FIRST ordering)'
        );
    }

    public function test_picks_agent_with_oldest_last_assigned_at_when_none_null(): void
    {
        $stale10 = $this->makeAgent(
            email: 'a@example.com',
            lastSeenAt: now(),
            lastAssignedAt: now()->subMinutes(10),
        );
        $stale1 = $this->makeAgent(
            email: 'b@example.com',
            lastSeenAt: now(),
            lastAssignedAt: now()->subMinutes(1),
        );
        $stale5 = $this->makeAgent(
            email: 'c@example.com',
            lastSeenAt: now(),
            lastAssignedAt: now()->subMinutes(5),
        );

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame(
            $stale10->id,
            $picked->id,
            'Agent with oldest last_assigned_at (10 min ago) must be picked'
        );
    }

    public function test_stamps_picked_agent_with_current_timestamp_for_next_round(): void
    {
        // Two agents, both with NULL last_assigned_at. First call picks one,
        // stamps them; second call should pick the OTHER (because the first
        // is now stamped to "now", later than the still-NULL second).
        $a = $this->makeAgent(email: 'a@example.com', lastSeenAt: now());
        $b = $this->makeAgent(email: 'b@example.com', lastSeenAt: now());

        $assigner = new RoundRobinAssigner();

        $first = $assigner->next();
        $second = $assigner->next();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame(
            $first->id,
            $second->id,
            'Two consecutive next() calls must return different agents — '
            .'the first call stamps last_assigned_at, deprioritizing the picked agent.'
        );

        // And the first agent now has a non-null last_assigned_at
        $first->refresh();
        $this->assertNotNull($first->last_assigned_at);
    }

    public function test_excludes_agents_with_presence_status_away(): void
    {
        $away = $this->makeAgent(lastSeenAt: now());
        $away->forceFill(['presence_status' => User::PRESENCE_AWAY])->save();

        $assigner = new RoundRobinAssigner();

        $this->assertNull(
            $assigner->next(),
            'Away agents must be excluded from the rotation entirely'
        );
    }

    public function test_includes_agents_with_presence_status_busy(): void
    {
        $busy = $this->makeAgent(lastSeenAt: now());
        $busy->forceFill(['presence_status' => User::PRESENCE_BUSY])->save();

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame(
            $busy->id,
            $picked->id,
            'Busy agents stay in rotation — busy is a social signal, not a routing rule'
        );
    }

    public function test_treats_busy_and_available_identically_in_rotation(): void
    {
        // Two online agents with NULL last_assigned_at, one busy and one available.
        // Both must be picked across two consecutive next() calls (not one preferred
        // over the other). The first call stamps last_assigned_at on whichever it
        // picks; the second call must therefore pick the OTHER agent — proving the
        // first-call pick was driven by the round-robin pointer, NOT by status.
        $available = $this->makeAgent(email: 'a@example.com', lastSeenAt: now());
        // available defaults to 'available' from migration default — no override needed.

        $busy = $this->makeAgent(email: 'b@example.com', lastSeenAt: now());
        $busy->forceFill(['presence_status' => User::PRESENCE_BUSY])->save();

        $assigner = new RoundRobinAssigner();

        $first = $assigner->next();
        $second = $assigner->next();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame(
            $first->id,
            $second->id,
            'Two consecutive next() calls must return different agents — '
            .'busy and available are equivalent in routing'
        );
    }

    public function test_excludes_agent_at_cap(): void
    {
        \App\Models\Setting::set('round_robin_cap_per_agent', '3');

        $agent = $this->makeAgent(lastSeenAt: now());

        // 3 active conversations (last_inbound_at within 24h) — at cap
        for ($i = 0; $i < 3; $i++) {
            $this->makeAssignedConversation($agent, lastInboundAt: now());
        }

        $assigner = new RoundRobinAssigner();

        $this->assertNull(
            $assigner->next(),
            'Agent at cap (3 active conversations) must be excluded from rotation'
        );
    }

    public function test_includes_agent_one_below_cap(): void
    {
        \App\Models\Setting::set('round_robin_cap_per_agent', '3');

        $agent = $this->makeAgent(lastSeenAt: now());
        // Only 2 active conversations — below cap
        for ($i = 0; $i < 2; $i++) {
            $this->makeAssignedConversation($agent, lastInboundAt: now());
        }

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame($agent->id, $picked->id);
    }

    public function test_does_not_count_conversations_with_old_inbound(): void
    {
        \App\Models\Setting::set('round_robin_cap_per_agent', '3');

        $agent = $this->makeAgent(lastSeenAt: now());
        // 5 conversations, all with last_inbound_at OUTSIDE the 24h window
        for ($i = 0; $i < 5; $i++) {
            $this->makeAssignedConversation($agent, lastInboundAt: now()->subHours(25));
        }

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull(
            $picked,
            'Conversations with last_inbound_at >24h ago must NOT count toward cap '
            .'(those are dormant — agent is effectively free)'
        );
        $this->assertSame($agent->id, $picked->id);
    }

    public function test_uses_settings_value_for_cap(): void
    {
        \App\Models\Setting::set('round_robin_cap_per_agent', '2');

        $a = $this->makeAgent(email: 'a@example.com', lastSeenAt: now());
        $b = $this->makeAgent(email: 'b@example.com', lastSeenAt: now());

        // Agent A: 2 active conversations — AT cap (excluded)
        $this->makeAssignedConversation($a, lastInboundAt: now());
        $this->makeAssignedConversation($a, lastInboundAt: now());

        // Agent B: 1 active conversation — below cap (eligible)
        $this->makeAssignedConversation($b, lastInboundAt: now());

        $assigner = new RoundRobinAssigner();

        $picked = $assigner->next();

        $this->assertNotNull($picked);
        $this->assertSame(
            $b->id,
            $picked->id,
            'Cap of 2 from settings must filter A (count=2) and pick B (count=1)'
        );
    }

    public function test_cap_of_zero_returns_null_for_all_online_agents(): void
    {
        // cap=0 means manual-only mode: no agent is ever auto-picked.
        // (count) < 0 is always false, so all agents filtered out.
        \App\Models\Setting::set('round_robin_cap_per_agent', '0');

        $this->makeAgent(email: 'a@example.com', lastSeenAt: now());
        $this->makeAgent(email: 'b@example.com', lastSeenAt: now());

        $assigner = new RoundRobinAssigner();

        $this->assertNull(
            $assigner->next(),
            'Cap=0 disables auto-assignment entirely (manual-only mode)'
        );
    }

    private function makeAssignedConversation(
        \App\Models\User $agent,
        \Illuminate\Support\Carbon $lastInboundAt,
    ): \App\Models\Conversation {
        $owner = \App\Models\User::factory()->create([
            'role' => \App\Models\User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $instance = \App\Models\WhatsAppInstance::factory()->create([
            'user_id' => $owner->id,
        ]);
        $contact = \App\Models\Contact::factory()->create([
            'user_id' => $owner->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);

        return \App\Models\Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
            'last_inbound_at' => $lastInboundAt,
            'last_message_at' => $lastInboundAt,
            'unread_count' => 0,
        ]);
    }

    private function makeAgent(
        ?string $email = null,
        ?\Illuminate\Support\Carbon $lastSeenAt = null,
        ?\Illuminate\Support\Carbon $lastAssignedAt = null,
        bool $isActive = true,
    ): User {
        $user = User::factory()->create([
            'email' => $email ?? 'agent-'.uniqid().'@example.com',
            'role' => User::ROLE_AGENT,
            'is_active' => $isActive,
            'last_seen_at' => $lastSeenAt,
            'last_assigned_at' => $lastAssignedAt,
        ]);
        $user->assignRole(User::ROLE_AGENT);

        return $user;
    }
}
