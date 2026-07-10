<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index call_logs.provider_session_id.
 *
 * AfricasTalkingWebhookController resolves CallLogs by this column on every
 * inbound webhook event (Ringing/InProgress/Completed/Failed and the
 * isActive=1 call-control request). meta_call_id is already unique, but
 * AT sessions use provider_session_id, so lookups need their own index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->index('provider_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex(['provider_session_id']);
        });
    }
};
