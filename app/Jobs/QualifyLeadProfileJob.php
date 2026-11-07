<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LeadProfile;
use App\Services\Leads\LeadQualificationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Qualifies (and reveals) one profile's leads. Takes the id so a deleted profile is a no-op. */
class QualifyLeadProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // A batch of AI calls plus reveals can take a while; the runner's own lock is 600 s.
    public int $timeout = 540;

    public function __construct(public readonly int $profileId) {}

    public function handle(LeadQualificationRunner $runner): void
    {
        $profile = LeadProfile::find($this->profileId);

        if ($profile === null) {
            return;
        }

        $runner->run($profile);
    }
}
