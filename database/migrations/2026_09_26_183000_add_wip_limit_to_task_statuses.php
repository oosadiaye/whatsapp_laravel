<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-column work-in-progress ceiling.
 *
 * Nullable, and null means "no ceiling" rather than zero: a column nobody has
 * constrained should not render as 0/0 and look like the worst column on the
 * board.
 *
 * The ceiling is company-wide, matching task_statuses itself - one vocabulary,
 * one limit per column. A team wanting a different ceiling per board would need
 * per-board column sets (the deferred half of this feature), not a nullable
 * integer pretending to be that.
 *
 * Advisory only. Nothing in the write path consults this to refuse a move, and
 * the reason is worth keeping in the schema: a hard ceiling is unrecoverable the
 * moment a limit is set below the current occupancy. The column is already over,
 * nobody may add to it, and the only way out is for an admin to notice and edit
 * the number - so the team's response to a real constraint is to route around
 * the board. TaskStatus::effectiveLimit() and TaskStatusController carry the
 * rest of the reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_statuses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('wip_limit')->nullable()->after('is_completed');
        });
    }

    public function down(): void
    {
        Schema::table('task_statuses', function (Blueprint $table): void {
            $table->dropColumn('wip_limit');
        });
    }
};
