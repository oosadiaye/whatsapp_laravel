<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voicemails left by inbound callers when the office is closed, all agents are
 * busy, or the caller chose the voicemail IVR option. Africa's Talking records
 * the audio (Voice XML <Record>) and posts a recordingUrl to the callback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voicemails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_log_id')->nullable()->constrained('call_logs')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('from_phone')->nullable();
            // AT-hosted recording URL (may later be mirrored to the private disk).
            $table->string('recording_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('is_heard')->default(false);
            $table->foreignId('heard_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('heard_at')->nullable();
            $table->timestamps();

            $table->index(['is_heard', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voicemails');
    }
};
