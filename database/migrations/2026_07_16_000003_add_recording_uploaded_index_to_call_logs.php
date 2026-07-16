<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit L5: PruneCallRecordings runs daily and filters call_logs by
 * whereNotNull(recording_path)->where(recording_uploaded_at,'<',cutoff) with no
 * supporting index — a full scan once call recording is enabled at volume.
 * Index the range column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->index('recording_uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex(['recording_uploaded_at']);
        });
    }
};
