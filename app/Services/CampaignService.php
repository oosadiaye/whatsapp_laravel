<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CampaignBatchDispatch;
use App\Models\Campaign;
use Illuminate\Support\Carbon;

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
     * Cancel a campaign.
     */
    public function cancel(Campaign $campaign): void
    {
        $campaign->update(['status' => 'CANCELLED']);
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
