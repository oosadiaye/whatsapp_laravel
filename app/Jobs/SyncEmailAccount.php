<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\MailAuthException;
use App\Models\EmailAccount;
use App\Services\MailClient\EmailSyncService;
use App\Services\MailClient\MailAccountProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sync one connected mailbox's inbound mail (plan B3).
 *
 * Retries WITH backoff — unlike the send jobs, a re-fetch is idempotent (dedup by
 * message_id), so a transient/network failure is safe and desirable to retry. An
 * AUTH failure is terminal: flag the account needs_reauth and stop (a retry can't
 * fix bad credentials).
 */
class SyncEmailAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> per-attempt backoff seconds */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $accountId)
    {
        $this->onQueue('mail-sync');
    }

    public function handle(EmailSyncService $service, MailAccountProviderFactory $factory): void
    {
        $account = EmailAccount::find($this->accountId);

        if ($account === null || ! $account->is_active || $account->needs_reauth) {
            return;
        }

        $fetcher = $factory->fetcherFor($account);
        if ($fetcher === null) {
            return;
        }

        try {
            $service->sync($account, $fetcher);
        } catch (MailAuthException $e) {
            // Terminal — stop syncing this account until the user reconnects.
            $account->update(['needs_reauth' => true]);
            Log::warning('Mailbox needs reconnect (auth failed)', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
            // Deliberately not rethrown — a retry can't fix bad credentials.
        }
        // Any other exception propagates → Horizon retries with backoff.
    }
}
