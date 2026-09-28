<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Card ordering uses `position`, and moves were stored as the card *count* of
     * the target column — which lands a moved card on the same integer as the
     * card already occupying that slot. That tie is the bug §3.6 names: two cards
     * sharing a position order by id, so a drag silently does not go where the
     * user dropped it, and two concurrent drags can interleave.
     *
     * Fix, in three steps:
     *  1. Make positions unique *within a column* so a collision is a hard error
     *     rather than a silent tie (the database is the source of truth; the
     *     controller retries on the rare concurrent collision).
     *  2. The controller reserves a free slot by pushing the suffix up one, so a
     *     move never lands on an occupied position.
     *  3. De-duplicate existing rows first, or the unique index cannot be added.
     */
    public function up(): void
    {
        // Re-number every (board_id, status) group to 1..N in the current visual
        // order. Soft-deleted cards are included: the unique index counts them,
        // so a duplicate among them would block the index too. Ordering is by
        // (position, id), so the visible arrangement is preserved.
        $tasks = DB::table('tasks')
            ->orderBy('board_id')
            ->orderBy('status')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'board_id', 'status']);

        $lastKey = null;
        $n = 0;

        foreach ($tasks as $task) {
            $key = $task->board_id.'|'.$task->status;

            if ($key !== $lastKey) {
                $lastKey = $key;
                $n = 0;
            }

            $n++;
            DB::table('tasks')->where('id', $task->id)->update(['position' => $n]);
        }

        Schema::table('tasks', function (Blueprint $table) {
            // Drop the old non-unique composite index so it does not shadow the
            // unique one we are about to add.
            $table->dropIndex(['board_id', 'position']);

            // Unique within a column: two cards in the same column may never
            // share a position. Across columns duplicate values are fine — the
            // board groups by status, so position only has to be unique per
            // (board, status).
            $table->unique(['board_id', 'status', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['board_id', 'status', 'position']);
            $table->index(['board_id', 'position']);
        });
    }
};
