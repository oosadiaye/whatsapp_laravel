<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CampaignBatchDispatch;
use App\Models\Campaign;
use App\Models\MessageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manages campaign lifecycle operations: launch, pause, resume, cancel, clone, and completion.
 */
class CampaignService
{
    /**
     * Launch a campaign by setting it to QUEUED and dispatching the batch job.
     */
    public function launch(Campaign $campaign): void
    {
        $campaign->update([
            'status' => 'QUEUED',
            'started_at' => Carbon::now(),
        ]);

        CampaignBatchDispatch::dispatch($campaign);
    }

    /**
     * Queue a campaign for DEFERRED dispatch at its scheduled_at time.
     *
     * Unlike launch() this does NOT dispatch the batch job or set started_at —
     * it just marks the campaign QUEUED. The campaigns:dispatch-scheduled cron
     * selects QUEUED campaigns whose scheduled_at has passed and calls launch()
     * then. (Immediately-launched campaigns have scheduled_at = null, which the
     * cron's `scheduled_at <= now` predicate never matches, so they are not
     * double-dispatched.)
     */
    public function schedule(Campaign $campaign): void
    {
        $campaign->update(['status' => 'QUEUED']);
    }

    /**
     * Pause a running campaign.
     */
    public function pause(Campaign $campaign): void
    {
        $campaign->update(['status' => 'PAUSED']);
    }

    /**
     * Resume a paused campaign.
     */
    public function resume(Campaign $campaign): void
    {
        $campaign->update(['status' => 'RUNNING']);
    }

    /**
     * Cancel a campaign and clean up its queue footprint.
     *
     * Just flipping status to CANCELLED isn't enough — there can be:
     *   - PENDING MessageLog rows (created by CampaignBatchDispatch but
     *     not yet picked up by a SendWhatsAppMessage job)
     *   - SendWhatsAppMessage jobs already in the queue table waiting for
     *     a worker, which would still fire and try to send if the worker
     *     comes online later
     *
     * We mark the PENDING logs as 'CANCELLED' so SendWhatsAppMessage's own
     * status guard (it refuses to send when log->status !== 'PENDING') skips
     * them when they run. Already-queued jobs are left to self-abort via that
     * guard rather than purged from the queue.
     *
     * Returns the count of pending logs that were cancelled, useful for
     * the controller's flash message ("Cancelled. 7 pending sends aborted.").
     */
    public function cancel(Campaign $campaign): int
    {
        $campaign->update([
            'status' => 'CANCELLED',
            'completed_at' => Carbon::now(),
        ]);

        // Mark every still-PENDING MessageLog as CANCELLED. Any already-queued
        // SendWhatsAppMessage job re-reads its log in handle() and bails because
        // the status is no longer PENDING — so no queue purge is needed.
        $cancelledLogs = MessageLog::where('campaign_id', $campaign->id)
            ->where('status', 'PENDING')
            ->update([
                'status' => 'CANCELLED',
                'error_message' => 'Campaign cancelled before send',
            ]);

        // NOTE: queued/delayed jobs are intentionally NOT purged from the queue
        // here. The previous payload-LIKE delete matched the serialized
        // ModelIdentifier shape unreliably (it keys on `id`, not `campaign_id`)
        // and is irrelevant on Redis/Horizon anyway, so it was removed rather
        // than left as a false safety net. Correctness is the log-status guard:
        // the jobs run, see a non-PENDING log, and abort.

        return $cancelledLogs;
    }

    /**
     * Clone a campaign as a new DRAFT with zeroed-out counters.
     */
    public function clone(Campaign $campaign): Campaign
    {
        $cloned = $campaign->replicate();
        $cloned->status = 'DRAFT';
        $cloned->started_at = null;
        $cloned->completed_at = null;
        $cloned->sent_count = 0;
        $cloned->delivered_count = 0;
        $cloned->read_count = 0;
        $cloned->failed_count = 0;
        $cloned->total_contacts = 0;
        $cloned->save();

        $groupIds = $campaign->contactGroups()->pluck('contact_groups.id');
        $cloned->contactGroups()->attach($groupIds);

        return $cloned;
    }

    /**
     * Check whether all contacts have been processed and mark the campaign as completed.
     */
    public function checkCompletion(Campaign $campaign): void
    {
        $campaign->refresh();

        $processed = $campaign->sent_count + $campaign->failed_count;

        if ($processed >= $campaign->total_contacts && $campaign->total_contacts > 0) {
            $campaign->update([
                'status' => 'COMPLETED',
                'completed_at' => Carbon::now(),
            ]);
        }
    }
}
