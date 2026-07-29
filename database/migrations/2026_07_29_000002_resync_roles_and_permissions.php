<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Re-run the roles & permissions seeder so environments that deploy with
 * `php artisan migrate` alone (not `db:seed`) pick up permissions that were
 * ADDED to the seeder AFTER the initial 2026_07_28 seed migration already ran —
 * notably `mailbox.view` (gates the per-employee Mailbox in the sidebar/routes)
 * and `reports.view` (gates the Reports & Health page).
 *
 * A migration runs once, so the original seed migration can't back-fill later
 * additions on a DB it has already touched. This migration does. The seeder is
 * idempotent (Permission::firstOrCreate + Role::syncPermissions), so re-running
 * only creates what's missing and re-grants roles — existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\RolesAndPermissionsSeeder',
            '--force' => true,
        ]);
    }

    public function down(): void
    {
        // No-op: permissions are additive and safe to keep.
    }
};
