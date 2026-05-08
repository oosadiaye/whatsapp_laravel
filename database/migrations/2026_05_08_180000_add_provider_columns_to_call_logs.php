<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            // 'meta_whatsapp' (existing — Phase 14.x + 17) or 'africas_talking'
            // (Phase 18). Determines API client to use for terminate, webhook
            // signature scheme to verify, and JS factory the browser mounts.
            $table->string('provider', 32)->default('meta_whatsapp')->after('direction');
            $table->index('provider');

            // Africa's Talking session ID. Populated when provider='africas_talking'.
            // Mutually exclusive with meta_call_id (each row uses exactly one
            // provider's identifier).
            $table->string('provider_session_id', 128)->nullable()->after('meta_call_id');

            // Estimated cost in kobo (1/100 NGN), computed on call-end from
            // duration_seconds * africastalking_rate_per_minute_kobo / 60.
            // Integer to avoid float rounding on currency. Nullable — Meta
            // calls stay null (free, not metered).
            $table->unsignedInteger('cost_estimate_kobo')->nullable()->after('duration_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropColumn(['provider', 'provider_session_id', 'cost_estimate_kobo']);
        });
    }
};
