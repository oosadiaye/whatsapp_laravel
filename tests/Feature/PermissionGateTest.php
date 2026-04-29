<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the permission middleware applied to every existing controller
 * in Phase 15. The seeded role permission sets must match what these tests
 * expect — if a role is updated in RolesAndPermissionsSeeder, the
 * corresponding test method here should be the canary.
 *
 * Test pattern: each role gets a logged-in session, hits a representative
 * route from each resource, and we assert the expected status (200 or 403).
 *
 * Strategy choice: testing one route per resource per role gives wide
 * coverage cheaply. Per-action gates (campaigns.create vs campaigns.delete)
 * are tested separately in their controller tests; here we just confirm
 * the middleware fires at all.
 */
class PermissionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_agent_role_access_matrix(): void
    {
        // Agent: contacts (CRUD-light) + assigned conversations + nothing else.
        $agent = $this->makeUser('agent');

        // ✓ Allowed
        $this->actingAs($agent)->get(route('dashboard'))->assertOk();
        $this->actingAs($agent)->get(route('contacts.index'))->assertOk();
        $this->actingAs($agent)->get(route('contacts.import'))->assertOk();

        // ✗ Forbidden — agent has no instance/template/settings/users access.
        $this->actingAs($agent)->get(route('instances.index'))->assertForbidden();
        $this->actingAs($agent)->get(route('instances.create'))->assertForbidden();
        $this->actingAs($agent)->get(route('templates.create'))->assertForbidden();
        $this->actingAs($agent)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($agent)->get(route('users.index'))->assertForbidden();

        // ✓ Read-only access to templates, campaigns, groups (per agent permission set)
        $this->actingAs($agent)->get(route('templates.index'))->assertOk();
        $this->actingAs($agent)->get(route('campaigns.index'))->assertOk();
        $this->actingAs($agent)->get(route('groups.index'))->assertOk();
    }

    public function test_manager_role_access_matrix(): void
    {
        // Manager: full ops, no user mgmt, no instance create/edit/delete.
        $manager = $this->makeUser('manager');

        $this->actingAs($manager)->get(route('dashboard'))->assertOk();
        $this->actingAs($manager)->get(route('campaigns.index'))->assertOk();
        $this->actingAs($manager)->get(route('campaigns.create'))->assertOk();
        $this->actingAs($manager)->get(route('templates.index'))->assertOk();
        $this->actingAs($manager)->get(route('contacts.index'))->assertOk();

        // ✓ Settings allowed
        $this->actingAs($manager)->get(route('settings.index'))->assertOk();

        // ✗ User mgmt forbidden
        $this->actingAs($manager)->get(route('users.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('users.create'))->assertForbidden();

        // ✗ Instance create/edit forbidden (manager.instance permissions)
        $this->actingAs($manager)->get(route('instances.create'))->assertForbidden();
    }

    public function test_admin_role_access_matrix(): void
    {
        // Admin: nearly full. Can manage instances + templates + campaigns + users.view.
        // Cannot users.create / users.delete (only super_admin can).
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->actingAs($admin)->get(route('users.create'))->assertForbidden();  // only super_admin
        $this->actingAs($admin)->get(route('campaigns.create'))->assertOk();
        $this->actingAs($admin)->get(route('instances.create'))->assertOk();
        $this->actingAs($admin)->get(route('settings.index'))->assertOk();
    }

    public function test_super_admin_can_reach_everything(): void
    {
        $sa = $this->makeUser('super_admin');

        // Sanity check on every resource entry point.
        foreach ([
            'dashboard',
            'instances.index',
            'instances.create',
            'groups.index',
            'contacts.index',
            'contacts.import',
            'templates.index',
            'templates.create',
            'campaigns.index',
            'campaigns.create',
            'settings.index',
            'users.index',
            'users.create',
            'conversations.index',
        ] as $routeName) {
            $this->actingAs($sa)->get(route($routeName))
                ->assertOk();  // assertOk() = 200; would fail with 403 anyway
        }
    }

    public function test_user_with_no_role_at_all_is_blocked_from_protected_routes(): void
    {
        // Brand-new user without any role assignment — corner case if a sign-up
        // flow ever forgets to assign a default role.
        $u = User::factory()->create();

        $this->actingAs($u)->get(route('campaigns.index'))->assertForbidden();
        $this->actingAs($u)->get(route('contacts.index'))->assertForbidden();

        // Dashboard should remain accessible — it's the "you're logged in but
        // can't do anything yet" landing page.
        $this->actingAs($u)->get(route('dashboard'))->assertOk();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
