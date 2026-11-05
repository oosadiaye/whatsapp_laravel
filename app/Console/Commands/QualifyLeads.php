<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\QualifyLeadProfileJob;
use App\Models\Lead;
use App\Models\LeadProfile;
use App\Support\LeadConfig;
use Illuminate\Console\Command;

class QualifyLeads extends Command
{
    protected $signature = 'leads:qualify {--profile= : Only this lead profile id}';

    protected $description = 'Queue AI qualification and email reveal for every active lead profile that has work.';

    private const MAX_ATTEMPTS = 3;

    public function handle(): int
    {
        if (LeadConfig::killSwitchOn()) {
            $this->warn('Lead kill switch is ON; nothing queued.');

            return self::SUCCESS;
        }

        $profiles = LeadProfile::query()
            ->active()
            ->whereNotNull('lead_query')
            ->when($this->option('profile'), fn ($query, $id) => $query->whereKey($id))
            ->whereHas('leads', fn ($leads) => $leads->where(function ($work) {
                $work->where(fn ($new) => $new->where('status', Lead::STATUS_NEW)->where('qualify_attempts', '<', self::MAX_ATTEMPTS))
                    ->orWhere(fn ($waiting) => $waiting->where('status', Lead::STATUS_QUALIFIED)->where('email_status', Lead::EMAIL_UNREVEALED));
            }))
            ->get();

        foreach ($profiles as $profile) {
            QualifyLeadProfileJob::dispatch($profile->id);
        }

        $this->info("Queued {$profiles->count()} qualification run(s).");

        return self::SUCCESS;
    }
}
