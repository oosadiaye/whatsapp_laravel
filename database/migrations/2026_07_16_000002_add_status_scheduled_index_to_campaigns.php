<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit M4: campaigns had no index on status/scheduled_at, unlike its sibling
 * email_campaigns. DispatchScheduledCampaigns (runs on schedule) and
 * CampaignController::clearQueue() both scan by status unindexed. Mirror the
 * email_campaigns (status, scheduled_at) composite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
        });
    }
};
