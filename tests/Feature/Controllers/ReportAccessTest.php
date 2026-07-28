<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L1: /reports is gated on reports.view. That permission was never created, so
 * the page 403'd for everyone (including super_admin). These lock in that the
 * permission now exists and is granted to manager+ but not agent.
 */
class ReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_manager_can_view_reports(): void
    {
        $this->actingAs($this->userWithRole('manager'))
            ->get(route('reports.index'))
            ->assertOk();
    }

    public function test_admin_can_view_reports(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('reports.index'))
            ->assertOk();
    }

    public function test_agent_cannot_view_reports(): void
    {
        $this->actingAs($this->userWithRole('agent'))
            ->get(route('reports.index'))
            ->assertForbidden();
    }
}
