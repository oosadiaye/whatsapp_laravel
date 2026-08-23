<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Calling\CallTerminated;
use App\Jobs\TerminateProviderCall;
use App\Models\CallLog;
use Illuminate\Console\Command;

/**
 * Stale-call sweeper. Catches the case where a provider's terminate webhook
 * never arrives — without this, a CallLog row would be stuck in-flight
 * indefinitely and the agent's banner would never dismiss.
 *
 * Sweeps every in-flight status (initiated / ringing / connected). INITIATED
 * matters specifically for outbound Africa's Talking calls: they are created
 * INITIATED and only advance when a status webhook arrives, so a webhook-secret
 * mismatch (or a call that only ever fires call-control) would otherwise strand
 * them forever — and if the customer leg connected, keep it billing.
 *
 * Threshold: 30 minutes from started_at. Scheduled everyMinute() in
 * routes/console.php so a stuck banner dismisses within ~1 minute of when the
 * terminate webhook should have arrived.
 */
class CleanupStaleCalls extends Command
{
    protected $signature = 'calls:cleanup-stale';

    protected $description = 'Terminalize calls stuck in-flight because the provider terminate webhook never arrived (30-min threshold)';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(30);

        $stale = CallLog::query()
            ->whereIn('status', CallLog::STATUSES_IN_FLIGHT)
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stale as $call) {
            // Answered (connected) → ended; never answered (initiated/ringing) → missed.
            $newStatus = $call->status === CallLog::STATUS_CONNECTED
                ? CallLog::STATUS_ENDED
                : CallLog::STATUS_MISSED;

            $call->update([
                'status' => $newStatus,
                'ended_at' => now(),
                'failure_reason' => 'stale - no terminate webhook received',
            ]);

            // A lost terminate webhook is exactly what stranded this row, so the
            // provider leg may still be live (and billing). Ask the provider to
            // hang up — the retried job no-ops if the leg is already gone.
            if (filled($call->provider_session_id) || filled($call->meta_call_id)) {
                TerminateProviderCall::dispatch($call->id);
            }

            CallTerminated::dispatch($call, 'stale_cleanup');
        }

        $this->info(sprintf('Cleaned up %d stale call(s).', $stale->count()));

        return self::SUCCESS;
    }
}
