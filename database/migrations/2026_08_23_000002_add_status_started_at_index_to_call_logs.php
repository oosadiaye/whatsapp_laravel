<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stale-call sweeper (calls:cleanup-stale, scheduled everyMinute) filters
 * call_logs on (status, started_at). No existing index leads with `status` — the
 * composites lead with direction / whatsapp_instance_id / conversation_id — so
 * the sweep full-scans call_logs, which grows one row per call forever. This
 * composite makes it an index range scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex(['status', 'started_at']);
        });
    }
};
