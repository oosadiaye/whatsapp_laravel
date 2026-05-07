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
            // pending = never asked (default), granted = browser said yes,
            // denied = browser said no. The browser's permission API is the
            // source of truth; this column drives a "Grant microphone access"
            // hint banner only.
            $table->string('mic_permission_state', 16)
                ->default('pending')
                ->after('presence_status_set_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mic_permission_state');
        });
    }
};
