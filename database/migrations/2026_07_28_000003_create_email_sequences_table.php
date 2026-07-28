<?php

declare(strict_types=1);

use App\Models\EmailSequence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('email_account_id')->nullable()->constrained('email_accounts')->nullOnDelete();
            $table->string('name');
            $table->string('status', 20)->default(EmailSequence::STATUS_DRAFT);
            $table->json('target_filters')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_sequence_id')->constrained('email_sequences')->cascadeOnDelete();
            $table->unsignedTinyInteger('order')->default(0);
            $table->string('subject');
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->unsignedInteger('delay_hours')->default(0);
            $table->unsignedInteger('delay_days')->default(0);
            $table->timestamps();
        });

        Schema::create('email_sequence_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_sequence_id')->constrained('email_sequences')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('email');
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->string('status', 20)->default(EmailSequence::RECIPIENT_PENDING);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('open_count')->default(0);
            $table->unsignedSmallInteger('click_count')->default(0);
            $table->unsignedSmallInteger('reply_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sequence_recipients');
        Schema::dropIfExists('email_sequence_steps');
        Schema::dropIfExists('email_sequences');
    }
};
