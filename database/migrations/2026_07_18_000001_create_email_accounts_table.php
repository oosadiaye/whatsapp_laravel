<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee email client (plan B1) — a connected mailbox owned by one user.
 * Additive + inert while config('mail_client.enabled') is false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('provider');                 // gmail | graph | imap
            $table->string('display_name')->nullable();
            $table->text('credentials')->nullable();     // ENCRYPTED at rest (OAuth tokens / IMAP creds)
            $table->json('sync_state')->nullable();       // provider cursor: historyId / delta token / UIDVALIDITY+UID
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_reauth')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // softDeletes + this plain unique = the contact trap: reconnecting a
            // disconnected account must revive the trashed row (see
            // EmailAccount::firstOrNewIncludingTrashed), not collide.
            $table->unique(['user_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
