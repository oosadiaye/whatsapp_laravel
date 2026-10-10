<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Resync the denormalized `users.role` column from the authoritative spatie role.
 *
 * UserController used to collapse the column to 'admin'/'user', so it never held
 * 'agent'/'manager'. Six features route by `User::callStaff()` (whereIn
 * CALL_STAFF_ROLES on that column), so no user was ever routable and every
 * inbound call hit "all agents busy". The controller now writes the granular
 * role; this backfills rows created before that fix so existing managers/admins/
 * agents become routable without being re-saved through the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resolve the morph type the way spatie stores it (honours any morph map),
        // so this matches the real model_has_roles rows on every environment.
        $userMorph = (new User)->getMorphClass();

        $rows = DB::table('model_has_roles as mhr')
            ->join('roles', 'roles.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $userMorph)
            ->orderBy('roles.id') // deterministic if a user somehow holds >1 role
            ->get(['mhr.model_id as user_id', 'roles.name as role']);

        foreach ($rows->groupBy('user_id') as $userId => $group) {
            DB::table('users')
                ->where('id', $userId)
                ->update(['role' => $group->first()->role]);
        }
    }

    /**
     * Irreversible: the pre-migration values were a lossy 'admin'/'user' collapse
     * that carried no routing information worth restoring.
     */
    public function down(): void
    {
        // no-op
    }
};
