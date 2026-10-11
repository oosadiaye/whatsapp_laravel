<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The role-column backfill migration runs against real production rows, but
 * RefreshDatabase executes it on an EMPTY users table (seeding happens after
 * migration), so its loop is never exercised by the normal suite. Re-invoke up()
 * against deliberately drifted rows to prove the resync works before it touches
 * prod — the migration is the only thing that makes existing users routable.
 */
class ResyncUsersRoleColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_backfills_the_role_column_from_each_users_spatie_role(): void
    {
        // Reproduce exactly the drift UserController used to create: the column
        // collapsed to 'admin'/'user' while the spatie role holds the real one.
        $superAdmin = $this->makeDrifted('super@example.com', 'admin', User::ROLE_SUPER_ADMIN);
        $manager = $this->makeDrifted('manager@example.com', 'user', User::ROLE_MANAGER);
        $agent = $this->makeDrifted('agent@example.com', 'user', User::ROLE_AGENT);

        $this->runBackfill();

        $this->assertSame(User::ROLE_SUPER_ADMIN, $superAdmin->fresh()->role);
        $this->assertSame(User::ROLE_MANAGER, $manager->fresh()->role);
        $this->assertSame(User::ROLE_AGENT, $agent->fresh()->role);
    }

    public function test_backfilled_users_become_call_staff(): void
    {
        // The point of the migration: after it runs these rows match callStaff(),
        // which is what makes an inbound call routable instead of "agents busy".
        $manager = $this->makeDrifted('manager@example.com', 'user', User::ROLE_MANAGER);

        $this->assertFalse(
            User::callStaff()->whereKey($manager->id)->exists(),
            'a drifted row must not be call staff before the backfill'
        );

        $this->runBackfill();

        $this->assertTrue(
            User::callStaff()->whereKey($manager->id)->exists(),
            'after the backfill the manager must be routable'
        );
    }

    public function test_leaves_a_user_without_a_spatie_role_untouched(): void
    {
        $roleless = User::factory()->create(['email' => 'none@example.com', 'role' => 'user']);

        $this->runBackfill();

        $this->assertSame('user', $roleless->fresh()->role);
    }

    private function makeDrifted(string $email, string $columnRole, string $spatieRole): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => $columnRole]);
        $user->assignRole($spatieRole);

        return $user;
    }

    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/2026_10_11_000000_resync_users_role_column_from_spatie.php'
        );

        $migration->up();
    }
}
