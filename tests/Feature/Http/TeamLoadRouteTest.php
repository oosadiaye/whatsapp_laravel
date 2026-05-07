<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamLoadRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_team_route_requires_team_view_permission(): void
    {
        // Guest → redirect to login.
        $this->get(route('team.index'))
            ->assertRedirect(route('login'));

        // Authenticated agent (no team.view permission) → 403.
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);
        $agent->assignRole(User::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('team.index'))
            ->assertForbidden();

        // Manager (has team.view but NOT users.view) → 200, sees the heading.
        // This is the whole point of separating team.view from users.view —
        // managers get team load visibility without user-CRUD rights.
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);
        $manager->assignRole(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee('Team');

        // Admin (has both team.view and users.view) → 200.
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $admin->assignRole(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee('Team');
    }
}
