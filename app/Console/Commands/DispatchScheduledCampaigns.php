<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CampaignBatchDispatch;
use App\Models\Campaign;
use Illuminate\Console\Command;

class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-scheduled';

    protected $description = 'Launch campaigns that are queued and past their scheduled time';

    public function handle(): int
    {
        // Only campaigns not yet launched (started_at IS NULL). launch() /the
        // claim below set started_at, so once dispatched a campaign drops out of
        // this selection and the next minute's tick won't re-dispatch it.
        $dueIds = Campaign::query()
            ->where('status', 'QUEUED')
            ->whereNull('started_at')
            ->where('scheduled_at', '<=', now())
            ->pluck('id');

        if ($dueIds->isEmpty()) {
            $this->info('No scheduled campaigns to dispatch.');

            return self::SUCCESS;
        }

        $launched = 0;

        foreach ($dueIds as $id) {
            // Atomically claim each campaign before dispatching. Without this a
            // batch job stuck in a backlogged queue past the next minute's tick
            // (started_at still null because the job hasn't run yet) — or a second
            // scheduler host — would re-dispatch it, and concurrent batch jobs
            // double-send the whole audience. Only the row-claimer dispatches.
            $claimed = Campaign::query()
                ->whereKey($id)
                ->where('status', 'QUEUED')
                ->whereNull('started_at')
                ->update(['started_at' => now()]);

            if ($claimed === 0) {
                continue; // already claimed by a re-tick / another host
            }

            $campaign = Campaign::find($id);
            if ($campaign === null) {
                continue;
            }

            CampaignBatchDispatch::dispatch($campaign);
            $this->info("Launched campaign: {$campaign->name} (ID: {$campaign->id})");
            $launched++;
        }

        $this->info("Dispatched {$launched} campaign(s).");

        return self::SUCCESS;
    }
}
