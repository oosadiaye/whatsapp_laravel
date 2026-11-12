<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncEmailAccount;
use App\Models\EmailAccount;
use Illuminate\Console\Command;

/**
 * Fans out inbound sync for every healthy connected mailbox that is due (plan B3). The scheduler
 * runs this every minute; a mailbox is due once its own interval (the person's choice, default
 * mail_client.sync_interval_minutes) has passed since its last attempt. Each account syncs on the
 * `mail-sync` queue. No-op when the feature is off.
 */
class SyncMailboxes extends Command
{
    protected $signature = 'mailbox:sync {--all : Ignore the interval and check every mailbox now}';

    protected $description = 'Dispatch inbound sync for the active, connected mailboxes that are due';

    public function handle(): int
    {
        if (! config('mail_client.enabled')) {
            return self::SUCCESS;
        }

        $default = max(1, (int) config('mail_client.sync_interval_minutes', 2));
        $force = (bool) $this->option('all');
        $count = 0;

        EmailAccount::query()
            ->where('is_active', true)
            ->where('needs_reauth', false)
            ->select(['id', 'sync_interval_minutes', 'last_sync_attempt_at'])
            ->chunkById(500, function ($accounts) use (&$count, $default, $force): void {
                foreach ($accounts as $account) {
                    if (! $force && ! $this->isDue($account, $default)) {
                        continue;
                    }

                    SyncEmailAccount::dispatch($account->id);
                    $count++;
                }
            });

        $this->info("Dispatched sync for {$count} mailbox(es).");

        return self::SUCCESS;
    }

    private function isDue(EmailAccount $account, int $defaultMinutes): bool
    {
        if ($account->last_sync_attempt_at === null) {
            return true;
        }

        $minutes = max(1, (int) ($account->sync_interval_minutes ?: $defaultMinutes));

        return $account->last_sync_attempt_at->lte(now()->subMinutes($minutes)->addSeconds(5));
    }
}
