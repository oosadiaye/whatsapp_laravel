<?php

declare(strict_types=1);

use App\Models\EmailAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table): void {
            // How often this mailbox is checked for new mail (minutes); the person picks it per mailbox.
            $table->unsignedSmallInteger('sync_interval_minutes')->default(2)->after('last_synced_at');
            $table->timestamp('last_sync_attempt_at')->nullable()->after('sync_interval_minutes');
            // Plain-language reason the last check failed; null when the last check worked.
            $table->string('last_sync_error', 500)->nullable()->after('last_sync_attempt_at');
        });

        Schema::table('email_messages', function (Blueprint $table): void {
            // null = normal; 'failed' = we tried to send this and the mail server refused or was unreachable.
            $table->string('send_status', 20)->nullable()->after('has_attachments');
            $table->string('send_error', 500)->nullable()->after('send_status');
            $table->index(['email_account_id', 'send_status']);
        });

        // Mailboxes still crawling their history oldest-first restart newest-first, so recent mail
        // shows up now instead of after the whole backlog. Already-stored mail is skipped by dedup.
        EmailAccount::query()->whereNotNull('sync_state')->each(function (EmailAccount $account): void {
            if (($account->sync_state['backfilling'] ?? false) === true) {
                $account->update(['sync_state' => null, 'last_synced_at' => null]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table): void {
            $table->dropIndex(['email_account_id', 'send_status']);
            $table->dropColumn(['send_status', 'send_error']);
        });

        Schema::table('email_accounts', function (Blueprint $table): void {
            $table->dropColumn(['sync_interval_minutes', 'last_sync_attempt_at', 'last_sync_error']);
        });
    }
};
