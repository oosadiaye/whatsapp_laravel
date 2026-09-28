<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An append-only record of what happened to a card.
     *
     * Deliberately NOT foreign-keyed, unlike the rest of this schema. A log
     * must survive the things it records: an FK from task_id with
     * cascadeOnDelete() would take the entire history of a card the moment
     * that card was hard-deleted, which defeats the only reason the table
     * exists. board_id is therefore denormalised rather than reached through
     * tasks, so a per-board report still works after the card is gone.
     */
    public function up(): void
    {
        Schema::create('task_activity', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('board_id');

            // Null for a change made with no signed-in user (a console command,
            // a queued job) so those rows are still attributable to "nobody"
            // rather than being unrecordable.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('type', 32);

            // Only populated for a transition. Time-in-column reporting reads
            // (to_status, created_at) pairs, so both sides are stored rather
            // than reconstructed.
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();

            // Escape hatch for the details that do not deserve a column: the
            // comment id, the resulting assignee set, and so on.
            $table->json('metadata')->nullable();

            // created_at only. There is no update path on a log, and an
            // updated_at column that is never written is a lie waiting to be
            // believed by a future report.
            $table->timestamp('created_at')->nullable();

            // "History of this card, newest first" - the drawer's own query.
            $table->index(['task_id', 'id'], 'task_activity_task_index');

            // "Everything that happened on this board in this window" - the
            // reporting query. Scoping by board first keeps it off a full scan.
            $table->index(['board_id', 'created_at'], 'task_activity_board_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activity');
    }
};
