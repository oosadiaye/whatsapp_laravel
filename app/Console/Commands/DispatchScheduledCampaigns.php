<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Console\Command;

class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-scheduled';
    protected $description = 'Launch campaigns that are queued and past their scheduled time';

    public function handle(): int
    {
        $campaigns = Campaign::where('status', 'QUEUED')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($campaigns->isEmpty()) {
            $this->info('No scheduled campaigns to dispatch.');
            return self::SUCCESS;
        }

        $service = new CampaignService();

        foreach ($campaigns as $campaign) {
            $service->launch($campaign);
            $this->info("Launched campaign: {$campaign->name} (ID: {$campaign->id})");
        }

        $this->info("Dispatched {$campaigns->count()} campaign(s).");
        return self::SUCCESS;
    }
}
