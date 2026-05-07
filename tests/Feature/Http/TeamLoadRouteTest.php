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

    public function test_team_route_requires_users_view_permission(): void
    {
        // Guest → redirect to login
        $this->get(route('team.index'))
            ->assertRedirect(route('login'));

        // Authenticated agent (no users.view permission) → 403
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);
        $agent->assignRole(User::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('team.index'))
            ->assertForbidden();

        // Admin (has users.view) → 200, sees the page heading
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
