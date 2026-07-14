<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email broadcast campaigns — the email sibling of WhatsApp campaigns. Targets
 * contact groups (contacts with an email), sends via Laravel Mail, and supports
 * one-off scheduling plus simple recurrence (daily/weekly/monthly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('subject');
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->longText('body_html');

            $table->string('status')->default('draft'); // draft|scheduled|queued|sending|sent|failed|paused|cancelled

            // Scheduling + recurrence.
            $table->timestamp('scheduled_at')->nullable();
            $table->string('recurrence')->default('none'); // none|daily|weekly|monthly
            $table->timestamp('recurrence_until')->nullable();
            $table->timestamp('last_run_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Throttle so bulk sends don't trip provider rate limits.
            $table->unsignedInteger('rate_per_minute')->default(60);

            // Counters.
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);

            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');
    }
};
