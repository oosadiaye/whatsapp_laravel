<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LeadProfile;
use App\Services\Leads\Outreach\LeadMessageSender;
use App\Services\Leads\Outreach\LeadOutreachRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Drafts one profile's outreach, then sends whatever is approved. Takes the id so a deleted profile is a no-op. */
class RunLeadOutreachJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 280;

    public function __construct(public readonly int $profileId) {}

    public function handle(LeadOutreachRunner $runner, LeadMessageSender $sender): void
    {
        $profile = LeadProfile::find($this->profileId);

        if ($profile !== null) {
            $runner->run($profile);
        }

        $sender->sweep();
    }
}
