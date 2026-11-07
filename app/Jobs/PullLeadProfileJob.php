<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LeadProfile;
use App\Services\Leads\LeadPuller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Pulls one page per source for one profile. Takes the id so a deleted profile is a no-op. */
class PullLeadProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $profileId) {}

    public function handle(LeadPuller $puller): void
    {
        $profile = LeadProfile::find($this->profileId);

        if ($profile === null) {
            return;
        }

        $puller->pull($profile);
    }
}
