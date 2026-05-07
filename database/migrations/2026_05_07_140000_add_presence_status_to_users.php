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
            // Explicit user-controlled presence status. Defaults to 'available'
            // so seeded/existing users automatically participate in round-robin
            // rotation. Indexed because RoundRobinAssigner adds a WHERE clause
            // on this column in the hot-path next() query.
            $table->string('presence_status', 16)
                ->default('available')
                ->after('last_assigned_at');
            $table->index('presence_status');

            // When the current presence_status was set. NOT indexed — read only
            // by the agent's own UI for "Set N min ago" tooltip via Carbon
            // diffForHumans. Never used as a filter or order key.
            $table->timestamp('presence_status_set_at')
                ->nullable()
                ->after('presence_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['presence_status']);
            $table->dropColumn(['presence_status', 'presence_status_set_at']);
        });
    }
};
