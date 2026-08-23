<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Defense-in-depth for the campaign double-send race: a unique
 * (campaign_id, contact_id) guarantees at most one log per recipient per
 * campaign even if two batch jobs ever race the app-level dedupe. The atomic
 * QUEUED->RUNNING claim in CampaignBatchDispatch is the primary guard; this is
 * the backstop.
 *
 * First remove any pre-existing duplicates (keep the earliest row per pair) so
 * the index can be created on a table that already has double-sent rows. The
 * derived-table wrapper + `id NOT IN` form is portable across MySQL (which
 * forbids referencing the delete target directly in the subquery) and the
 * SQLite test database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            DELETE FROM message_logs
            WHERE id NOT IN (
                SELECT keep_id FROM (
                    SELECT MIN(id) AS keep_id
                    FROM message_logs
                    GROUP BY campaign_id, contact_id
                ) AS keepers
            )
        ');

        Schema::table('message_logs', function (Blueprint $table) {
            $table->unique(['campaign_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'contact_id']);
        });
    }
};
