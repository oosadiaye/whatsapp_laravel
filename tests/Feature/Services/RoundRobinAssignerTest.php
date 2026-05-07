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
