<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the four roles and all permissions used across the app.
 *
 * Idempotent — safe to re-run. firstOrCreate avoids duplicate role/permission
 * exceptions, and syncPermissions replaces the role's permission list rather
 * than appending so changes here propagate cleanly on re-seed.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Wipe spatie's role/permission cache so newly-created records are visible
        // immediately to every check that runs after this seeder.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = $this->createPermissions();
        $this->createRoles($permissions);
        $this->upgradeAdminUser();
    }

    /**
     * Create every permission we'll check anywhere in the app.
     * Naming convention: `<resource>.<action>` so spatie's Blade `@can('users.create')` reads naturally.
     *
     * @return array<string>
     */
    private function createPermissions(): array
    {
        $perms = [
            // User management
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',

            // WhatsApp instances (credentials)
            'instances.view',
            'instances.create',
            'instances.edit',
            'instances.delete',

            // Contacts + groups
            'contacts.view',
            'contacts.create',
            'contacts.edit',
            'contacts.delete',
            'contacts.import',
            'groups.view',
            'groups.create',
            'groups.edit',
            'groups.delete',

            // Templates
            'templates.view',
            'templates.create',
            'templates.edit',
            'templates.delete',
            'templates.sync',
            'templates.submit',

            // Campaigns
            'campaigns.view',
            'campaigns.create',
            'campaigns.edit',
            'campaigns.delete',
            'campaigns.launch',
            'campaigns.cancel',

            // Conversations / chat (Phases 13-14)
            'conversations.view_all',     // see everyone's chats
            'conversations.view_assigned', // see only assigned-to-me
            'conversations.reply',
            'conversations.assign',        // route a chat to a staff member
            'conversations.call',          // initiate voice calls

            // Settings
            'settings.view',
            'settings.edit',
        ];

        foreach ($perms as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        return $perms;
    }

    /**
     * Create the four roles and assign their permission sets.
     *
     * @param  array<string>  $allPermissions
     */
    private function createRoles(array $allPermissions): void
    {
        // Super admin: everything.
        $superAdmin = Role::firstOrCreate(['name' => User::ROLE_SUPER_ADMIN, 'guard_name' => 'web']);
        $superAdmin->syncPermissions($allPermissions);

        // Admin: same as super_admin minus user create/delete + instance delete.
        $admin = Role::firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
        $admin->syncPermissions(array_diff($allPermissions, [
            'users.create',
            'users.delete',
            'instances.delete',
        ]));

        // Manager: full operational access but no user management at all + no instance management.
        $manager = Role::firstOrCreate(['name' => User::ROLE_MANAGER, 'guard_name' => 'web']);
        $manager->syncPermissions(array_diff($allPermissions, [
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'instances.create',
            'instances.edit',
            'instances.delete',
        ]));

        // Agent (support staff): contacts + assigned conversations only.
        $agent = Role::firstOrCreate(['name' => User::ROLE_AGENT, 'guard_name' => 'web']);
        $agent->syncPermissions([
            'contacts.view',
            'contacts.create',
            'contacts.edit',
            'contacts.import',
            'groups.view',
            'templates.view',
            'campaigns.view',
            'conversations.view_assigned',
            'conversations.reply',
        ]);
    }

    /**
     * Promote the default seeded admin user to super_admin role.
     * Safe to re-run — assignRole is idempotent.
     */
    private function upgradeAdminUser(): void
    {
        $admin = User::where('email', 'admin@blastiq.com')->first();
        if ($admin) {
            $admin->assignRole(User::ROLE_SUPER_ADMIN);
            $admin->update(['is_active' => true]);
        }
    }
}
