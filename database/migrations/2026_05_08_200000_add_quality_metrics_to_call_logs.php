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
            // 7-field call-quality summary, populated on call-end via the
            // browser POST to /calls/{call}/quality. NULL for pre-Phase-19a
            // rows and for calls where browser teardown happened before the
            // POST landed.
            //
            // Shape: {
            //   "mos": 4.2,                    // 1.0-5.0 G.107 derivation
            //   "avg_jitter_ms": 18,           // float, milliseconds
            //   "avg_packet_loss_pct": 0.3,    // float, 0.0-100.0
            //   "avg_rtt_ms": 145,             // integer, milliseconds
            //   "samples_captured": 18,        // integer; <3 = unreliable
            //   "ice_candidate_type": "host",  // host | srflx | relay | prflx | unknown
            //   "codec": "opus"                // string
            // }
            $table->json('quality_metrics')->nullable()->after('cost_estimate_kobo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn('quality_metrics');
        });
    }
};
