<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedTinyInteger('email_lead_score')->default(0)->after('is_active');
            $table->unsignedSmallInteger('email_open_count')->default(0)->after('email_lead_score');
            $table->unsignedSmallInteger('email_click_count')->default(0)->after('email_open_count');
            $table->unsignedSmallInteger('email_reply_count')->default(0)->after('email_click_count');
            $table->timestamp('last_email_engagement_at')->nullable()->after('email_reply_count');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['email_lead_score', 'email_open_count', 'email_click_count', 'email_reply_count', 'last_email_engagement_at']);
        });
    }
};
