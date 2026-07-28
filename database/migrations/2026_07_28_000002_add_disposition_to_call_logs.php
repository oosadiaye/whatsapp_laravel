<?php

declare(strict_types=1);

use App\Models\CallLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('disposition', 50)->nullable()->after('failure_reason');
            $table->timestamp('wrap_up_at')->nullable()->after('disposition');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn(['disposition', 'wrap_up_at']);
        });
    }
};
