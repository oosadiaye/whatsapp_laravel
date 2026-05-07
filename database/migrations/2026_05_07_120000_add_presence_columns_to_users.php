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
        Schema::table('users', function (Blueprint $table) {
            // Implicit-heartbeat presence: touched on every RealtimePulse poll
            // (with 30s dedup). 'available' for round-robin = last_seen_at >=
            // now()->subMinutes(2). NULL means user has never logged in since
            // this column was added — naturally excluded from rotation.
            $table->timestamp('last_seen_at')->nullable()->after('is_active');
            $table->index('last_seen_at');

            // Round-robin pointer: stamped to now() when an agent is assigned
            // a new conversation. Pick query orders by last_assigned_at ASC
            // with NULLS FIRST, so newer agents (NULL) get prioritized; older
            // stamps are deprioritized until they "rotate forward" again.
            $table->timestamp('last_assigned_at')->nullable()->after('last_seen_at');
            $table->index('last_assigned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_assigned_at']);
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn(['last_assigned_at', 'last_seen_at']);
        });
    }
};
