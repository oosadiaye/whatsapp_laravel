<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote call_logs.provider_session_id from a plain index to UNIQUE.
 *
 * Africa's Talking can fire the same inbound webhook event more than once
 * (retries, at-least-once delivery). Without a unique constraint two
 * near-simultaneous events for one session race past the "find existing"
 * check and insert duplicate call rows — the customer double-rings and the
 * call gets double-assigned. The unique index makes the DB the final arbiter.
 *
 * NULL is allowed to repeat (Meta calls use meta_call_id, not this column),
 * which both MySQL and SQLite permit in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Backfill: collapse any existing duplicates so the unique index can
        //    be created. Keep the earliest row per session; null the session id
        //    on the later duplicates (preserves the rows, frees the constraint).
        $dupeSessionIds = DB::table('call_logs')
            ->select('provider_session_id')
            ->whereNotNull('provider_session_id')
            ->groupBy('provider_session_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('provider_session_id');

        foreach ($dupeSessionIds as $sessionId) {
            $keepId = DB::table('call_logs')->where('provider_session_id', $sessionId)->min('id');

            DB::table('call_logs')
                ->where('provider_session_id', $sessionId)
                ->where('id', '!=', $keepId)
                ->update(['provider_session_id' => null]);
        }

        // 2) Swap the plain index for a unique one.
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropIndex(['provider_session_id']);
        });
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->unique('provider_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropUnique(['provider_session_id']);
        });
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->index('provider_session_id');
        });
    }
};
