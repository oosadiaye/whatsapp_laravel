<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One inbound or outbound message (plan B1). email_account_id is denormalised so
 * the unique(email_account_id, message_id) dedup constraint can enforce
 * idempotent inbound sync (B3). Column names avoid the SQL reserved words
 * `from`/`references`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_thread_id')->constrained('email_threads')->cascadeOnDelete();
            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->string('direction');                    // inbound | outbound
            $table->string('message_id')->nullable();        // RFC 5322 Message-ID (null for drafts)
            $table->string('in_reply_to')->nullable();
            $table->text('references_header')->nullable();    // References header chain (threading)
            $table->string('from_email')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->string('provider_ref')->nullable();       // provider message id
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            // Dedup per account (MySQL/SQLite allow multiple NULLs, so drafts don't collide).
            $table->unique(['email_account_id', 'message_id']);
            $table->index('email_thread_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
