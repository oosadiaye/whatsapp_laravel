<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index message_logs.whatsapp_message_id.
 *
 * CloudWebhookController::processStatuses looks up MessageLogs by this
 * column on every inbound status webhook. Without an index that is a full
 * table scan per event; with campaign volume this is the hot path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->index('whatsapp_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_message_id']);
        });
    }
};
