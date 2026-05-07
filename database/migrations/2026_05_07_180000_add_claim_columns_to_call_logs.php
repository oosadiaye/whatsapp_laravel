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
            // The browser session UUID that claimed this call. Atomic UPDATE
            // WHERE answered_by_session_id IS NULL guarantees first-tab-wins
            // semantics with no application-layer race window.
            $table->string('answered_by_session_id', 64)->nullable()
                ->after('placed_by_user_id');
            $table->index('answered_by_session_id');

            // SDP offer received from Meta in the connect webhook. Stored so
            // a tab loading mid-ring can fetch the offer via Livewire mount,
            // not only from the live Reverb broadcast.
            $table->text('sdp_offer')->nullable()->after('answered_by_session_id');

            // SDP answer the agent's browser generated. Audit aid only.
            $table->text('sdp_answer')->nullable()->after('sdp_offer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex(['answered_by_session_id']);
            $table->dropColumn(['answered_by_session_id', 'sdp_offer', 'sdp_answer']);
        });
    }
};
