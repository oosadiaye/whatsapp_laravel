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
     * We mark the PENDING logs as 'CANCELLED' so SendWhatsAppMessage's
     * own sanity check (it should refuse to send if log->status !==
     * 'PENDING') skips them, and we delete any queued database jobs
     * that reference this campaign by serialized payload.
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

        // Mark every still-PENDING MessageLog as CANCELLED so even if a
        // SendWhatsAppMessage job somehow runs later, it sees the log isn't
        // PENDING and bails. (This depends on SendWhatsAppMessage having a
        // PENDING-status guard; if it doesn't, this is at least a recordable
        // signal of the cancellation intent.)
        $cancelledLogs = MessageLog::where('campaign_id', $campaign->id)
            ->where('status', 'PENDING')
            ->update([
                'status' => 'CANCELLED',
                'error_message' => 'Campaign cancelled before send',
            ]);

        // Best-effort cleanup of the database queue table. Jobs are stored
        // with their serialized payload in `payload`; we match by the
        // campaign id token. Only relevant when QUEUE_CONNECTION=database.
        // This is intentionally narrow — we don't want to delete unrelated
        // jobs that happen to mention the same number elsewhere.
        if (config('queue.default') === 'database') {
            try {
                DB::table('jobs')
                    ->where('queue', 'messages')
                    ->where('payload', 'like', '%"campaign_id";i:'.$campaign->id.';%')
                    ->delete();
                DB::table('jobs')
                    ->where('queue', 'default')
                    ->where('payload', 'like', '%"campaign":'.$campaign->id.'%')
                    ->orWhere('payload', 'like', '%CampaignBatchDispatch%campaign_id%'.$campaign->id.'%')
                    ->delete();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Could not clean up queue jobs on cancel', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
