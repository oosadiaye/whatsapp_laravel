<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_warmup_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('target_email')->nullable();
            $table->unsignedTinyInteger('daily_target')->default(5);
            $table->timestamp('last_warmup_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_warmup_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->string('recipient_email');
            $table->string('status', 20)->default('sent');
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_warmup_logs');
        Schema::dropIfExists('email_warmup_settings');
    }
};
