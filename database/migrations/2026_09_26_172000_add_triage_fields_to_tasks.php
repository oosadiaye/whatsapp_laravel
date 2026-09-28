<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Priority is an integer rank, not a string enum. Its only real job
            // is to be sorted, and ORDER BY on a string enum ('urgent' sorts
            // between 'normal' and 'low') needs a CASE expression that has to be
            // kept in sync with the constant list by hand. 4 is the highest.
            $table->unsignedTinyInteger('priority')->default(2)->after('status');

            // Nullable so "no deadline" is a real, storable state rather than
            // the epoch.
            $table->timestamp('due_at')->nullable()->after('priority');

            // The unit is in the name on purpose: a bare "estimate" column
            // invites someone storing hours and someone else storing days.
            $table->unsignedInteger('estimate_minutes')->nullable()->after('due_at');

            // Both the overdue filter and the "sort by due date" view scan
            // boards by deadline, so the index carries board_id to keep that
            // from degrading to a full-table scan as boards grow.
            $table->index(['board_id', 'due_at'], 'tasks_board_due_index');

            $table->index(['board_id', 'priority'], 'tasks_board_priority_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_board_due_index');
            $table->dropIndex('tasks_board_priority_index');
            $table->dropColumn(['priority', 'due_at', 'estimate_minutes']);
        });
    }
};
