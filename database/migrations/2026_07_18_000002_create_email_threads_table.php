<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A conversation thread within a connected mailbox (plan B1). Denormalised
 * last_message_at / unread_count for fast inbox sorting, mirroring conversations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('folder')->default('inbox');   // inbox | sent | archive | trash
            // Optional agent assignment — reuses the conversations pattern, but the
            // client defaults to PRIVATE-per-user (mailbox.* perms), not shared.
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('thread_ref')->nullable();      // provider thread id (Gmail threadId, etc.)
            $table->timestamps();

            $table->index(['email_account_id', 'last_message_at']);
            $table->index('assigned_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_threads');
    }
};
