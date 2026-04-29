<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the user-management permission gates on every action.
 *
 * Strategy: each role sees a different slice of behavior. A 'super_admin'
 * gets through every gate. An 'agent' is blocked from /users entirely.
 * The middle roles ('admin', 'manager') are exercised for their specific
 * cuts of access.
 *
 * Self-edit guards (no self-demote, no self-deactivate, no self-delete)
 * are tested explicitly because they're the highest-risk failure mode —
 * a slip there could permanently lock the only super_admin out of their app.
 */
class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_super_admin_can_list_users(): void
    {
        $this->actingAs($this->makeUser('super_admin'))
            ->get(route('users.index'))
            ->assertOk();
    }

    public function test_agent_cannot_view_users_index(): void
    {
        $this->actingAs($this->makeUser('agent'))
            ->get(route('users.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_but_cannot_create_users(): void
    {
        // 'admin' lacks users.create permission — only super_admin gets that.
        $this->actingAs($this->makeUser('admin'))
            ->get(route('users.index'))
            ->assertOk();

        $this->actingAs($this->makeUser('admin'))
            ->get(route('users.create'))
            ->assertForbidden();
    }

    public function test_super_admin_can_create_user_with_role(): void
    {
        $this->actingAs($this->makeUser('super_admin'))
            ->post(route('users.store'), [
                'name' => 'New Agent',
                'email' => 'new.agent@example.com',
                'password' => 'password123',
                'role' => 'agent',
            ])
            ->assertRedirect(route('users.index'));

        $created = User::where('email', 'new.agent@example.com')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('agent'));
        $this->assertTrue($created->is_active);
    }

    public function test_create_user_validates_required_fields(): void
    {
        $this->actingAs($this->makeUser('super_admin'))
            ->post(route('users.store'), [])
            ->assertSessionHasErrors(['name', 'email', 'password', 'role']);
    }

    public function test_create_user_blocks_duplicate_email(): void
    {
        $existing = $this->makeUser('agent', 'taken@example.com');

        $this->actingAs($this->makeUser('super_admin'))
            ->post(route('users.store'), [
                'name' => 'Other',
                'email' => 'taken@example.com',
                'password' => 'password123',
                'role' => 'agent',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_self_edit_blocks_role_change(): void
    {
        $superAdmin = $this->makeUser('super_admin');

        $this->actingAs($superAdmin)
            ->put(route('users.update', $superAdmin), [
                'name' => $superAdmin->name,
                'email' => $superAdmin->email,
                'role' => 'agent', // attempting self-demotion
            ])
            ->assertSessionHas('error');

        $superAdmin->refresh();
        $this->assertTrue($superAdmin->hasRole('super_admin'));
        $this->assertFalse($superAdmin->hasRole('agent'));
    }

    public function test_self_deactivate_blocked(): void
    {
        $superAdmin = $this->makeUser('super_admin');

        $this->actingAs($superAdmin)
            ->post(route('users.toggleActive', $superAdmin))
            ->assertSessionHas('error');

        $superAdmin->refresh();
        $this->assertTrue($superAdmin->is_active);
    }

    public function test_self_delete_blocked(): void
    {
        $superAdmin = $this->makeUser('super_admin');

        $this->actingAs($superAdmin)
            ->delete(route('users.destroy', $superAdmin))
            ->assertSessionHas('error');

        $this->assertNotNull(User::find($superAdmin->id));
    }

    public function test_super_admin_can_deactivate_other_user(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $other = $this->makeUser('agent', 'other@example.com');

        $this->actingAs($superAdmin)
            ->post(route('users.toggleActive', $other))
            ->assertRedirect();

        $other->refresh();
        $this->assertFalse($other->is_active);
    }

    public function test_super_admin_can_delete_other_user(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $other = $this->makeUser('agent', 'todelete@example.com');

        $this->actingAs($superAdmin)
            ->delete(route('users.destroy', $other))
            ->assertRedirect(route('users.index'));

        $this->assertNull(User::find($other->id));
    }

    public function test_legacy_isAdmin_helper_still_works_for_spatie_admins(): void
    {
        // Backward-compat check: AdminOnly middleware uses isAdmin(),
        // which must return true for spatie admin/super_admin even when
        // legacy 'role' column says 'user'.
        $u = User::factory()->create(['role' => 'user']);
        $u->assignRole('admin');
        $u->refresh();

        $this->assertTrue($u->isAdmin());
    }

    private function makeUser(string $role, string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? "{$role}-".uniqid().'@example.com',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
