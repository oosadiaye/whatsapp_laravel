<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index call_logs on (direction, status, created_at).
 *
 * RealtimePulse polls this exact shape every 3 seconds for every online agent
 * (in-flight inbound + missed counts), and CallController::index filters the
 * same columns. The existing indexes cover (conversation_id, created_at) and
 * (whatsapp_instance_id, status, created_at) but not this hot path — without
 * it the poll becomes a widening scan as call_logs grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->index(['direction', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex(['direction', 'status', 'created_at']);
        });
    }
};
