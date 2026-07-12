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

            // Note: the WhatsApp number is single-instance now and configured
            // on the Settings page — the old instances.* permissions were
            // removed when the multi-instance CRUD was collapsed.

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

            // Team-load dashboard (Phase 15) — separate from users.* so
            // managers can see team load without gaining user-CRUD access.
            // Granted to super_admin (via "all"), admin (via diff-allow),
            // and manager (via diff-allow — manager exclusion list does
            // NOT contain 'team.view', so it's included by default).
            // Explicitly NOT granted to agent (their syncPermissions is
            // an allowlist; we don't add team.view there).
            'team.view',
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

        // Admin: same as super_admin minus user create/delete.
        $admin = Role::firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
        $admin->syncPermissions(array_diff($allPermissions, [
            'users.create',
            'users.delete',
        ]));

        // Manager: full operational access but no user management at all.
        $manager = Role::firstOrCreate(['name' => User::ROLE_MANAGER, 'guard_name' => 'web']);
        $manager->syncPermissions(array_diff($allPermissions, [
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
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
            // Phase 18 — agents need this to use the Call button on the
            // conversation page (server-side gate on the /calls/outbound
            // route). Without it agents see the button but POSTing returns 403.
            'conversations.call',
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
