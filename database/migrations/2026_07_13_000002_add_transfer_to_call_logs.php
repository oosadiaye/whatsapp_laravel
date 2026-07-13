<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blind-transfer columns. When an agent transfers a live call, we record the
 * destination on the call; the next AT call-control request routes the customer
 * leg there (Dial to the target agent's client or a PSTN number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            // The pending destination: an agent client name ("agent_5") or a
            // PSTN number. Cleared once the transfer Dial is issued.
            $table->string('transfer_target')->nullable()->after('answered_by_session_id');
            $table->foreignId('transferred_to_user_id')->nullable()->after('transfer_target')
                ->constrained('users')->nullOnDelete();
            $table->string('transfer_type')->nullable()->after('transferred_to_user_id'); // blind | attended
            $table->timestamp('transferred_at')->nullable()->after('transfer_type');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transferred_to_user_id');
            $table->dropColumn(['transfer_target', 'transfer_type', 'transferred_at']);
        });
    }
};
