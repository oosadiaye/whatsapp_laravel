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
            $table->string('autonomy', 32)->nullable()->after('is_active');
            $table->foreignId('sender_email_account_id')->nullable()->after('autonomy')->constrained('email_accounts')->nullOnDelete();
            $table->text('playbook')->nullable()->after('sender_email_account_id');
            $table->unsignedTinyInteger('followup_count')->default(2)->after('playbook');
            $table->unsignedTinyInteger('followup_delay_days')->default(3)->after('followup_count');
            $table->unsignedSmallInteger('daily_send_cap')->default(20)->after('followup_delay_days');
            $table->text('last_outreach_error')->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('outreach_started_at')->nullable();
            $table->timestamp('next_followup_at')->nullable();
            $table->unsignedTinyInteger('followups_sent')->default(0);
            $table->index(['status', 'next_followup_at']);
        });

        Schema::create('lead_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // first | followup | reply
            $table->string('status', 24); // pending_approval | approved | sending | sent | discarded | failed
            $table->unsignedTinyInteger('step')->default(0);
            $table->string('subject', 255);
            $table->text('body');
            $table->boolean('by_ai')->default(true);
            $table->unsignedBigInteger('lead_reply_id')->nullable();
            $table->string('message_id', 255)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['lead_id', 'kind']);
        });

        Schema::create('lead_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
            $table->string('classification', 24)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->text('summary')->nullable();
            $table->string('status', 24)->default('new'); // new | handled | needs_human | unsubscribed
            $table->timestamp('seen_at')->nullable();
            $table->unsignedTinyInteger('classify_attempts')->default(0);
            $table->timestamps();
            $table->unique('email_message_id');
            $table->index(['status', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_replies');
        Schema::dropIfExists('lead_messages');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_followup_at']);
            $table->dropColumn(['outreach_started_at', 'next_followup_at', 'followups_sent']);
        });
        Schema::table('lead_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_email_account_id');
            $table->dropColumn(['autonomy', 'playbook', 'followup_count', 'followup_delay_days', 'daily_send_cap', 'last_outreach_error']);
        });
    }
};
