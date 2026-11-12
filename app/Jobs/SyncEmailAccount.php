<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\MailAuthException;
use App\Models\EmailAccount;
use App\Services\MailClient\EmailSyncService;
use App\Services\MailClient\MailAccountProviderFactory;
use App\Services\MailClient\MailErrorExplainer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sync one connected mailbox's inbound mail (plan B3).
 *
 * Retries WITH backoff — unlike the send jobs, a re-fetch is idempotent (dedup by
 * message_id), so a transient/network failure is safe and desirable to retry. An
 * AUTH failure is terminal: flag the account needs_reauth and stop (a retry can't
 * fix bad credentials).
 *
 * At most one waiting job per mailbox ({@see ShouldBeUniqueUntilProcessing}: a second dispatch while
 * one is queued is dropped, but the running job can still queue its own next batch) and never two
 * running at once ({@see WithoutOverlapping}), so the schedule, "check now" and the batch chain
 * cannot trip over each other. Every outcome is written to the account so the person can see it.
 */
class SyncEmailAccount implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Failures (not lock releases) allowed before the job gives up. Attempts are bounded by
     * {@see retryUntil()} instead of a count, because waiting for another run's lock also counts as an
     * attempt and must not burn the retries meant for real errors.
     */
    public int $maxExceptions = 3;

    /** @var list<int> per-attempt backoff seconds */
    public array $backoff = [30, 120, 300];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /** A stuck lock (worker killed while the job waited) frees itself after this many seconds. */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $accountId)
    {
        $this->onQueue('mail-sync');
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->accountId))->releaseAfter(60)->expireAfter(600)];
    }

    public function handle(EmailSyncService $service, MailAccountProviderFactory $factory): void
    {
        $account = EmailAccount::find($this->accountId);

        if ($account === null || ! $account->is_active || $account->needs_reauth) {
            return;
        }

        $fetcher = $factory->fetcherFor($account);
        if ($fetcher === null) {
            $this->recordFailure($account, 'This mailbox type is not supported for receiving mail.');

            return;
        }

        $account->forceFill(['last_sync_attempt_at' => now()])->save();

        try {
            $service->sync($account, $fetcher);

            // A big mailbox arrives in batches: queue the next one until the account is caught up.
            if (($account->refresh()->sync_state['backfilling'] ?? false) === true) {
                // A moment's delay so the next batch is not picked up while this run still holds the lock.
                self::dispatch($account->id)->delay(now()->addSeconds(3));
            }
        } catch (MailAuthException $e) {
            // Terminal — stop syncing this account until the user reconnects.
            $account->update(['needs_reauth' => true]);
            $this->recordFailure($account, MailErrorExplainer::explain($e, $this->host($account)));
            Log::warning('Mailbox needs reconnect (auth failed)', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
            // Deliberately not rethrown — a retry can't fix bad credentials.
        } catch (\Throwable $e) {
            // Show the person why it is not updating, then let the queue retry with backoff.
            $this->recordFailure($account, MailErrorExplainer::explain($e, $this->host($account)));
            Log::warning('Mailbox sync failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    private function recordFailure(EmailAccount $account, string $message): void
    {
        $account->forceFill(['last_sync_error' => mb_substr($message, 0, 500)])->save();
    }

    private function host(EmailAccount $account): ?string
    {
        $host = ($account->credentials ?? [])['imap_host'] ?? null;

        return is_string($host) ? $host : null;
    }
}
