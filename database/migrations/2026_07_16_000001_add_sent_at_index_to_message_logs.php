<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit M3: the dashboard filters and DATE()-groups message_logs by sent_at
 * (messagesToday + the 30-day trend), but only (campaign_id,status) and
 * whatsapp_message_id were indexed — so every dashboard load full-scanned the
 * table as it grows with campaign volume. Index sent_at for the range scans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['sent_at']);
        });
    }
};
