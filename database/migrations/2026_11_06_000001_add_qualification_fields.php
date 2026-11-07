<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('hot_threshold')->default(75);
            $table->unsignedTinyInteger('warm_threshold')->default(50);
            // AI judgements per business day (code-gate rejections are free and do not count).
            $table->unsignedInteger('daily_qualify_cap')->default(100);
            // Platform credits (one per revealed email) per business month; 0 disables revealing.
            $table->unsignedInteger('monthly_reveal_budget')->default(200);
            $table->text('last_qualify_error')->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedTinyInteger('qualify_attempts')->default(0);
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('revealed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['qualify_attempts', 'qualified_at', 'revealed_at']);
        });

        Schema::table('lead_profiles', function (Blueprint $table) {
            $table->dropColumn(['hot_threshold', 'warm_threshold', 'daily_qualify_cap', 'monthly_reveal_budget', 'last_qualify_error']);
        });
    }
};
