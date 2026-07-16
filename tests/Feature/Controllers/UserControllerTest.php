<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    // ── Privilege-boundary guards (Production-Audit blocker B2) ──────────────
    //
    // The `admin` role keeps `users.edit` (it's super_admin minus create/delete),
    // so without these guards an admin could promote anyone to super_admin or
    // reset any user's password — horizontal → vertical privilege escalation.

    public function test_admin_cannot_promote_another_user_to_super_admin(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('agent', 'target@example.com');

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => 'super_admin',
            ])
            ->assertSessionHas('error');

        $target->refresh();
        $this->assertFalse($target->hasRole('super_admin'), 'admin must not be able to grant super_admin');
        $this->assertTrue($target->hasRole('agent'));
    }

    public function test_admin_cannot_edit_an_existing_super_admin(): void
    {
        $admin = $this->makeUser('admin');
        $super = $this->makeUser('super_admin', 'super@example.com');
        $originalName = $super->name;

        $this->actingAs($admin)
            ->put(route('users.update', $super), [
                'name' => 'Hijacked Name',
                'email' => $super->email,
                'role' => 'super_admin',
            ])
            ->assertSessionHas('error');

        $this->assertSame($originalName, $super->fresh()->name, 'admin must not be able to edit a super_admin');
    }

    public function test_admin_setting_another_users_password_requires_current_password(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('agent', 'target@example.com');
        $originalHash = $target->password;

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => 'agent',
                'password' => 'newpassword123',
                // deliberately omitting current_password
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($originalHash, $target->fresh()->password, "target's password must be unchanged");
    }

    public function test_admin_can_set_another_users_password_with_correct_current_password(): void
    {
        // Factory seeds every user's password as 'password'.
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('agent', 'target@example.com');

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => 'agent',
                'password' => 'newpassword123',
                'current_password' => 'password',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertTrue(Hash::check('newpassword123', $target->fresh()->password));
    }

    public function test_admin_can_edit_a_regular_user_without_current_password_when_not_touching_password(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('agent', 'target@example.com');

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'name' => 'Renamed',
                'email' => $target->email,
                'role' => 'agent',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertSame('Renamed', $target->fresh()->name);
    }

    public function test_super_admin_can_still_assign_super_admin_role(): void
    {
        // Regression guard: the boundary must not block the legitimate flow.
        $super = $this->makeUser('super_admin');
        $target = $this->makeUser('agent', 'target@example.com');

        $this->actingAs($super)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => 'super_admin',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertTrue($target->fresh()->hasRole('super_admin'));
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
