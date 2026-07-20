<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncEmailAccount;
use App\Models\EmailAccount;
use Illuminate\Console\Command;

/**
 * Fans out inbound sync for every healthy connected mailbox (plan B3). Scheduled
 * on the mail_client sync interval; each account syncs on the `mail-sync` queue.
 * No-op when the feature is off.
 */
class SyncMailboxes extends Command
{
    protected $signature = 'mailbox:sync';

    protected $description = 'Dispatch inbound sync for all active, connected mailboxes';

    public function handle(): int
    {
        if (! config('mail_client.enabled')) {
            return self::SUCCESS;
        }

        $count = 0;
        EmailAccount::query()
            ->where('is_active', true)
            ->where('needs_reauth', false)
            ->select('id')
            ->chunkById(500, function ($accounts) use (&$count): void {
                foreach ($accounts as $account) {
                    SyncEmailAccount::dispatch($account->id);
                    $count++;
                }
            });

        $this->info("Dispatched sync for {$count} mailbox(es).");

        return self::SUCCESS;
    }
}
