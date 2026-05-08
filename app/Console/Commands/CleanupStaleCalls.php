<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Calling\CallTerminated;
use App\Models\CallLog;
use Illuminate\Console\Command;

/**
 * Phase 17 stale-call sweeper. Catches the case where Meta's terminate
 * webhook never arrives — without this, a CallLog row would be stuck
 * in 'ringing' or 'connected' indefinitely and the agent's banner
 * would never dismiss.
 *
 * Threshold: 30 minutes from started_at. Generous enough that genuine
 * long calls (rare for WhatsApp) aren't stomped early. Configurable
 * via Setting in a future phase if usage data shows a need.
 *
 * Scheduled everyMinute() in routes/console.php so a stuck banner
 * dismisses within ~1 minute of when the terminate webhook should
 * have arrived.
 */
class CleanupStaleCalls extends Command
{
    protected $signature = 'calls:cleanup-stale';

    protected $description = 'Mark calls as stale if Meta terminate webhook never arrived (30-min threshold)';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(30);

        $stale = CallLog::query()
            ->whereIn('status', [CallLog::STATUS_RINGING, CallLog::STATUS_CONNECTED])
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stale as $call) {
            $newStatus = $call->status === CallLog::STATUS_RINGING
                ? CallLog::STATUS_MISSED
                : CallLog::STATUS_ENDED;

            $call->update([
                'status' => $newStatus,
                'ended_at' => now(),
                'failure_reason' => 'stale - no terminate webhook received',
            ]);

            CallTerminated::dispatch($call, 'stale_cleanup');
        }

        $this->info(sprintf('Cleaned up %d stale call(s).', $stale->count()));

        return self::SUCCESS;
    }
}
