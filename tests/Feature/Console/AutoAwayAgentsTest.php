<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoAwayAgentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_marks_idle_call_staff_of_any_role_away(): void
    {
        // Managers/admins are call staff too, so an idle manager must be
        // auto-away'd the same as an idle agent (previously role=agent only).
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
            'presence_status' => User::PRESENCE_AVAILABLE,
            'last_seen_at' => now()->subMinutes(30),
        ]);
        $manager->assignRole('manager');

        $this->artisan('agents:auto-away', ['--threshold' => 5])
            ->assertSuccessful();

        $this->assertSame(
            User::PRESENCE_AWAY,
            $manager->fresh()->presence_status,
            'an idle manager (call staff) must be auto-marked away'
        );
    }

    public function test_leaves_recently_active_call_staff_available(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
            'presence_status' => User::PRESENCE_AVAILABLE,
            'last_seen_at' => now(),
        ]);
        $manager->assignRole('manager');

        $this->artisan('agents:auto-away', ['--threshold' => 5])
            ->assertSuccessful();

        $this->assertSame(User::PRESENCE_AVAILABLE, $manager->fresh()->presence_status);
    }
}
