<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent notes on a call — an append-only timeline. Each note records who wrote
 * it and when, so "take a note and log it" produces an auditable trail on the
 * Call Workspace panel rather than a single overwritable textarea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_log_id')->constrained('call_logs')->cascadeOnDelete();
            // Nullable + nullOnDelete so a note survives (as "system"/removed user)
            // if the authoring account is later deleted — the note is a record.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['call_log_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_notes');
    }
};
