<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime-configurable task statuses.
 *
 * Statuses live in the database rather than config/task-statuses.php so an
 * administrator can add/rename/reorder a column without a deploy. The config
 * file is retained as a read-only fallback (see TaskStatus::fallbackColumns())
 * for installs whose seeder has not run yet.
 *
 * The `slug` is the machine value persisted in tasks.status, so it is the
 * column's real identity: renaming a `name` is safe, changing a `slug` that
 * tasks already reference is not (TaskStatusController guards that).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('color', 40)->default('gray');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_completed')->default(false);
            $table->timestamps();

            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_statuses');
    }
};
