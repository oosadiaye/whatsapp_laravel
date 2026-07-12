<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call intelligence columns: a private recording of the call audio plus the
 * Gemini-generated transcript, summary, and key points that power the Call
 * Workspace right-hand panel.
 *
 * The recording is stored on the private disk (permission-gated stream, never
 * hotlinked). ai_status drives the panel: none → pending → processing →
 * completed | failed | unavailable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            // Private-disk key for the uploaded call audio (e.g. call-recordings/xxxx.webm).
            $table->string('recording_path')->nullable()->after('quality_metrics');
            $table->string('recording_mime')->nullable()->after('recording_path');
            $table->timestamp('recording_uploaded_at')->nullable()->after('recording_mime');

            // Gemini output.
            $table->longText('transcript')->nullable()->after('recording_uploaded_at');
            $table->text('ai_summary')->nullable()->after('transcript');
            $table->json('ai_key_points')->nullable()->after('ai_summary');

            // Pipeline state for the panel. Default 'none' = nothing to analyse yet.
            $table->string('ai_status', 20)->default('none')->after('ai_key_points');
            $table->text('ai_error')->nullable()->after('ai_status');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'recording_path',
                'recording_mime',
                'recording_uploaded_at',
                'transcript',
                'ai_summary',
                'ai_key_points',
                'ai_status',
                'ai_error',
            ]);
        });
    }
};
