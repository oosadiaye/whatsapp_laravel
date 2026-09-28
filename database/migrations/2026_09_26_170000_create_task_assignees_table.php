<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignees', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Composite PK does the dedup for us: the same person cannot be
            // attached to the same card twice, so toggleAssignee() can rely on
            // an attach() either inserting or throwing on a duplicate race
            // rather than silently creating a second row.
            $table->primary(['task_id', 'user_id']);

            // Supports the reverse direction - "everything assigned to me" -
            // without a full table scan.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignees');
    }
};
